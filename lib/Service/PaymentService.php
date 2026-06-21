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
use OCA\ShareGate\Util\BuyerAccount;
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
		private BuyerAccessTokenService $accessTokenService,
		private BuyerPurchasesTokenService $purchasesTokenService,
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
	public function confirmPayment(
		string $orderId,
		string $providerUserId,
		?string $providerOrderId = null,
		array $supplementalGrantUserIds = [],
	): array {
		try {
			$payment = $this->paymentMapper->findByOrderId($orderId);
		} catch (DoesNotExistException) {
			return ['success' => false, 'error' => $this->l->t('Order not found')];
		}

		if ($payment->getStatus() === 'paid') {
			$this->ensureAccessGrantsForPayment($payment);
			return $this->appendAccessTokenFields([
				'success' => true,
				'message' => $this->l->t('Order already paid'),
				'payer_user_id' => $this->payerUserIdForPayment($payment),
			], $payment);
		}

		$sessionUserId = trim((string)($payment->getClientUserId() ?? ''));
		$payerGrantId = $this->resolveGrantUserId($payment, $providerUserId);

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
			$this->issueAccessGrantsAfterPayment($payment, $share, $payerGrantId, $sessionUserId, $now);
			$this->issueSupplementalAccessGrants($payment, $share, $payerGrantId, $sessionUserId, $now, $supplementalGrantUserIds);
		} catch (Exception $e) {
			return ['success' => false, 'error' => $this->l->t('Failed to create access grant: %s', [$e->getMessage()])];
		}

		return $this->appendAccessTokenFields([
			'success' => true,
			'message' => $this->l->t('Payment successful'),
			'payer_user_id' => $payerGrantId,
		], $payment);
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
			$this->supplementalAlipayGrantIds($verify),
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

		if (($verify['event_type'] ?? '') === 'charge.refunded') {
			$orderId = (string)($verify['order_id'] ?? '');
			if ($orderId !== '') {
				$this->markOrderRefunded(
					$orderId,
					(string)($verify['status_message'] ?? $this->l->t('Payment refunded')),
				);
			}
			return ['success' => true, 'refunded' => true];
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

		if (($result['event_type'] ?? '') === 'refunded') {
			$orderId = (string)($result['order_id'] ?? '');
			if ($orderId !== '') {
				$this->markOrderRefunded(
					$orderId,
					(string)($result['status_message'] ?? $this->l->t('Payment refunded')),
				);
			}
			return ['success' => true, 'refunded' => true];
		}

		return $this->confirmPayment(
			$result['order_id'],
			$result['payer_user_id'],
			$result['provider_order_id'],
		);
	}

	public function hasUserPaid(string $shareId, string $providerUserId): bool {
		try {
			if ($this->accessGrantMapper->hasActiveGrantForPayer($shareId, $providerUserId)) {
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
	public function queryOrderStatus(string $orderId, ?string $paypalToken = null, bool $buyerCancelled = false): array {
		try {
			$payment = $this->paymentMapper->findByOrderId($orderId);
		} catch (DoesNotExistException) {
			return ['success' => false, 'error' => $this->l->t('Order not found')];
		}

		if ($buyerCancelled && $payment->getStatus() === 'pending') {
			$reason = $this->l->t('Buyer cancelled payment');
			$this->markOrderCancelled($orderId, $reason);
			return ['success' => true, 'status' => 'cancelled', 'status_message' => $reason];
		}

		if (in_array($payment->getStatus(), ['cancelled', 'failed', 'refunded'], true)) {
			return [
				'success' => true,
				'status' => $payment->getStatus(),
				'status_message' => $payment->getStatusMessage(),
			];
		}

		if ($payment->getStatus() === 'paid') {
			$this->repairPaidOrderGrants($payment);
			return $this->paidStatusResponse($orderId);
		}

		if ($payment->getProvider() === AlipayF2fProvider::NAME) {
			$query = $this->alipayProvider->queryOrder($orderId);
			if (($query['success'] ?? false) && ($query['status'] ?? '') === 'paid') {
				$this->confirmPayment(
					$orderId,
					(string)($query['payer_user_id'] ?? $payment->getClientUserId() ?? 'alipay_user'),
					(string)($query['provider_order_id'] ?? ''),
					$this->supplementalAlipayGrantIds($query),
				);
				return $this->paidStatusResponse($orderId);
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
					return $this->paidStatusResponse($orderId);
				}
				if ($query['success'] ?? false) {
					$stripeStatus = (string)($query['status'] ?? 'pending');
					if ($stripeStatus === 'expired') {
						$reason = (string)($query['status_message'] ?? $this->l->t('Checkout session expired'));
						$this->markOrderFailed($orderId, $reason);
						return ['success' => true, 'status' => 'failed', 'status_message' => $reason];
					}
					return ['success' => true, 'status' => $stripeStatus];
				}
				if (!empty($query['error'])) {
					$this->markOrderFailed($orderId, (string)$query['error']);
					return [
						'success' => false,
						'error' => (string)$query['error'],
						'status' => 'failed',
						'status_message' => $payment->getStatusMessage(),
					];
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
						$reason = (string)($confirm['error'] ?? $this->l->t('PayPal capture failed'));
						$this->markOrderFailed($orderId, $reason);
						return [
							'success' => false,
							'error' => $reason,
							'status' => 'failed',
							'status_message' => $payment->getStatusMessage(),
						];
					}
					return $this->paidStatusResponse($orderId);
				}
				if ($query['success'] ?? false) {
					$ppStatus = (string)($query['status'] ?? 'pending');
					if ($ppStatus === 'cancelled') {
						$reason = (string)($query['status_message'] ?? $this->l->t('Buyer cancelled payment'));
						$this->markOrderCancelled($orderId, $reason);
						return ['success' => true, 'status' => 'cancelled', 'status_message' => $reason];
					}
					if ($ppStatus === 'failed') {
						$reason = (string)($query['status_message'] ?? $this->l->t('Payment failed'));
						$this->markOrderFailed($orderId, $reason);
						return ['success' => true, 'status' => 'failed', 'status_message' => $reason];
					}
					return ['success' => true, 'status' => $ppStatus];
				}
				if (!empty($query['error'])) {
					$this->markOrderFailed($orderId, (string)$query['error']);
					return [
						'success' => false,
						'error' => (string)$query['error'],
						'status' => 'failed',
						'status_message' => $payment->getStatusMessage(),
					];
				}
			}
		}

		return [
			'success' => true,
			'status' => $payment->getStatus(),
			'status_message' => $payment->getStatusMessage(),
		];
	}

	public function markOrderCancelled(string $orderId, string $reason): void {
		$this->updateOrderTerminalStatus($orderId, 'cancelled', $reason);
	}

	public function markOrderFailed(string $orderId, string $reason): void {
		$this->updateOrderTerminalStatus($orderId, 'failed', $reason);
	}

	public function markOrderRefunded(string $orderId, string $reason): void {
		try {
			$payment = $this->paymentMapper->findByOrderId($orderId);
		} catch (DoesNotExistException) {
			return;
		}
		if ($payment->getStatus() !== 'paid') {
			return;
		}
		$payment->setStatus('refunded');
		$payment->setStatusMessage($this->truncateMessage($reason));
		$payment->setRefundedAt((int)(microtime(true) * 1000));
		try {
			$this->paymentMapper->update($payment);
		} catch (Exception) {
			// ignore
		}
	}

	private function updateOrderTerminalStatus(string $orderId, string $status, string $reason): void {
		try {
			$payment = $this->paymentMapper->findByOrderId($orderId);
		} catch (DoesNotExistException) {
			return;
		}
		if ($payment->getStatus() !== 'pending') {
			return;
		}
		$payment->setStatus($status);
		$payment->setStatusMessage($this->truncateMessage($reason));
		try {
			$this->paymentMapper->update($payment);
		} catch (Exception) {
			// ignore
		}
	}

	private function truncateMessage(string $message): string {
		$message = trim($message);
		if (strlen($message) <= 2000) {
			return $message;
		}
		return substr($message, 0, 1997) . '...';
	}

	private function isShareExpired(?int $expireAt): bool {
		return $expireAt !== null && $expireAt < (int)(microtime(true) * 1000);
	}

	/**
	 * Backfill access grants and payer identity for a paid order (ledger / repair).
	 */
	public function repairPaidOrderGrants(Payment $payment): void {
		if ($payment->getStatus() !== 'paid') {
			return;
		}
		try {
			$this->accessGrantMapper->linkOrphanGrantsForPayment($payment);
		} catch (Exception) {
			// ignore
		}
		$this->ensureAccessGrantsForPayment($payment);
	}

	private function ensureAccessGrantsForPayment(Payment $payment): void {
		if ($payment->getStatus() !== 'paid') {
			return;
		}

		try {
			$share = $this->shareMapper->findByShareId($payment->getShareId());
		} catch (DoesNotExistException|Exception) {
			return;
		}

		$paidAt = $payment->getPaidAt() ?? (int)(microtime(true) * 1000);
		$sessionUserId = trim((string)($payment->getClientUserId() ?? ''));
		$payerGrantId = $this->payerUserIdForPayment($payment);
		if (!$this->isUsefulPayerGrantId($payerGrantId)) {
			$fromProvider = $this->resolvePayerIdFromProvider($payment);
			if ($fromProvider !== '') {
				$payerGrantId = $fromProvider;
			}
		}

		try {
			$this->issueAccessGrantsAfterPayment(
				$payment,
				$share,
				$payerGrantId !== '' ? $payerGrantId : $sessionUserId,
				$sessionUserId,
				$paidAt,
			);
			if ($payment->getProvider() === AlipayF2fProvider::NAME) {
				$query = $this->alipayProvider->queryOrder($payment->getOrderId());
				if ($query['success'] ?? false) {
					$this->issueSupplementalAccessGrants(
						$payment,
						$share,
						$payerGrantId,
						$sessionUserId,
						$paidAt,
						$this->supplementalAlipayGrantIds($query),
					);
				}
			}
			$this->ensureAlipayUidGrantFromProvider($payment, $share, $paidAt);
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

		$this->ensureAccessGrantsForPayment($payment);
		try {
			return $this->accessGrantMapper->hasActiveGrant($shareId, $providerUserId);
		} catch (Exception) {
			return false;
		}
	}

	/**
	 * @throws Exception
	 */
	private function issueAccessGrantsAfterPayment(
		Payment $payment,
		Share $share,
		string $payerGrantId,
		string $sessionUserId,
		int $anchorMs,
	): void {
		if ($payerGrantId !== '') {
			$this->insertAccessGrantIfMissing($payment, $share, $payerGrantId, $anchorMs);
		}
		if ($sessionUserId !== '' && $sessionUserId !== $payerGrantId) {
			$this->insertAccessGrantIfMissing($payment, $share, $sessionUserId, $anchorMs);
		}
	}

	/**
	 * @param list<string> $supplementalGrantUserIds
	 * @throws Exception
	 */
	private function issueSupplementalAccessGrants(
		Payment $payment,
		Share $share,
		string $payerGrantId,
		string $sessionUserId,
		int $anchorMs,
		array $supplementalGrantUserIds,
	): void {
		foreach ($supplementalGrantUserIds as $grantUserId) {
			$grantUserId = trim((string)$grantUserId);
			if ($grantUserId === ''
				|| $grantUserId === $payerGrantId
				|| $grantUserId === $sessionUserId
				|| $this->isPlaceholderPayer($grantUserId)) {
				continue;
			}
			$this->insertAccessGrantIfMissing($payment, $share, $grantUserId, $anchorMs);
		}
	}

	/**
	 * Backfill Alipay UID grant for older paid orders (API never returns full logon).
	 *
	 * @throws Exception
	 */
	private function ensureAlipayUidGrantFromProvider(Payment $payment, Share $share, int $anchorMs): void {
		if ($payment->getProvider() !== AlipayF2fProvider::NAME || $payment->getStatus() !== 'paid') {
			return;
		}
		try {
			$grants = $this->accessGrantMapper->findByPaymentId((int)$payment->getId());
		} catch (Exception) {
			return;
		}
		$hasUid = false;
		$hasMasked = false;
		foreach ($grants as $grant) {
			$id = trim($grant->getProviderUserId());
			if (BuyerAccount::isAlipayUid($id)) {
				$hasUid = true;
			}
			if (BuyerAccount::isMaskedAlipayLogon($id)) {
				$hasMasked = true;
			}
		}
		if ($hasUid && $hasMasked) {
			return;
		}
		$query = $this->alipayProvider->queryOrder($payment->getOrderId());
		if (!($query['success'] ?? false)) {
			return;
		}
		if (!$hasUid) {
			$uid = trim((string)($query['alipay_uid'] ?? ''));
			if (BuyerAccount::isAlipayUid($uid)) {
				$this->insertAccessGrantIfMissing($payment, $share, $uid, $anchorMs);
			}
		}
		if (!$hasMasked) {
			$logon = trim((string)($query['payer_user_id'] ?? ''));
			if ($logon !== '' && BuyerAccount::isMaskedAlipayLogon($logon)) {
				$this->insertAccessGrantIfMissing($payment, $share, $logon, $anchorMs);
			}
		}
	}

	/**
	 * @param array<string, mixed> $alipayPayload
	 * @return list<string>
	 */
	private function supplementalAlipayGrantIds(array $alipayPayload): array {
		$uid = trim((string)($alipayPayload['alipay_uid'] ?? ''));
		return BuyerAccount::isAlipayUid($uid) ? [$uid] : [];
	}

	/**
	 * @throws Exception
	 */
	private function insertAccessGrantIfMissing(
		Payment $payment,
		Share $share,
		string $grantUserId,
		int $anchorMs,
	): void {
		if ($grantUserId === '' || $this->isPlaceholderPayer($grantUserId)) {
			return;
		}
		$paymentId = (int)$payment->getId();
		try {
			foreach ($this->accessGrantMapper->findByPaymentId($paymentId) as $grant) {
				if ($grant->getProviderUserId() === $grantUserId) {
					return;
				}
			}
		} catch (Exception) {
			// continue to insert
		}
		$this->insertAccessGrant($payment, $share, $grantUserId, $anchorMs);
	}

	private function isUsefulPayerGrantId(string $id): bool {
		$id = trim($id);
		return $id !== ''
			&& !BuyerAccount::isBrowserSessionId($id)
			&& !BuyerAccount::isPlaceholderPayer($id);
	}

	private function resolvePayerIdFromProvider(Payment $payment): string {
		if ($payment->getStatus() !== 'paid') {
			return '';
		}
		return match ($payment->getProvider()) {
			AlipayF2fProvider::NAME => $this->resolveAlipayPayerFromProvider($payment),
			StripeProvider::NAME => $this->resolveStripePayerFromProvider($payment),
			PayPalProvider::NAME => $this->resolvePaypalPayerFromProvider($payment),
			default => '',
		};
	}

	private function resolveAlipayPayerFromProvider(Payment $payment): string {
		$query = $this->alipayProvider->queryOrder($payment->getOrderId());
		if (!($query['success'] ?? false)) {
			return '';
		}
		$logon = trim((string)($query['payer_user_id'] ?? ''));
		if ($logon !== '' && !BuyerAccount::isPlaceholderPayer($logon)) {
			return $logon;
		}
		$uid = trim((string)($query['alipay_uid'] ?? ''));
		return BuyerAccount::isAlipayUid($uid) ? $uid : '';
	}

	private function resolveStripePayerFromProvider(Payment $payment): string {
		$sessionId = trim((string)($payment->getProviderOrderId() ?? ''));
		if ($sessionId === '') {
			return '';
		}
		$query = $this->stripeProvider->querySession($sessionId);
		if (!($query['success'] ?? false)) {
			return '';
		}
		$payer = trim((string)($query['payer_user_id'] ?? ''));
		return ($payer !== '' && !BuyerAccount::isPlaceholderPayer($payer)) ? $payer : '';
	}

	private function resolvePaypalPayerFromProvider(Payment $payment): string {
		$paypalOrderId = trim((string)($payment->getProviderOrderId() ?? ''));
		if ($paypalOrderId === '') {
			return '';
		}
		$query = $this->paypalProvider->queryOrder($paypalOrderId);
		if (!($query['success'] ?? false)) {
			return '';
		}
		$payer = trim((string)($query['payer_user_id'] ?? ''));
		return ($payer !== '' && !BuyerAccount::isPlaceholderPayer($payer)) ? $payer : '';
	}

	private function ensureAccessGrant(Payment $payment): void {
		$this->ensureAccessGrantsForPayment($payment);
	}

	private function payerUserIdForPayment(Payment $payment): string {
		try {
			$grants = $this->accessGrantMapper->findByPaymentId((int)$payment->getId());
		} catch (Exception) {
			$grants = [];
		}
		$grantHolderIds = array_map(static fn (AccessGrant $grant) => $grant->getProviderUserId(), $grants);
		return BuyerAccount::resolvePayerId($grantHolderIds, $payment->getClientUserId());
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

	private function resolveGrantUserId(Payment $payment, string $payerUserId): string {
		$payer = trim($payerUserId);
		if ($payer !== '' && !$this->isPlaceholderPayer($payer)) {
			return $payer;
		}
		$client = trim((string)($payment->getClientUserId() ?? ''));
		if ($client !== '' && !$this->isPlaceholderPayer($client)) {
			return $client;
		}
		if ($client !== '') {
			return $client;
		}
		return 'buyer_' . $payment->getOrderId();
	}

	private function isPlaceholderPayer(string $id): bool {
		return BuyerAccount::isPlaceholderPayer($id);
	}

	/**
	 * @return array{success: true, status: string, payer_user_id?: string, status_message?: string}
	 */
	private function paidStatusResponse(string $orderId): array {
		try {
			$payment = $this->paymentMapper->findByOrderId($orderId);
		} catch (DoesNotExistException) {
			return ['success' => true, 'status' => 'paid'];
		}
		$this->ensureAccessGrantsForPayment($payment);
		$payer = $this->payerUserIdForPayment($payment);
		$response = ['success' => true, 'status' => 'paid'];
		if ($payer !== '') {
			$response['payer_user_id'] = $payer;
		}
		return $this->appendAccessTokenFields($response, $payment);
	}

	/**
	 * @param array<string, mixed> $response
	 * @return array<string, mixed>
	 */
	private function appendAccessTokenFields(array $response, Payment $payment): array {
		if ($payment->getStatus() !== 'paid') {
			return $response;
		}

		$payerId = trim((string)($response['payer_user_id'] ?? $this->payerUserIdForPayment($payment)));
		if ($payerId === '' || $this->isPlaceholderPayer($payerId)) {
			return $response;
		}

		try {
			$grant = $this->accessGrantMapper->findActive($payment->getShareId(), $payerId);
			$expiresAt = (int)($grant->getExpiresAt() ?? 0);
		} catch (DoesNotExistException|Exception) {
			return $response;
		}

		if ($expiresAt <= (int)(microtime(true) * 1000)) {
			return $response;
		}

		$token = $this->accessTokenService->create($payment->getShareId(), $payerId, $expiresAt);
		if ($token === '') {
			return $response;
		}

		$response['access_token'] = $token;
		$response['cross_device_url'] = $this->accessTokenService->buildCrossDeviceViewUrl(
			$payment->getShareId(),
			$token,
		);

		$purchasesToken = $this->purchasesTokenService->createForPayer($payerId);
		if ($purchasesToken !== '') {
			$response['purchases_token'] = $purchasesToken;
			$response['purchases_url'] = $this->purchasesTokenService->buildPurchasesPageUrl($purchasesToken);
		}

		return $response;
	}
}
