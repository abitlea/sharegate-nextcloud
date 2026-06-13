<?php

declare(strict_types=1);

namespace OCA\ShareGate\Payment;

use OCP\IURLGenerator;

/**
 * 阶段 2：模拟支付（开发/测试用，不产生真实扣款）
 */
class MockPaymentProvider {
	public const NAME = 'mock';

	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	public function createPaymentUrl(string $orderId, string $providerUserId): string {
		return $this->urlGenerator->linkToRoute(
			'sharegate.payment.mockPay',
			['orderId' => $orderId],
		) . '?provider_user_id=' . rawurlencode($providerUserId);
	}
}
