<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Db\AccessGrantMapper;
use OCA\ShareGate\Util\BuyerAccount;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\IL10N;

class BuyerRecoveryService {
	public function __construct(
		private PaymentService $paymentService,
		private AccessGrantMapper $accessGrantMapper,
		private BuyerAccessTokenService $accessTokenService,
		private BuyerPurchasesTokenService $purchasesTokenService,
		private IL10N $l,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function recoverShareAccess(string $shareId, string $payerIdRaw): array {
		$shareId = trim($shareId);
		$payerId = BuyerAccount::normalizePayerId($payerIdRaw);
		if ($shareId === '' || $payerId === null) {
			return [
				'success' => false,
				'error' => $this->l->t('Invalid share or payment account'),
			];
		}

		if (!$this->paymentService->hasUserPaid($shareId, $payerId)) {
			return [
				'success' => false,
				'error' => $this->l->t('No active purchase found for this payment account on this file'),
			];
		}

		try {
			$grantHolderId = $this->accessGrantMapper->resolveActiveGrantHolderId($shareId, $payerId) ?? $payerId;
			$grant = $this->accessGrantMapper->findActive($shareId, $grantHolderId);
			$expiresAt = (int)($grant->getExpiresAt() ?? 0);
		} catch (DoesNotExistException|Exception) {
			return [
				'success' => false,
				'error' => $this->l->t('No active purchase found for this payment account on this file'),
			];
		}

		if ($expiresAt <= (int)(microtime(true) * 1000)) {
			return [
				'success' => false,
				'error' => $this->l->t('Download access has expired'),
			];
		}

		$token = $this->accessTokenService->create($shareId, $grantHolderId, $expiresAt);
		if ($token === '') {
			return ['success' => false, 'error' => $this->l->t('Failed to issue access token')];
		}

		return array_merge([
			'success' => true,
			'payer_user_id' => $grantHolderId,
			'access_token' => $token,
			'cross_device_url' => $this->accessTokenService->buildCrossDeviceViewUrl($shareId, $token),
		], $this->issuePurchasesTokenForPayer($grantHolderId));
	}

	/**
	 * @return array{purchases_token: string, purchases_url: string}|array{}
	 */
	private function issuePurchasesTokenForPayer(string $payerId): array {
		$token = $this->purchasesTokenService->createForPayer($payerId);
		if ($token === '') {
			return [];
		}
		return [
			'purchases_token' => $token,
			'purchases_url' => $this->purchasesTokenService->buildPurchasesPageUrl($token),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function verifyPayerAccount(string $payerIdRaw, ?string $existingPurchasesToken = null): array {
		if (BuyerAccount::isBrowserSessionId($payerIdRaw)) {
			return [
				'success' => true,
				'found' => false,
				'payer_user_id' => trim($payerIdRaw),
			];
		}

		$payerId = BuyerAccount::normalizePayerId($payerIdRaw);
		if ($payerId === null) {
			return [
				'success' => false,
				'error' => $this->l->t('Invalid payment account'),
			];
		}

		try {
			$grantHolderIds = $this->accessGrantMapper->findActiveGrantHolderIdsForPayerInput($payerId);
		} catch (Exception $e) {
			return [
				'success' => false,
				'error' => $this->l->t('Recovery failed: %s', [$e->getMessage()]),
			];
		}

		if ($grantHolderIds === []) {
			return [
				'success' => true,
				'found' => false,
				'payer_user_id' => $payerId,
			];
		}

		$token = '';
		$existingPurchasesToken = $existingPurchasesToken !== null ? trim($existingPurchasesToken) : '';
		if ($existingPurchasesToken !== '') {
			$token = $this->purchasesTokenService->mergePayer($existingPurchasesToken, $payerId) ?? '';
		}
		if ($token === '') {
			$token = $this->purchasesTokenService->create($grantHolderIds);
		}
		if ($token === '') {
			return [
				'success' => false,
				'error' => $this->l->t('Failed to issue purchases session'),
			];
		}

		return [
			'success' => true,
			'found' => true,
			'payer_user_id' => $payerId,
			'purchases_token' => $token,
			'purchases_url' => $this->purchasesTokenService->buildPurchasesPageUrl($token),
		];
	}

	/**
	 * @return array{purchases_token: string, purchases_url: string}|null
	 */
	public function purchasesTokenForPayer(string $payerIdRaw, ?string $existingPurchasesToken = null): ?array {
		$result = $this->verifyPayerAccount($payerIdRaw, $existingPurchasesToken);
		if (!($result['success'] ?? false) || !($result['found'] ?? false)) {
			return null;
		}
		$token = (string)($result['purchases_token'] ?? '');
		if ($token === '') {
			return null;
		}
		return [
			'purchases_token' => $token,
			'purchases_url' => (string)($result['purchases_url'] ?? $this->purchasesTokenService->buildPurchasesPageUrl($token)),
		];
	}
}
