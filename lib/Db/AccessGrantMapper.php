<?php

declare(strict_types=1);

namespace OCA\ShareGate\Db;

use OCA\ShareGate\Util\BuyerAccount;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\IDBConnection;

/** @extends QBMapper<AccessGrant> */
class AccessGrantMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sharegate_access_grants', AccessGrant::class);
	}

	/**
	 * @throws Exception
	 */
	public function hasActiveGrant(string $shareId, string $providerUserId): bool {
		$now = (int)(microtime(true) * 1000);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->andWhere($qb->expr()->eq('provider_user_id', $qb->createNamedParameter($providerUserId)))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		return $row !== false;
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function findActive(string $shareId, string $providerUserId): AccessGrant {
		$now = (int)(microtime(true) * 1000);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->andWhere($qb->expr()->eq('provider_user_id', $qb->createNamedParameter($providerUserId)))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		return $this->findEntity($qb);
	}

	/**
	 * @return AccessGrant[]
	 * @throws Exception
	 */
	public function findByProviderUserId(string $providerUserId, int $limit = 100): array {
		return $this->findByProviderUserIds([$providerUserId], $limit);
	}

	/**
	 * @param list<string> $providerUserIds
	 * @return AccessGrant[]
	 * @throws Exception
	 */
	public function findByProviderUserIds(array $providerUserIds, int $limit = 100): array {
		$providerUserIds = array_values(array_filter(array_map('strval', $providerUserIds)));
		if ($providerUserIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in(
				'provider_user_id',
				$qb->createNamedParameter($providerUserIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
			))
			->orderBy('created_at', 'DESC')
			->setMaxResults(max(1, min(200, $limit)));
		return $this->findEntities($qb);
	}

	/**
	 * @throws Exception
	 */
	public function countActiveByProviderUserId(string $providerUserId): int {
		$now = (int)(microtime(true) * 1000);
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('provider_user_id', $qb->createNamedParameter($providerUserId)))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	/**
	 * Exact grant or Alipay masked-logon match (user enters full sandbox email).
	 *
	 * @throws Exception
	 */
	public function hasActiveGrantForPayer(string $shareId, string $payerInput): bool {
		return $this->resolveActiveGrantHolderId($shareId, $payerInput) !== null;
	}

	/**
	 * @throws Exception
	 */
	public function resolveActiveGrantHolderId(string $shareId, string $payerInput): ?string {
		$payerInput = trim($payerInput);
		if ($payerInput === '') {
			return null;
		}
		if ($this->hasActiveGrant($shareId, $payerInput)) {
			return $payerInput;
		}
		foreach ($this->findActiveMaskedAlipayLogonIds($shareId) as $masked) {
			if (\OCA\ShareGate\Util\BuyerAccount::matchesMaskedAlipayLogon($masked, $payerInput)) {
				return $masked;
			}
		}
		return null;
	}

	/**
	 * @throws Exception
	 */
	public function countActiveForPayer(string $payerInput): int {
		$count = $this->countActiveByProviderUserId($payerInput);
		if ($count > 0) {
			return $count;
		}
		return $this->countActiveByMaskedAlipayLogon($payerInput);
	}

	/**
	 * @throws Exception
	 */
	private function countActiveByMaskedAlipayLogon(string $fullLogon): int {
		$count = 0;
		foreach ($this->findActiveMaskedAlipayLogonIds(null) as $masked) {
			if (\OCA\ShareGate\Util\BuyerAccount::matchesMaskedAlipayLogon($masked, $fullLogon)) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * @return list<string>
	 * @throws Exception
	 */
	private function findActiveMaskedAlipayLogonIds(?string $shareId): array {
		$now = (int)(microtime(true) * 1000);
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('provider_user_id')
			->from($this->getTableName())
			->where($qb->expr()->like('provider_user_id', $qb->createNamedParameter('%***%')))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now)));
		if ($shareId !== null && $shareId !== '') {
			$qb->andWhere($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)));
		}
		$cursor = $qb->executeQuery();
		$ids = [];
		while ($row = $cursor->fetch()) {
			$id = trim((string)($row['provider_user_id'] ?? ''));
			if ($id !== '') {
				$ids[] = $id;
			}
		}
		$cursor->closeCursor();
		return $ids;
	}

	/**
	 * All active grant holder ids matching payer input (exact, masked Alipay logon, linked UID).
	 *
	 * @return list<string>
	 * @throws Exception
	 */
	public function findActiveGrantHolderIdsForPayerInput(string $payerInput): array {
		$payerInput = trim($payerInput);
		if ($payerInput === '') {
			return [];
		}

		/** @var list<string> */
		$ids = [];
		if ($this->countActiveByProviderUserId($payerInput) > 0) {
			$this->appendUniqueHolderId($ids, $payerInput);
		}
		foreach ($this->findActiveMaskedAlipayLogonIds(null) as $masked) {
			if (\OCA\ShareGate\Util\BuyerAccount::matchesMaskedAlipayLogon($masked, $payerInput)) {
				$this->appendUniqueHolderId($ids, $masked);
			}
		}

		$now = (int)(microtime(true) * 1000);
		$maskedHolders = array_values(array_filter(
			$ids,
			static fn (string $id): bool => \OCA\ShareGate\Util\BuyerAccount::isMaskedAlipayLogon($id),
		));
		foreach ($maskedHolders as $holderId) {
			try {
				$grants = $this->findByProviderUserId($holderId, 200);
			} catch (Exception) {
				continue;
			}
			foreach ($grants as $grant) {
				if (($grant->getExpiresAt() ?? 0) <= $now) {
					continue;
				}
				$paymentId = (int)$grant->getPaymentId();
				if ($paymentId <= 0) {
					continue;
				}
				try {
					$siblings = $this->findByPaymentId($paymentId);
				} catch (Exception) {
					continue;
				}
				foreach ($siblings as $sibling) {
					$siblingId = trim($sibling->getProviderUserId());
					if ($siblingId !== ''
						&& \OCA\ShareGate\Util\BuyerAccount::isAlipayUid($siblingId)
						&& ($sibling->getExpiresAt() ?? 0) > $now) {
						$this->appendUniqueHolderId($ids, $siblingId);
					}
				}
			}
		}

		return $ids;
	}

	/**
	 * @param list<string> $ids
	 */
	private function appendUniqueHolderId(array &$ids, string $id): void {
		$id = trim($id);
		if ($id === '' || in_array($id, $ids, true)) {
			return;
		}
		$ids[] = $id;
	}

	/**
	 * @throws Exception
	 */
	public function deleteById(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function updateEntityGrant(AccessGrant $grant): AccessGrant {
		return $this->update($grant);
	}

	/**
	 * Grants for seller ledger: linked to payment_id, plus unlinked rows on the same share near pay time.
	 *
	 * @return AccessGrant[]
	 * @throws Exception
	 */
	public function findLedgerGrantsForPayment(Payment $payment): array {
		$paymentId = (int)$payment->getId();
		if ($paymentId <= 0) {
			return [];
		}

		$grants = $this->findByPaymentId($paymentId);
		if ($payment->getStatus() !== 'paid') {
			return $grants;
		}

		$seen = [];
		$merged = [];
		foreach ($grants as $grant) {
			$holderId = trim($grant->getProviderUserId());
			if ($holderId === '' || isset($seen[$holderId])) {
				continue;
			}
			$seen[$holderId] = true;
			$merged[] = $grant;
		}

		foreach ($this->findOrphanGrantsNearPayment($payment) as $grant) {
			$holderId = trim($grant->getProviderUserId());
			if ($holderId === '' || isset($seen[$holderId])) {
				continue;
			}
			$seen[$holderId] = true;
			$merged[] = $grant;
		}

		if (!BuyerAccount::resolveMaskedAlipayLogon(array_map(
			static fn (AccessGrant $grant) => $grant->getProviderUserId(),
			$merged,
		))) {
			foreach ($this->findMaskedAlipayGrantsNearPayment($payment) as $grant) {
				$holderId = trim($grant->getProviderUserId());
				if ($holderId === '' || isset($seen[$holderId])) {
					continue;
				}
				$seen[$holderId] = true;
				$merged[] = $grant;
			}
		}

		return $merged;
	}

	/**
	 * @return AccessGrant[]
	 * @throws Exception
	 */
	private function findMaskedAlipayGrantsNearPayment(Payment $payment): array {
		$shareId = trim($payment->getShareId());
		if ($shareId === '') {
			return [];
		}
		$anchor = $payment->getPaidAt() ?? $payment->getCreatedAt();
		$windowStart = max(0, $anchor - 300_000);
		$windowEnd = $anchor + 600_000;
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->andWhere($qb->expr()->like('provider_user_id', $qb->createNamedParameter('%***%')))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($windowStart, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($windowEnd, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * @return AccessGrant[]
	 * @throws Exception
	 */
	private function findOrphanGrantsNearPayment(Payment $payment): array {
		$shareId = trim($payment->getShareId());
		if ($shareId === '') {
			return [];
		}
		$anchor = $payment->getPaidAt() ?? $payment->getCreatedAt();
		$windowStart = max(0, $anchor - 300_000);
		$windowEnd = $anchor + 600_000;
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->andWhere($qb->expr()->eq('payment_id', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($windowStart, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($windowEnd, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Link grants created before payment_id tracking (payment_id = 0) to the paid order.
	 *
	 * @throws Exception
	 */
	public function linkOrphanGrantsForPayment(Payment $payment): void {
		$paymentId = (int)$payment->getId();
		if ($paymentId <= 0 || $payment->getStatus() !== 'paid') {
			return;
		}
		$shareId = trim($payment->getShareId());
		if ($shareId === '') {
			return;
		}
		$anchor = $payment->getPaidAt() ?? $payment->getCreatedAt();
		$windowStart = max(0, $anchor - 300_000);
		$windowEnd = $anchor + 600_000;
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->andWhere($qb->expr()->eq('payment_id', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($windowStart, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($windowEnd, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$grants = $this->findEntities($qb);
		foreach ($grants as $grant) {
			$grant->setPaymentId($paymentId);
			$this->updateEntityGrant($grant);
		}
	}

	/**
	 * @return AccessGrant[]
	 * @throws Exception
	 */
	public function findByPaymentId(int $paymentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('payment_id', $qb->createNamedParameter($paymentId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $paymentIds
	 * @return AccessGrant[]
	 * @throws Exception
	 */
	public function findByPaymentIds(array $paymentIds): array {
		$paymentIds = array_values(array_unique(array_filter(array_map('intval', $paymentIds))));
		if ($paymentIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in(
				'payment_id',
				$qb->createNamedParameter($paymentIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY),
			))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}
}
