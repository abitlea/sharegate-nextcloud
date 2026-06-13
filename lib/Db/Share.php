<?php

declare(strict_types=1);

namespace OCA\ShareGate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getShareId()
 * @method void setShareId(string $shareId)
 * @method string getFilePath()
 * @method void setFilePath(string $filePath)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method int getFileSize()
 * @method void setFileSize(int $fileSize)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method int getPrice()
 * @method void setPrice(int $price)
 * @method int getAccessDays()
 * @method void setAccessDays(int $accessDays)
 * @method string getStorageType()
 * @method void setStorageType(string $storageType)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int|null getExpireAt()
 * @method void setExpireAt(?int $expireAt)
 */
class Share extends Entity {
	protected string $shareId = '';
	protected string $filePath = '';
	protected string $fileName = '';
	protected int $fileSize = 0;
	protected string $title = '';
	protected string $description = '';
	/** 价格，单位：分（与 ShareGate monorepo 一致） */
	protected int $price = 0;
	protected int $accessDays = 30;
	protected string $storageType = 'nextcloud';
	protected string $status = 'active';
	protected string $createdBy = '';
	protected int $createdAt = 0;
	protected ?int $expireAt = null;

	public function __construct() {
		$this->addType('shareId', 'string');
		$this->addType('filePath', 'string');
		$this->addType('fileName', 'string');
		$this->addType('fileSize', 'integer');
		$this->addType('title', 'string');
		$this->addType('description', 'string');
		$this->addType('price', 'integer');
		$this->addType('accessDays', 'integer');
		$this->addType('storageType', 'string');
		$this->addType('status', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('expireAt', 'integer');
	}
}
