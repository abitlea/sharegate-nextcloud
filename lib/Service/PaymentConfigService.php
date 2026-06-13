<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Payment\AlipayF2fProvider;
use OCA\ShareGate\Payment\MockPaymentProvider;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * 支付配置读写（NC IConfig，对齐 monorepo sharegate.config.json payment 段）
 */
class PaymentConfigService {
	public const MODE_MOCK = 'mock';
	public const MODE_ALIPAY_F2F = 'alipay_f2f';

	private const KEY_MODE = 'payment_mode';
	private const KEY_APP_ID = 'alipay_f2f_app_id';
	private const KEY_PRIVATE_KEY = 'alipay_f2f_private_key';
	private const KEY_PUBLIC_KEY = 'alipay_f2f_public_key';
	private const KEY_SANDBOX = 'alipay_f2f_sandbox';
	private const KEY_NOTIFY_BASE = 'alipay_f2f_notify_url_base';

	public function __construct(
		private IConfig $config,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getPaymentMode(): string {
		$mode = $this->config->getAppValue('sharegate', self::KEY_MODE, self::MODE_MOCK);
		return in_array($mode, [self::MODE_MOCK, self::MODE_ALIPAY_F2F], true)
			? $mode
			: self::MODE_MOCK;
	}

	public function setPaymentMode(string $mode): void {
		if (!in_array($mode, [self::MODE_MOCK, self::MODE_ALIPAY_F2F], true)) {
			$mode = self::MODE_MOCK;
		}
		$this->config->setAppValue('sharegate', self::KEY_MODE, $mode);
	}

	/**
	 * @return array{
	 *   app_id: string,
	 *   private_key: string,
	 *   alipay_public_key: string,
	 *   sandbox: bool,
	 *   notify_url_base: string,
	 *   notify_url: string,
	 *   configured: bool
	 * }
	 */
	public function getAlipayF2fConfig(): array {
		$appId = $this->config->getAppValue('sharegate', self::KEY_APP_ID, '');
		$privateKey = $this->config->getAppValue('sharegate', self::KEY_PRIVATE_KEY, '');
		$publicKey = $this->config->getAppValue('sharegate', self::KEY_PUBLIC_KEY, '');
		$sandbox = $this->config->getAppValue('sharegate', self::KEY_SANDBOX, 'yes') !== 'no';
		$notifyBase = trim($this->config->getAppValue('sharegate', self::KEY_NOTIFY_BASE, ''));

		if ($notifyBase === '') {
			$notifyBase = rtrim($this->urlGenerator->getAbsoluteURL(''), '/');
		}

		$notifyUrl = $this->urlGenerator->linkToRouteAbsolute('sharegate.payment.notifyAlipay');

		return [
			'app_id' => $appId,
			'private_key' => $this->normalizeAlipayKey($privateKey),
			'alipay_public_key' => $this->normalizeAlipayKey($publicKey),
			'sandbox' => $sandbox,
			'notify_url_base' => $notifyBase,
			'notify_url' => $notifyUrl,
			'configured' => $appId !== '' && $privateKey !== '',
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function saveAlipayF2fConfig(array $data): void {
		$this->config->setAppValue('sharegate', self::KEY_APP_ID, trim((string)($data['app_id'] ?? '')));
		$this->config->setAppValue('sharegate', self::KEY_PRIVATE_KEY, $this->normalizeAlipayKey((string)($data['private_key'] ?? '')));
		$this->config->setAppValue('sharegate', self::KEY_PUBLIC_KEY, $this->normalizeAlipayKey((string)($data['alipay_public_key'] ?? '')));
		$sandbox = ($data['sandbox'] ?? true) ? 'yes' : 'no';
		$this->config->setAppValue('sharegate', self::KEY_SANDBOX, $sandbox);
		$this->config->setAppValue(
			'sharegate',
			self::KEY_NOTIFY_BASE,
			trim((string)($data['notify_url_base'] ?? '')),
		);
	}

	public function getActiveProviderName(): string {
		$mode = $this->getPaymentMode();
		if ($mode === self::MODE_ALIPAY_F2F && $this->isAlipayConfigured()) {
			return AlipayF2fProvider::NAME;
		}
		return MockPaymentProvider::NAME;
	}

	public function isAlipayConfigured(): bool {
		$cfg = $this->getAlipayF2fConfig();
		return $cfg['configured'] && $cfg['alipay_public_key'] !== '';
	}

	public function shouldUseMock(): bool {
		return $this->getActiveProviderName() === MockPaymentProvider::NAME;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getAdminSummary(): array {
		$mode = $this->getPaymentMode();
		$alipay = $this->getAlipayF2fConfig();
		return [
			'payment_mode' => $mode,
			'effective_provider' => $this->getActiveProviderName(),
			'alipay_f2f' => [
				'app_id' => $alipay['app_id'],
				'private_key' => $alipay['private_key'],
				'alipay_public_key' => $alipay['alipay_public_key'],
				'sandbox' => $alipay['sandbox'],
				'notify_url_base' => $alipay['notify_url_base'],
				'notify_url' => $alipay['notify_url'],
				'configured' => $alipay['configured'],
			],
		];
	}

	private function normalizeAlipayKey(string $key): string {
		$key = trim($key);
		if ($key === '') {
			return '';
		}
		$key = preg_replace('/-----BEGIN[A-Z ]*-----/i', '', $key) ?? $key;
		$key = preg_replace('/-----END[A-Z ]*-----/i', '', $key) ?? $key;
		return preg_replace('/\s+/', '', $key) ?? $key;
	}
}
