<?php

declare(strict_types=1);

namespace OCA\ShareGate\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Payment> */
class PaymentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sharegate_payments', Payment::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function findByOrderId(string $orderId): Payment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('order_id', $qb->createNamedParameter($orderId)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws Exception
	 */
	public function findLatestPaidByShareAndClientUser(string $shareId, string $clientUserId): ?Payment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->andWhere($qb->expr()->eq('client_user_id', $qb->createNamedParameter($clientUserId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('paid')))
			->orderBy('paid_at', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @param list<string> $shareIds
	 * @return array<string, int> share_id => paid count
	 * @throws Exception
	 */
	public function countPaidByShareIds(array $shareIds): array {
		if ($shareIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias('share_id', 'share_id')
			->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('paid')))
			->andWhere($qb->expr()->in('share_id', $qb->createNamedParameter($shareIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('share_id');

		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(string)$row['share_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * @param list<string> $shareIds
	 * @return array<string, int> share_id => total paid amount (cents)
	 * @throws Exception
	 */
	public function sumPaidAmountByShareIds(array $shareIds): array {
		if ($shareIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias('share_id', 'share_id')
			->selectAlias($qb->createFunction('COALESCE(SUM(amount), 0)'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('paid')))
			->andWhere($qb->expr()->in('share_id', $qb->createNamedParameter($shareIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('share_id');

		$result = $qb->executeQuery();
		$amounts = [];
		while ($row = $result->fetch()) {
			$amounts[(string)$row['share_id']] = (int)$row['total'];
		}
		$result->closeCursor();
		return $amounts;
	}

	/**
	 * @param list<string> $shareIds
	 * @return array<string, int> share_id => last paid_at (ms)
	 * @throws Exception
	 */
	public function lastPaidAtByShareIds(array $shareIds): array {
		if ($shareIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias('share_id', 'share_id')
			->selectAlias($qb->createFunction('MAX(paid_at)'), 'last_paid')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('paid')))
			->andWhere($qb->expr()->in('share_id', $qb->createNamedParameter($shareIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('share_id');

		$result = $qb->executeQuery();
		$map = [];
		while ($row = $result->fetch()) {
			if ($row['last_paid'] !== null) {
				$map[(string)$row['share_id']] = (int)$row['last_paid'];
			}
		}
		$result->closeCursor();
		return $map;
	}

	/**
	 * @param list<string> $shareIds
	 * @return list<array{order_id: string, share_id: string, amount: int, paid_at: int, buyer_email: string}>
	 * @throws Exception
	 */
	public function findPaidByShareIds(array $shareIds): array {
		if ($shareIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('paid')))
			->andWhere($qb->expr()->in('share_id', $qb->createNamedParameter($shareIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('paid_at', 'DESC')
			->setMaxResults(100);

		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = [
				'order_id' => (string)$row['order_id'],
				'share_id' => (string)$row['share_id'],
				'amount' => (int)$row['amount'],
				'paid_at' => (int)$row['paid_at'],
				'buyer_email' => (string)($row['buyer_email'] ?? ''),
			];
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @param list<string> $shareIds
	 * @return array{paid_orders: int, total_amount: int}
	 * @throws Exception
	 */
	/**
	 * @return array{paid_orders: int, total_amount: int}
	 * @throws Exception
	 */
	public function sumAllPaid(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->selectAlias($qb->createFunction('COALESCE(SUM(amount), 0)'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('paid')));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return [
			'paid_orders' => (int)($row['cnt'] ?? 0),
			'total_amount' => (int)($row['total'] ?? 0),
		];
	}

	/**
	 * @return Payment[]
	 * @throws Exception
	 */
	public function findAll(int $limit = 100, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/**
	 * @throws Exception
	 */
	public function countAll(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName());
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	/**
	 * @param list<string> $shareIds
	 * @return array{paid_orders: int, total_amount: int}
	 * @throws Exception
	 */
	public function sumPaidForShareIds(array $shareIds): array {
		if ($shareIds === []) {
			return ['paid_orders' => 0, 'total_amount' => 0];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->selectAlias($qb->createFunction('COALESCE(SUM(amount), 0)'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('paid')))
			->andWhere($qb->expr()->in('share_id', $qb->createNamedParameter($shareIds, IQueryBuilder::PARAM_STR_ARRAY)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return [
			'paid_orders' => (int)($row['cnt'] ?? 0),
			'total_amount' => (int)($row['total'] ?? 0),
		];
	}
}
