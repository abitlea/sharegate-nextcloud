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
use OCA\ShareGate\Payment\PayPalProvider;
use OCA\ShareGate\Payment\StripeProvider;
use OCA\ShareGate\Util\QrCodeRenderer;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\IL10N;

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
		private StripeProvider $stripeProvider,
		private PayPalProvider $paypalProvider,
		private PaymentConfigService $paymentConfig,
		private QrCodeRenderer $qrCodeRenderer,
		private IL10N $l,
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
			return ['success' => false, 'error' => $this->l->t('Missing share_id')];
		}

		try {
			$share = $this->shareMapper->findByShareId($shareId);
		} catch (DoesNotExistException) {
			return ['success' => false, 'error' => $this->l->t('Share link not found')];
		}

		if ($this->isShareExpired($share->getExpireAt())) {
			return ['success' => false, 'error' => $this->l->t('Share expired')];
		}

		if ($providerUserId !== '' && $this->hasUserPaid($shareId, $providerUserId)) {
			return ['success' => true, 'already_paid' => true, 'message' => $this->l->t('Already purchased')];
		}

		$orderId = $this->generateOrderId();
		$provider = $this->paymentConfig->getActiveProviderName();
		$buyerId = $providerUserId !== '' ? $providerUserId : ('buyer_' . $orderId);

		$qrCode = null;
		$paymentUrl = null;
		$providerOrderId = null;
		$paymentFlow = 'qrcode';

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
		} elseif ($provider === StripeProvider::NAME) {
			$stripeResult = $this->stripeProvider->createCheckoutSession(
				$orderId,
				$shareId,
				$share->getTitle(),
				$share->getPrice(),
			);
			if (!($stripeResult['success'] ?? false)) {
				return $stripeResult;
			}
			$paymentUrl = $stripeResult['payment_url'];
			$providerOrderId = $stripeResult['session_id'];
			$paymentFlow = 'redirect';
		} elseif ($provider === PayPalProvider::NAME) {
			$paypalResult = $this->paypalProvider->createCheckoutOrder(
				$orderId,
				$shareId,
				$share->getTitle(),
				$share->getPrice(),
			);
			if (!($paypalResult['success'] ?? false)) {
				return $paypalResult;
			}
			$paymentUrl = $paypalResult['payment_url'];
			$providerOrderId = $paypalResult['order_id'];
			$paymentFlow = 'redirect';
		} else {
			$paymentUrl = $this->mockProvider->createPaymentUrl($orderId, $buyerId);
			$qrCode = $paymentUrl;
		}

		$providerName = $provider !== '' ? $provider : MockPaymentProvider::NAME;

		$payment = new Payment();
		$payment->setShareId($shareId);
		$payment->setOrderId($orderId);
		$payment->setAmount($share->getPrice());
		// Entity skips unchanged defaults on INSERT; touch provider so MySQL always gets a value.
		$payment->setProvider('');
		$payment->setProvider($providerName);
		$payment->setClientUserId($buyerId);
		$payment->setStatus('pending');
		$payment->setQrCode($qrCode);
		$payment->setProviderOrderId($providerOrderId);
		$payment->setCreatedAt((int)(microtime(true) * 1000));

		try {
			$this->paymentMapper->insert($payment);
		} catch (Exception $e) {
			return ['success' => false, 'error' => $this->l->t('Failed to create order: %s', [$e->getMessage()])];
		}

		$response = [
			'success' => true,
			'order_id' => $orderId,
			'provider' => $provider,
			'payment_flow' => $paymentFlow,
		];
		if ($qrCode !== null && $qrCode !== '') {
			$response['qr_code'] = $qrCode;
		}
		if ($paymentUrl !== null) {
			$response['payment_url'] = $paymentUrl;
		}
		if ($qrCode !== null && $qrCode !== '' && $paymentFlow === 'qrcode') {
			$qrImage = $this->qrCodeRenderer->toDataUri((string)$qrCode);
			if ($qrImage !== null) {
				$response['qr_image'] = $qrImage;
			}
			$qrSvg = $this->qrCodeRenderer->toRawSvg((string)$qrCode);
			if ($qrSvg !== null) {
				$response['qr_svg'] = $qrSvg;
			}
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
			return ['success' => false, 'error' => $this->l->t('Order not found')];
		}

		if ($payment->getStatus() === 'paid') {
			$this->ensureAccessGrant($payment);
			return ['success' => true, 'message' => $this->l->t('Order already paid')];
		}

		$grantUserId = trim((string)($payment->getClientUserId() ?? ''));
		if ($grantUserId === '') {
			$grantUserId = $providerUserId !== '' ? $providerUserId : ('buyer_' . $orderId);
		}

		try {
			$share = $this->shareMapper->findByShareId($payment->getShareId());
		} catch (DoesNotExistException) {
			return ['success' => false, 'error' => $this->l->t('Share not found')];
		}

		$now = (int)(microtime(true) * 1000);
		$payment->setStatus('paid');
		$payment->setPaidAt($now);
		$payment->setProviderOrderId($providerOrderId ?? $payment->getProviderOrderId() ?? bin2hex(random_bytes(16)));

		try {
			$this->paymentMapper->update($payment);
		} catch (Exception $e) {
			return ['success' => false, 'error' => $this->l->t('Failed to update order: %s', [$e->getMessage()])];
		}

		try {
			$this->insertAccessGrant($payment, $share, $grantUserId, $now);
		} catch (Exception $e) {
			return ['success' => false, 'error' => $this->l->t('Failed to create access grant: %s', [$e->getMessage()])];
		}

		return [
			'success' => true,
			'message' => $this->l->t('Payment successful'),
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

	public function handleStripeNotify(string $payload, string $signatureHeader): array {
		$verify = $this->stripeProvider->verifyWebhook($payload, $signatureHeader);
		if (!($verify['success'] ?? false)) {
			if (($verify['error'] ?? '') === 'ignored') {
				return ['success' => true, 'ignored' => true];
			}
			return $verify;
		}

		return $this->confirmPayment(
			$verify['order_id'],
			$verify['payer_user_id'],
			$verify['provider_order_id'],
		);
	}

	public function handlePaypalNotify(string $payload, array $headers): array {
		if (!$this->paypalProvider->verifyWebhook($payload, $headers)) {
			// Allow processing when webhook ID is not configured (rely on API capture on return).
			$cfg = $this->paymentConfig->getPaypalConfig();
			if ($cfg['webhook_id'] !== '') {
				return ['success' => false, 'error' => $this->l->t('PayPal webhook signature verification failed')];
			}
		}

		/** @var array<string, mixed>|null $event */
		$event = json_decode($payload, true);
		if (!is_array($event)) {
			return ['success' => false, 'error' => $this->l->t('Invalid PayPal webhook payload')];
		}

		$result = $this->paypalProvider->handleWebhookEvent($event);
		if (!($result['success'] ?? false)) {
			if (($result['error'] ?? '') === 'ignored') {
				return ['success' => true, 'ignored' => true];
			}
			return $result;
		}

		return $this->confirmPayment(
			$result['order_id'],
			$result['payer_user_id'],
			$result['provider_order_id'],
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
	public function queryOrderStatus(string $orderId, ?string $paypalToken = null): array {
		try {
			$payment = $this->paymentMapper->findByOrderId($orderId);
		} catch (DoesNotExistException) {
			return ['success' => false, 'error' => $this->l->t('Order not found')];
		}

		if ($payment->getStatus() === 'paid') {
			$this->confirmPayment(
				$orderId,
				(string)($payment->getClientUserId() ?? 'paypal_user'),
				(string)($payment->getProviderOrderId() ?? ''),
			);
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

		if ($payment->getProvider() === StripeProvider::NAME) {
			$sessionId = (string)($payment->getProviderOrderId() ?? '');
			if ($sessionId !== '') {
				$query = $this->stripeProvider->querySession($sessionId);
				if (($query['success'] ?? false) && ($query['status'] ?? '') === 'paid') {
					$this->confirmPayment(
						$orderId,
						(string)($query['payer_user_id'] ?? $payment->getClientUserId() ?? 'stripe_customer'),
						(string)($query['provider_order_id'] ?? $sessionId),
					);
					return ['success' => true, 'status' => 'paid'];
				}
				if ($query['success'] ?? false) {
					return ['success' => true, 'status' => $query['status'] ?? 'pending'];
				}
			}
		}

		if ($payment->getProvider() === PayPalProvider::NAME) {
			$paypalOrderId = trim((string)($paypalToken ?? ''));
			if ($paypalOrderId === '') {
				$paypalOrderId = (string)($payment->getProviderOrderId() ?? '');
			}
			if ($paypalOrderId !== '') {
				$query = $this->paypalProvider->queryAndCaptureOrder($paypalOrderId);
				if (($query['success'] ?? false) && ($query['status'] ?? '') === 'paid') {
					$confirm = $this->confirmPayment(
						$orderId,
						(string)($query['payer_user_id'] ?? $payment->getClientUserId() ?? 'paypal_user'),
						(string)($query['provider_order_id'] ?? $paypalOrderId),
					);
					if (!($confirm['success'] ?? false)) {
						return [
							'success' => false,
							'error' => $confirm['error'] ?? $this->l->t('PayPal capture failed'),
							'status' => 'pending',
						];
					}
					return ['success' => true, 'status' => 'paid'];
				}
				if ($query['success'] ?? false) {
					return ['success' => true, 'status' => $query['status'] ?? 'pending'];
				}
				if (!empty($query['error'])) {
					return [
						'success' => false,
						'error' => (string)$query['error'],
						'status' => 'pending',
					];
				}
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
