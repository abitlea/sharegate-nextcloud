<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Db\AccessGrant;
use OCA\ShareGate\Db\AccessGrantMapper;
use OCA\ShareGate\Db\Payment;
use OCA\ShareGate\Db\PaymentMapper;
use OCA\ShareGate\Db\Share;
use OCA\ShareGate\Db\ShareMapper;
use OCA\ShareGate\Payment\AlipayF2fProvider;
use OCA\ShareGate\Payment\MockPaymentProvider;
use OCA\ShareGate\Util\QrCodeRenderer;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;

/**
 * 对应 monorepo PaymentManager
 */
class PaymentService {
	public function __construct(
		private ShareMapper $shareMapper,
		private PaymentMapper $paymentMapper,
		private AccessGrantMapper $accessGrantMapper,
		private MockPaymentProvider $mockProvider,
		private AlipayF2fProvider $alipayProvider,
		private PaymentConfigService $paymentConfig,
		private QrCodeRenderer $qrCodeRenderer,
	) {
	}

	public function generateOrderId(): string {
		return 'SG' . strtoupper(base_convert((string)time(), 10, 36))
			. strtoupper(bin2hex(random_bytes(4)));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function createPayment(array $data): array {
		$shareId = trim((string)($data['share_id'] ?? ''));
		$providerUserId = trim((string)($data['provider_user_id'] ?? $data['client_user_id'] ?? ''));

		if ($shareId === '') {
			return ['success' => false, 'error' => '缺少 share_id'];
		}

		try {
			$share = $this->shareMapper->findByShareId($shareId);
		} catch (DoesNotExistException) {
			return ['success' => false, 'error' => '分享链接不存在'];
		}

		if ($this->isShareExpired($share->getExpireAt())) {
			return ['success' => false, 'error' => '分享已过期'];
		}

		if ($providerUserId !== '' && $this->hasUserPaid($shareId, $providerUserId)) {
			return ['success' => true, 'already_paid' => true, 'message' => '已购买过'];
		}

		$orderId = $this->generateOrderId();
		$provider = $this->paymentConfig->getActiveProviderName();
		$buyerId = $providerUserId !== '' ? $providerUserId : ('buyer_' . $orderId);

		$qrCode = null;
		$paymentUrl = null;
		$providerOrderId = null;

		if ($provider === AlipayF2fProvider::NAME) {
			$alipayResult = $this->alipayProvider->createPayment(
				$orderId,
				$share->getTitle(),
				$share->getPrice(),
			);
			if (!($alipayResult['success'] ?? false)) {
				return $alipayResult;
			}
			$qrCode = $alipayResult['qr_code'];
			$providerOrderId = $alipayResult['provider_order_id'] ?? null;
		} else {
			$paymentUrl = $this->mockProvider->createPaymentUrl($orderId, $buyerId);
			$qrCode = $paymentUrl;
		}

		$payment = new Payment();
		$payment->setShareId($shareId);
		$payment->setOrderId($orderId);
		$payment->setAmount($share->getPrice());
		$payment->setProvider($provider);
		$payment->setClientUserId($buyerId);
		$payment->setStatus('pending');
		$payment->setQrCode($qrCode);
		$payment->setProviderOrderId($providerOrderId);
		$payment->setCreatedAt((int)(microtime(true) * 1000));

		try {
			$this->paymentMapper->insert($payment);
		} catch (Exception $e) {
			return ['success' => false, 'error' => '创建订单失败: ' . $e->getMessage()];
		}

		$response = [
			'success' => true,
			'order_id' => $orderId,
			'provider' => $provider,
			'qr_code' => $qrCode,
		];
		if ($paymentUrl !== null) {
			$response['payment_url'] = $paymentUrl;
		}
		$qrImage = $this->qrCodeRenderer->toDataUri((string)$qrCode);
		if ($qrImage !== null) {
			$response['qr_image'] = $qrImage;
		}
		$qrSvg = $this->qrCodeRenderer->toRawSvg((string)$qrCode);
		if ($qrSvg !== null) {
			$response['qr_svg'] = $qrSvg;
		}
		return $response;
	}

	public function getQrSvgForOrder(string $orderId): ?string {
		try {
			$payment = $this->paymentMapper->findByOrderId($orderId);
		} catch (DoesNotExistException) {
			return null;
		}
		$qrCode = $payment->getQrCode();
		if ($qrCode === null || $qrCode === '') {
			return null;
		}
		return $this->qrCodeRenderer->toRawSvg($qrCode);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function confirmPayment(string $orderId, string $providerUserId, ?string $providerOrderId = null): array {
		try {
			$payment = $this->paymentMapper->findByOrderId($orderId);
		} catch (DoesNotExistException) {
			return ['success' => false, 'error' => '订单不存在'];
		}

		if ($payment->getStatus() === 'paid') {
			$this->ensureAccessGrant($payment);
			return ['success' => true, 'message' => '订单已支付'];
		}

		$grantUserId = trim((string)($payment->getClientUserId() ?? ''));
		if ($grantUserId === '') {
			$grantUserId = $providerUserId !== '' ? $providerUserId : ('buyer_' . $orderId);
		}

		try {
			$share = $this->shareMapper->findByShareId($payment->getShareId());
		} catch (DoesNotExistException) {
			return ['success' => false, 'error' => '分享不存在'];
		}

		$now = (int)(microtime(true) * 1000);
		$payment->setStatus('paid');
		$payment->setPaidAt($now);
		$payment->setProviderOrderId($providerOrderId ?? $payment->getProviderOrderId() ?? bin2hex(random_bytes(16)));

		try {
			$this->paymentMapper->update($payment);
		} catch (Exception $e) {
			return ['success' => false, 'error' => '更新订单失败: ' . $e->getMessage()];
		}

		try {
			$this->insertAccessGrant($payment, $share, $grantUserId, $now);
		} catch (Exception $e) {
			return ['success' => false, 'error' => '创建授权失败: ' . $e->getMessage()];
		}

		return [
			'success' => true,
			'message' => '支付成功',
		];
	}

	/**
	 * @param array<string, string> $params
	 * @return array<string, mixed>
	 */
	public function handleAlipayNotify(array $params): array {
		$verify = $this->alipayProvider->verifyCallback($params);
		if (!($verify['success'] ?? false)) {
			return $verify;
		}

		return $this->confirmPayment(
			$verify['order_id'],
			$verify['payer_user_id'],
			$verify['provider_order_id'],
		);
	}

	public function hasUserPaid(string $shareId, string $providerUserId): bool {
		try {
			if ($this->accessGrantMapper->hasActiveGrant($shareId, $providerUserId)) {
				return true;
			}
		} catch (Exception) {
			return false;
		}

		return $this->repairAccessGrant($shareId, $providerUserId);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function queryOrderStatus(string $orderId): array {
		try {
			$payment = $this->paymentMapper->findByOrderId($orderId);
		} catch (DoesNotExistException) {
			return ['success' => false, 'error' => '订单不存在'];
		}

		if ($payment->getStatus() === 'paid') {
			return ['success' => true, 'status' => 'paid'];
		}

		if ($payment->getProvider() === AlipayF2fProvider::NAME) {
			$query = $this->alipayProvider->queryOrder($orderId);
			if (($query['success'] ?? false) && ($query['status'] ?? '') === 'paid') {
				$this->confirmPayment(
					$orderId,
					(string)($query['payer_user_id'] ?? $payment->getClientUserId() ?? 'alipay_user'),
					(string)($query['provider_order_id'] ?? ''),
				);
				return ['success' => true, 'status' => 'paid'];
			}
			if ($query['success'] ?? false) {
				return ['success' => true, 'status' => $query['status'] ?? 'pending'];
			}
		}

		return [
			'success' => true,
			'status' => $payment->getStatus(),
		];
	}

	private function isShareExpired(?int $expireAt): bool {
		return $expireAt !== null && $expireAt < (int)(microtime(true) * 1000);
	}

	private function ensureAccessGrant(Payment $payment): void {
		$grantUserId = trim((string)($payment->getClientUserId() ?? ''));
		if ($grantUserId === '') {
			return;
		}

		try {
			if ($this->accessGrantMapper->hasActiveGrant($payment->getShareId(), $grantUserId)) {
				return;
			}
			$share = $this->shareMapper->findByShareId($payment->getShareId());
		} catch (DoesNotExistException|Exception) {
			return;
		}

		$paidAt = $payment->getPaidAt() ?? (int)(microtime(true) * 1000);
		try {
			$this->insertAccessGrant($payment, $share, $grantUserId, $paidAt);
		} catch (Exception) {
			// ignore repair failures
		}
	}

	private function repairAccessGrant(string $shareId, string $providerUserId): bool {
		try {
			$payment = $this->paymentMapper->findLatestPaidByShareAndClientUser($shareId, $providerUserId);
		} catch (Exception) {
			return false;
		}
		if ($payment === null) {
			return false;
		}

		$this->ensureAccessGrant($payment);

		try {
			return $this->accessGrantMapper->hasActiveGrant($shareId, $providerUserId);
		} catch (Exception) {
			return false;
		}
	}

	/**
	 * @throws Exception
	 */
	private function insertAccessGrant(Payment $payment, Share $share, string $grantUserId, int $anchorMs): void {
		$grant = new AccessGrant();
		$grant->setShareId($payment->getShareId());
		$grant->setPaymentId((int)$payment->getId());
		$grant->setProviderUserId($grantUserId);
		$grant->setCreatedAt($anchorMs);
		$grant->setExpiresAt($anchorMs + $share->getAccessDays() * 86400000);
		$this->accessGrantMapper->insert($grant);
	}
}
