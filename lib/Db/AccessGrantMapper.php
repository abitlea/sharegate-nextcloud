<?php

declare(strict_types=1);

namespace OCA\ShareGate\Db;

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
}
