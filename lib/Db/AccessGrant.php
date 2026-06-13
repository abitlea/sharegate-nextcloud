<?php

declare(strict_types=1);

namespace OCA\ShareGate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getShareId()
 * @method void setShareId(string $shareId)
 * @method int getPaymentId()
 * @method void setPaymentId(int $paymentId)
 * @method string getProviderUserId()
 * @method void setProviderUserId(string $providerUserId)
 * @method string|null getAccessToken()
 * @method void setAccessToken(?string $accessToken)
 * @method int|null getExpiresAt()
 * @method void setExpiresAt(?int $expiresAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class AccessGrant extends Entity {
	protected string $shareId = '';
	protected int $paymentId = 0;
	protected string $providerUserId = '';
	protected ?string $accessToken = null;
	protected ?int $expiresAt = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('shareId', 'string');
		$this->addType('paymentId', 'integer');
		$this->addType('providerUserId', 'string');
		$this->addType('accessToken', 'string');
		$this->addType('expiresAt', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
