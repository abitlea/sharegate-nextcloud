<?php

declare(strict_types=1);

namespace OCA\ShareGate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getShareId()
 * @method void setShareId(string $shareId)
 * @method string getOrderId()
 * @method void setOrderId(string $orderId)
 * @method int getAmount()
 * @method void setAmount(int $amount)
 * @method string getProvider()
 * @method void setProvider(string $provider)
 * @method string|null getProviderOrderId()
 * @method void setProviderOrderId(?string $providerOrderId)
 * @method string|null getClientUserId()
 * @method void setClientUserId(?string $clientUserId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getQrCode()
 * @method void setQrCode(?string $qrCode)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int|null getPaidAt()
 * @method void setPaidAt(?int $paidAt)
 */
class Payment extends Entity {
	protected string $shareId = '';
	protected string $orderId = '';
	protected int $amount = 0;
	protected string $provider = '';
	protected ?string $providerOrderId = null;
	protected ?string $clientUserId = null;
	protected string $status = 'pending';
	protected ?string $qrCode = null;
	protected int $createdAt = 0;
	protected ?int $paidAt = null;

	public function __construct() {
		$this->addType('shareId', 'string');
		$this->addType('orderId', 'string');
		$this->addType('amount', 'integer');
		$this->addType('provider', 'string');
		$this->addType('providerOrderId', 'string');
		$this->addType('clientUserId', 'string');
		$this->addType('status', 'string');
		$this->addType('qrCode', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('paidAt', 'integer');
	}
}
