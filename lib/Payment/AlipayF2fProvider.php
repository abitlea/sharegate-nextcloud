<?php

declare(strict_types=1);

namespace OCA\ShareGate\Payment;

use OCA\ShareGate\Service\PaymentConfigService;
use OCP\IL10N;

/**
 * 支付宝当面付 — 移植自 monorepo payments/alipay-f2f（PHP EasySDK）
 */
class AlipayF2fProvider {
	public const NAME = 'alipay_f2f';

	public function __construct(
		private PaymentConfigService $configService,
		private IL10N $l,
	) {
	}

	public function isAvailable(): bool {
		return class_exists(\Alipay\EasySDK\Kernel\Factory::class)
			&& $this->configService->isAlipayConfigured();
	}

	/**
	 * @return array{success: true, qr_code: string, provider_order_id?: string}|array{success: false, error: string}
	 */
	public function createPayment(string $orderId, string $title, int $amountCents): array {
		if (!class_exists(\Alipay\EasySDK\Kernel\Factory::class)) {
			return [
				'success' => false,
				'error' => $this->l->t('Alipay EasySDK not loaded. Run composer install in apps/sharegate.'),
			];
		}
		if (!$this->configService->isAlipayConfigured()) {
			return [
				'success' => false,
				'error' => $this->l->t('Alipay is not fully configured. Set App ID, application private key, and Alipay public key, then select Alipay Face-to-Face.'),
			];
		}

		$cfg = $this->configService->getAlipayF2fConfig();

		try {
			$this->applySdkOptions();
			$amountYuan = number_format($amountCents / 100, 2, '.', '');
			$result = \Alipay\EasySDK\Kernel\Factory::payment()->faceToFace()->preCreate(
				$title !== '' ? $title : $this->l->t('ShareGate file download'),
				$orderId,
				$amountYuan,
			);

			if ((string)$result->code === '10000' && !empty($result->qrCode)) {
				return [
					'success' => true,
					'qr_code' => (string)$result->qrCode,
					'provider_order_id' => (string)($result->outTradeNo ?? ''),
				];
			}

			return [
				'success' => false,
				'error' => (string)($result->subMsg ?? $result->msg ?? $this->l->t('Alipay pre-create failed')),
			];
		} catch (\Throwable $e) {
			$msg = $e->getMessage();
			if (str_contains($msg, '验签失败') || str_contains(strtolower($msg), 'verify signature')) {
				$envLabel = ($cfg['sandbox'] ?? true)
					? $this->l->t('sandbox')
					: $this->l->t('production');
				$msg .= ' ' . $this->l->t(
					'Check: ① Alipay public key from the open platform (not the app public key); ② App ID, private key, and public key from the same %s environment; ③ RSA2 private key matching the registered app public key.',
					[$envLabel],
				);
			}
			return ['success' => false, 'error' => $this->l->t('Alipay request error: %s', [$msg])];
		}
	}

	/**
	 * @param array<string, string> $params
	 * @return array{success: true, order_id: string, provider_order_id: string, payer_user_id: string}|array{success: false, error: string}
	 */
	public function verifyCallback(array $params): array {
		if (!$this->isAvailable()) {
			return ['success' => false, 'error' => $this->l->t('Alipay not configured')];
		}

		try {
			$this->applySdkOptions();
			$verified = \Alipay\EasySDK\Kernel\Factory::payment()->common()->verifyNotify($params);
			if (!$verified) {
				return ['success' => false, 'error' => $this->l->t('Alipay callback signature verification failed')];
			}

			$tradeStatus = (string)($params['trade_status'] ?? '');
			if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
				return ['success' => false, 'error' => $this->l->t('Invalid trade status: %s', [$tradeStatus])];
			}

			$orderId = (string)($params['out_trade_no'] ?? '');
			if ($orderId === '') {
				return ['success' => false, 'error' => $this->l->t('Missing out_trade_no')];
			}

			return [
				'success' => true,
				'order_id' => $orderId,
				'provider_order_id' => (string)($params['trade_no'] ?? ''),
				'payer_user_id' => (string)($params['buyer_logon_id'] ?? $params['buyer_id'] ?? 'alipay_user'),
				'alipay_uid' => trim((string)($params['buyer_id'] ?? $params['buyer_user_id'] ?? '')),
			];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $this->l->t('Callback verification error: %s', [$e->getMessage()])];
		}
	}

	/**
	 * @return array{success: true, status: string, provider_order_id?: string}|array{success: false, error: string}
	 */
	public function queryOrder(string $orderId): array {
		if (!$this->isAvailable()) {
			return ['success' => false, 'error' => $this->l->t('Alipay not configured')];
		}

		try {
			$this->applySdkOptions();
			$result = \Alipay\EasySDK\Kernel\Factory::payment()->common()->query($orderId);
			if ((string)$result->code !== '10000') {
				return ['success' => false, 'error' => (string)($result->subMsg ?? $result->msg ?? $this->l->t('Query failed'))];
			}

			$map = [
				'WAIT_BUYER_PAY' => 'pending',
				'TRADE_CLOSED' => 'closed',
				'TRADE_SUCCESS' => 'paid',
				'TRADE_FINISHED' => 'paid',
			];
			$tradeStatus = (string)($result->tradeStatus ?? '');

			return [
				'success' => true,
				'status' => $map[$tradeStatus] ?? 'pending',
				'provider_order_id' => (string)($result->tradeNo ?? ''),
				'payer_user_id' => (string)($result->buyerLogonId ?? 'alipay_user'),
				'alipay_uid' => trim((string)($result->buyerUserId ?? $result->buyerId ?? '')),
			];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	private function applySdkOptions(): void {
		$cfg = $this->configService->getAlipayF2fConfig();
		$options = new \Alipay\EasySDK\Kernel\Config();
		$options->protocol = 'https';
		$options->gatewayHost = $cfg['sandbox']
			? 'openapi-sandbox.dl.alipaydev.com'
			: 'openapi.alipay.com';
		$options->signType = 'RSA2';
		$options->appId = $cfg['app_id'];
		$options->merchantPrivateKey = $this->normalizeKey($cfg['private_key']);
		$options->alipayPublicKey = $this->normalizeKey($cfg['alipay_public_key']);
		$options->notifyUrl = $cfg['notify_url'];
		\Alipay\EasySDK\Kernel\Factory::setOptions($options);
	}

	private function normalizeKey(string $key): string {
		$key = trim($key);
		if ($key === '') {
			return '';
		}
		$key = preg_replace('/-----BEGIN[A-Z ]*-----/i', '', $key) ?? $key;
		$key = preg_replace('/-----END[A-Z ]*-----/i', '', $key) ?? $key;
		return preg_replace('/\s+/', '', $key) ?? $key;
	}
}
