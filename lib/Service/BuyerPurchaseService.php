<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Db\AccessGrant;
use OCA\ShareGate\Db\AccessGrantMapper;
use OCA\ShareGate\Db\PaymentMapper;
use OCA\ShareGate\Db\ShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Buyer purchase history keyed by payment provider account (after pay).
 */
class BuyerPurchaseService {
	public function __construct(
		private AccessGrantMapper $accessGrantMapper,
		private PaymentMapper $paymentMapper,
		private ShareMapper $shareMapper,
		private DownloadService $downloadService,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	/**
	 * @return array{success: true, merged: int}|array{success: false, error: string}
	 */
	public function linkAnonymousPurchases(string $ncUserId, string $anonymousId): array {
		$anonymousId = trim($anonymousId);
		if ($ncUserId === '' || $anonymousId === '' || $ncUserId === $anonymousId) {
			return ['success' => true, 'merged' => 0];
		}
		if (!str_starts_with($anonymousId, 'buyer_')) {
			return ['success' => false, 'error' => $this->l->t('Invalid anonymous buyer id')];
		}

		try {
			$merged = $this->mergeGrants($ncUserId, $anonymousId);
			$this->paymentMapper->reassignClientUserId($anonymousId, $ncUserId);
			return ['success' => true, 'merged' => $merged];
		} catch (Exception $e) {
			return ['success' => false, 'error' => $this->l->t('Failed to link purchases: %s', [$e->getMessage()])];
		}
	}

	/**
	 * @return array{success: true, items: list<array<string, mixed>>, total: int}|array{success: false, error: string}
	 */
	public function listPurchases(string $buyerAccountId, int $limit = 100): array {
		return $this->listPurchasesForPayers([$buyerAccountId], $limit);
	}

	/**
	 * @param list<string> $payerIds
	 * @return array{success: true, items: list<array<string, mixed>>, total: int}|array{success: false, error: string}
	 */
	public function listPurchasesForPayers(array $payerIds, int $limit = 100): array {
		$payerIds = array_values(array_filter(array_map('trim', $payerIds)));
		if ($payerIds === []) {
			return ['success' => true, 'items' => [], 'total' => 0];
		}

		try {
			/** @var list<string> */
			$lookupIds = [];
			foreach ($payerIds as $payerId) {
				foreach ($this->accessGrantMapper->findActiveGrantHolderIdsForPayerInput($payerId) as $holderId) {
					if (!in_array($holderId, $lookupIds, true)) {
						$lookupIds[] = $holderId;
					}
				}
				if ($payerId !== '' && !in_array($payerId, $lookupIds, true)) {
					$lookupIds[] = $payerId;
				}
			}
			if ($lookupIds === []) {
				return ['success' => true, 'items' => [], 'total' => 0];
			}
			$grants = $this->accessGrantMapper->findByProviderUserIds($lookupIds, $limit);
		} catch (Exception $e) {
			return ['success' => false, 'error' => $this->l->t('Failed to load purchases: %s', [$e->getMessage()])];
		}

		return $this->buildPurchaseItemsFromGrants($grants, $payerIds[0]);
	}

	/**
	 * @param AccessGrant[] $grants
	 * @return array{success: true, items: list<array<string, mixed>>, total: int}
	 */
	private function buildPurchaseItemsFromGrants(array $grants, string $defaultBuyerAccountId): array {
		$now = (int)(microtime(true) * 1000);
		$items = [];
		$seenShareIds = [];

		foreach ($grants as $grant) {
			$shareId = $grant->getShareId();
			if (isset($seenShareIds[$shareId])) {
				continue;
			}
			$seenShareIds[$shareId] = true;
			$buyerAccountId = $grant->getProviderUserId() ?: $defaultBuyerAccountId;

			try {
				$share = $this->shareMapper->findByShareId($shareId);
			} catch (DoesNotExistException) {
				$items[] = $this->buildItem(
					$grant,
					null,
					'unavailable',
					$now,
					$buyerAccountId,
				);
				continue;
			}

			$accessActive = $grant->getExpiresAt() !== null && $grant->getExpiresAt() > $now;
			$shareActive = $share->getStatus() === 'active' && !$this->isShareLinkExpired($share, $now);
			$fileAvailable = $this->downloadService->tryResolveShareFile($share) !== null;

			if (!$accessActive) {
				$status = 'expired';
			} elseif (!$shareActive || !$fileAvailable) {
				$status = 'unavailable';
			} else {
				$status = 'active';
			}

			$items[] = $this->buildItem($grant, $share, $status, $now, $buyerAccountId);
		}

		return [
			'success' => true,
			'items' => $items,
			'total' => count($items),
		];
	}

	public function countActivePurchases(string $buyerAccountId): int {
		if ($buyerAccountId === '') {
			return 0;
		}
		try {
			return $this->accessGrantMapper->countActiveByProviderUserId($buyerAccountId);
		} catch (Exception) {
			return 0;
		}
	}

	/**
	 * @throws Exception
	 */
	private function mergeGrants(string $ncUserId, string $anonymousId): int {
		$anonymousGrants = $this->accessGrantMapper->findByProviderUserId($anonymousId, 200);
		$merged = 0;

		foreach ($anonymousGrants as $grant) {
			$shareId = $grant->getShareId();
			try {
				$existing = $this->accessGrantMapper->findActive($shareId, $ncUserId);
				$anonExpires = $grant->getExpiresAt() ?? 0;
				$existExpires = $existing->getExpiresAt() ?? 0;
				if ($anonExpires > $existExpires) {
					$existing->setExpiresAt($anonExpires);
					$this->accessGrantMapper->updateEntityGrant($existing);
				}
				$this->accessGrantMapper->deleteById((int)$grant->getId());
				$merged++;
			} catch (DoesNotExistException) {
				$grant->setProviderUserId($ncUserId);
				$this->accessGrantMapper->updateEntityGrant($grant);
				$merged++;
			}
		}

		return $merged;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildItem(
		AccessGrant $grant,
		?\OCA\ShareGate\Db\Share $share,
		string $status,
		int $now,
		string $buyerAccountId,
	): array {
		$shareId = $grant->getShareId();
		$viewerUrl = $this->urlGenerator->linkToRoute(
			'sharegate.share.view',
			['shareId' => $shareId],
		);

		$downloadUrl = null;
		if ($status === 'active') {
			$verify = $this->downloadService->verifyDownload($shareId, $buyerAccountId);
			if ($verify['success'] ?? false) {
				$downloadUrl = $verify['download_url'] ?? null;
			}
		}

		$saveToCloudUrl = $this->urlGenerator->linkToRoute(
			'sharegate.share.saveToCloud',
			['shareId' => $shareId],
		);

		return [
			'share_id' => $shareId,
			'title' => $share !== null ? $share->getTitle() : $shareId,
			'file_name' => $share !== null ? $share->getFileName() : '',
			'file_size' => $share !== null ? $share->getFileSize() : 0,
			'paid_at' => $grant->getCreatedAt(),
			'expires_at' => $grant->getExpiresAt(),
			'status' => $status,
			'viewer_url' => $viewerUrl,
			'download_url' => $downloadUrl,
			'save_to_cloud_url' => $saveToCloudUrl,
			'provider_user_id' => $buyerAccountId,
		];
	}

	private function isShareLinkExpired(\OCA\ShareGate\Db\Share $share, int $nowMs): bool {
		$expireAt = $share->getExpireAt();
		return $expireAt !== null && $expireAt < $nowMs;
	}
}
