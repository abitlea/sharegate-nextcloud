<?php

declare(strict_types=1);

namespace OCA\ShareGate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getShareId()
 * @method void setShareId(string $shareId)
 * @method int getPreviewCount()
 * @method void setPreviewCount(int $previewCount)
 * @method int getSaveCount()
 * @method void setSaveCount(int $saveCount)
 * @method int getDownloadCount()
 * @method void setDownloadCount(int $downloadCount)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class ShareStats extends Entity {
	protected string $shareId = '';
	protected int $previewCount = 0;
	protected int $saveCount = 0;
	protected int $downloadCount = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('shareId', 'string');
		$this->addType('previewCount', 'integer');
		$this->addType('saveCount', 'integer');
		$this->addType('downloadCount', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}
