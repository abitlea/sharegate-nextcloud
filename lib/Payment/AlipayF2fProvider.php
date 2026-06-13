<?php

declare(strict_types=1);

namespace OCA\ShareGate\Payment;

use OCA\ShareGate\Service\PaymentConfigService;

/**
 * 支付宝当面付 — 移植自 monorepo payments/alipay-f2f（PHP EasySDK）
 */
class AlipayF2fProvider {
	public const NAME = 'alipay_f2f';

	public function __construct(
		private PaymentConfigService $configService,
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
				'error' => '支付宝 EasySDK 未加载，请在 apps/sharegate 目录执行 composer install --no-dev',
			];
		}
		if (!$this->configService->isAlipayConfigured()) {
			return [
				'success' => false,
				'error' => '支付宝未配置完整，请在管理台「账户绑定」填写 AppID、应用私钥和支付宝公钥，并选择支付宝当面付',
			];
		}

		$cfg = $this->configService->getAlipayF2fConfig();

		try {
			$this->applySdkOptions();
			$amountYuan = number_format($amountCents / 100, 2, '.', '');
			$result = \Alipay\EasySDK\Kernel\Factory::payment()->faceToFace()->preCreate(
				$title !== '' ? $title : 'ShareGate 文件下载',
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
				'error' => (string)($result->subMsg ?? $result->msg ?? '支付宝预创建失败'),
			];
		} catch (\Throwable $e) {
			$msg = $e->getMessage();
			if (str_contains($msg, '验签失败')) {
				$env = ($cfg['sandbox'] ?? true) ? '沙箱' : '生产';
				$msg .= '。请检查：①「支付宝公钥」须从开放平台复制（不是应用公钥）；② AppID、私钥、公钥均来自同一' . $env . '环境；③ 私钥为 RSA2，与控制台登记的应用公钥配对';
			}
			return ['success' => false, 'error' => '支付宝请求异常: ' . $msg];
		}
	}

	/**
	 * @param array<string, string> $params
	 * @return array{success: true, order_id: string, provider_order_id: string, payer_user_id: string}|array{success: false, error: string}
	 */
	public function verifyCallback(array $params): array {
		if (!$this->isAvailable()) {
			return ['success' => false, 'error' => '支付宝未配置'];
		}

		try {
			$this->applySdkOptions();
			$verified = \Alipay\EasySDK\Kernel\Factory::payment()->common()->verifyNotify($params);
			if (!$verified) {
				return ['success' => false, 'error' => '支付宝回调签名验证失败'];
			}

			$tradeStatus = (string)($params['trade_status'] ?? '');
			if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
				return ['success' => false, 'error' => '交易状态不正确: ' . $tradeStatus];
			}

			$orderId = (string)($params['out_trade_no'] ?? '');
			if ($orderId === '') {
				return ['success' => false, 'error' => '缺少 out_trade_no'];
			}

			return [
				'success' => true,
				'order_id' => $orderId,
				'provider_order_id' => (string)($params['trade_no'] ?? ''),
				'payer_user_id' => (string)($params['buyer_logon_id'] ?? $params['buyer_id'] ?? 'alipay_user'),
			];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => '回调验证异常: ' . $e->getMessage()];
		}
	}

	/**
	 * @return array{success: true, status: string, provider_order_id?: string}|array{success: false, error: string}
	 */
	public function queryOrder(string $orderId): array {
		if (!$this->isAvailable()) {
			return ['success' => false, 'error' => '支付宝未配置'];
		}

		try {
			$this->applySdkOptions();
			$result = \Alipay\EasySDK\Kernel\Factory::payment()->common()->query($orderId);
			if ((string)$result->code !== '10000') {
				return ['success' => false, 'error' => (string)($result->subMsg ?? $result->msg ?? '查询失败')];
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
