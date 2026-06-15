<?php

declare(strict_types=1);

namespace OCA\ShareGate\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;

/** @extends QBMapper<Share> */
class ShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sharegate_shares', Share::class);
	}

	/**
	 * @throws Exception
	 */
	public function findByShareId(string $shareId): Share {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));
		return $this->findEntity($qb);
	}

	/**
	 * @return Share[]
	 * @throws Exception
	 */
	/**
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function findOwnedByShareId(string $shareId, string $userId): Share {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->andWhere($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
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
	 * @throws Exception
	 */
	public function countActive(): int {
		$now = (int)(microtime(true) * 1000);
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('expire_at'),
					$qb->expr()->gt('expire_at', $qb->createNamedParameter($now)),
				),
			);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	/**
	 * @return Share[]
	 * @throws Exception
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * 同一用户、同一路径下是否已有有效付费分享（用于创建前去重）。
	 */
	public function findActiveByUserAndFilePath(string $userId, string $filePath): ?Share {
		$needle = $this->normalizeStoredPath($filePath);
		$now = (int)(microtime(true) * 1000);

		try {
			$shares = $this->findByUser($userId);
		} catch (Exception) {
			return null;
		}

		foreach ($shares as $share) {
			if ($share->getStatus() !== 'active') {
				continue;
			}
			$expireAt = $share->getExpireAt();
			if ($expireAt !== null && $expireAt <= $now) {
				continue;
			}
			if ($this->normalizeStoredPath($share->getFilePath()) === $needle) {
				return $share;
			}
		}

		return null;
	}

	/**
	 * 管理台付费分享列表：仅有效分享，DB 分页（避免一次加载全部导致超时/500）。
	 * 同一文件只保留最新一条有效分享，避免重复创建导致列表重复。
	 *
	 * @return Share[]
	 * @throws Exception
	 */
	public function findActiveByUserForList(
		string $userId,
		string $query,
		int $limit,
		int $offset,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());
		$this->applyUserActiveConstraints($qb, $userId);
		$this->applySearchConstraint($qb, $query);
		$qb->orderBy('created_at', 'DESC');
		$shares = $this->findEntities($qb);
		$deduped = $this->dedupeByFilePath($shares);
		$this->disableStaleDuplicates($shares, $deduped);

		return array_slice($deduped, $offset, $limit);
	}

	/**
	 * @throws Exception
	 */
	public function countActiveByUserForList(string $userId, string $query): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());
		$this->applyUserActiveConstraints($qb, $userId);
		$this->applySearchConstraint($qb, $query);
		$qb->orderBy('created_at', 'DESC');
		$shares = $this->findEntities($qb);
		$deduped = $this->dedupeByFilePath($shares);
		$this->disableStaleDuplicates($shares, $deduped);

		return count($deduped);
	}

	/**
	 * @param Share[] $shares
	 * @return Share[]
	 */
	private function dedupeByFilePath(array $shares): array {
		/** @var array<string, Share> $byPath */
		$byPath = [];

		foreach ($shares as $share) {
			$key = $this->normalizeStoredPath($share->getFilePath());
			$current = $byPath[$key] ?? null;
			if ($current === null || $share->getCreatedAt() > $current->getCreatedAt()) {
				$byPath[$key] = $share;
			}
		}

		$items = array_values($byPath);
		usort(
			$items,
			static fn (Share $a, Share $b): int => $b->getCreatedAt() <=> $a->getCreatedAt(),
		);

		return $items;
	}

	private function normalizeStoredPath(string $path): string {
		return '/' . trim($path, '/');
	}

	/**
	 * @param Share[] $all
	 * @param Share[] $keepers
	 */
	private function disableStaleDuplicates(array $all, array $keepers): void {
		$keepIds = [];
		foreach ($keepers as $share) {
			$keepIds[$share->getShareId()] = true;
		}

		foreach ($all as $share) {
			$shareId = $share->getShareId();
			if (isset($keepIds[$shareId])) {
				continue;
			}
			try {
				$this->disableShare($shareId);
			} catch (Exception) {
				// 清理失败不阻断列表
			}
		}
	}

	/**
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb
	 */
	private function applyUserActiveConstraints($qb, string $userId): void {
		$now = (int)(microtime(true) * 1000);
		$qb->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('expire_at'),
					$qb->expr()->gt('expire_at', $qb->createNamedParameter($now)),
				),
			);
	}

	/**
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb
	 */
	private function applySearchConstraint($qb, string $query): void {
		$query = trim($query);
		if ($query === '') {
			return;
		}
		$needle = '%' . $this->escapeLike($query) . '%';
		$qb->andWhere(
			$qb->expr()->orX(
				$qb->expr()->like('title', $qb->createNamedParameter($needle)),
				$qb->expr()->like('file_name', $qb->createNamedParameter($needle)),
				$qb->expr()->like('share_id', $qb->createNamedParameter($needle)),
			),
		);
	}

	private function escapeLike(string $value): string {
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
	}

	/**
	 * @return Share[]
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
	public function disableShare(string $shareId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('disabled'))
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->executeStatement();
	}
}