<?php

declare(strict_types=1);

namespace OCA\ShareGate\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<ShareStats> */
class ShareStatsMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sharegate_share_stats', ShareStats::class);
	}

	/**
	 * @throws Exception
	 */
	public function ensureExists(string $shareId): ShareStats {
		try {
			return $this->findByShareId($shareId);
		} catch (DoesNotExistException) {
			$stats = new ShareStats();
			$stats->setShareId($shareId);
			$stats->setPreviewCount(0);
			$stats->setSaveCount(0);
			$stats->setDownloadCount(0);
			$stats->setUpdatedAt((int)(microtime(true) * 1000));
			/** @var ShareStats $inserted */
			$inserted = $this->insert($stats);
			return $inserted;
		}
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function findByShareId(string $shareId): ShareStats {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)));
		return $this->findEntity($qb);
	}

	/**
	 * @param list<string> $shareIds
	 * @return array<string, ShareStats>
	 * @throws Exception
	 */
	public function findByShareIds(array $shareIds): array {
		if ($shareIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('share_id', $qb->createNamedParameter($shareIds, IQueryBuilder::PARAM_STR_ARRAY)));
		$entities = $this->findEntities($qb);
		$map = [];
		foreach ($entities as $entity) {
			$map[$entity->getShareId()] = $entity;
		}
		return $map;
	}

	/**
	 * @throws Exception
	 */
	/**
	 * @return array{preview_count: int, save_count: int, download_count: int}
	 * @throws Exception
	 */
	public function sumTotals(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COALESCE(SUM(preview_count), 0)'), 'previews')
			->selectAlias($qb->createFunction('COALESCE(SUM(save_count), 0)'), 'saves')
			->selectAlias($qb->createFunction('COALESCE(SUM(download_count), 0)'), 'downloads')
			->from($this->getTableName());
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return [
			'preview_count' => (int)($row['previews'] ?? 0),
			'save_count' => (int)($row['saves'] ?? 0),
			'download_count' => (int)($row['downloads'] ?? 0),
		];
	}

	/**
	 * @throws Exception
	 */
	public function increment(string $shareId, string $field): void {
		$this->ensureExists($shareId);
		if (!in_array($field, ['preview_count', 'save_count', 'download_count'], true)) {
			throw new \InvalidArgumentException('Invalid stats field');
		}
		$now = (int)(microtime(true) * 1000);
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set($field, $qb->createFunction($field . ' + 1'))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)));
		$qb->executeStatement();
	}
}
