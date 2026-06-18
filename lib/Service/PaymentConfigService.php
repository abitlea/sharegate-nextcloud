<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Payment\AlipayF2fProvider;
use OCA\ShareGate\Payment\MockPaymentProvider;
use OCA\ShareGate\Payment\PayPalProvider;
use OCA\ShareGate\Payment\PaymentProviderCatalog;
use OCA\ShareGate\Payment\StripeProvider;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * 支付配置读写（NC IConfig，对齐 monorepo sharegate.config.json payment 段）
 */
class PaymentConfigService {
	public const MODE_MOCK = 'mock';
	public const MODE_ALIPAY_F2F = 'alipay_f2f';
	public const MODE_STRIPE = 'stripe';
	public const MODE_PAYPAL = 'paypal';

	private const VALID_MODES = [
		self::MODE_MOCK,
		self::MODE_ALIPAY_F2F,
		self::MODE_STRIPE,
		self::MODE_PAYPAL,
	];

	private const KEY_MODE = 'payment_mode';
	private const KEY_APP_ID = 'alipay_f2f_app_id';
	private const KEY_PRIVATE_KEY = 'alipay_f2f_private_key';
	private const KEY_PUBLIC_KEY = 'alipay_f2f_public_key';
	private const KEY_SANDBOX = 'alipay_f2f_sandbox';
	private const KEY_NOTIFY_BASE = 'alipay_f2f_notify_url_base';
	private const KEY_STRIPE_SECRET = 'stripe_secret_key';
	private const KEY_STRIPE_WEBHOOK = 'stripe_webhook_secret';
	private const KEY_STRIPE_CURRENCY = 'stripe_currency';
	private const KEY_PAYPAL_CLIENT_ID = 'paypal_client_id';
	private const KEY_PAYPAL_CLIENT_SECRET = 'paypal_client_secret';
	private const KEY_PAYPAL_SANDBOX = 'paypal_sandbox';
	private const KEY_PAYPAL_WEBHOOK_ID = 'paypal_webhook_id';
	private const KEY_PAYPAL_CURRENCY = 'paypal_currency';

	/** @var list<string> */
	private const INTERNATIONAL_CURRENCIES = [
		'usd', 'eur', 'gbp', 'cad', 'aud', 'chf', 'sgd', 'hkd', 'nzd', 'jpy',
	];

	public function __construct(
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	public function getPaymentMode(): string {
		$mode = $this->config->getAppValue('sharegate', self::KEY_MODE, self::MODE_ALIPAY_F2F);
		return in_array($mode, self::VALID_MODES, true) ? $mode : self::MODE_ALIPAY_F2F;
	}

	public function setPaymentMode(string $mode): void {
		if (!in_array($mode, self::VALID_MODES, true)) {
			$mode = self::MODE_ALIPAY_F2F;
		}
		$this->config->setAppValue('sharegate', self::KEY_MODE, $mode);
	}

	/** 公网/生产站点（非 localhost、非公网 debug） */
	public function isProductionSite(): bool {
		if ($this->config->getSystemValueBool('debug', false)) {
			return false;
		}
		if ($this->config->getAppValue('sharegate', 'allow_mock_payment', 'no') === 'yes') {
			return false;
		}
		$host = parse_url($this->urlGenerator->getAbsoluteURL(''), PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return true;
		}
		$host = strtolower($host);
		if (in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
			return false;
		}
		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$long = ip2long($host);
			if ($long !== false) {
				if (($long & 0xFF000000) === 0x0A000000) {
					return false;
				}
				if (($long & 0xFFF00000) === 0xAC100000) {
					return false;
				}
				if (($long & 0xFFFF0000) === 0xC0A80000) {
					return false;
				}
			}
		}
		return true;
	}

	public function isMockSelectableInAdmin(): bool {
		return !$this->isProductionSite();
	}

	public function validatePaymentModeSave(string $mode): ?string {
		if ($mode === self::MODE_MOCK && !$this->isMockSelectableInAdmin()) {
			return $this->l->t(
				'Mock payment is disabled on production sites. Configure Alipay, Stripe, or PayPal.',
			);
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	public function validatePaymentConfigSave(string $mode, array $body): ?string {
		$modeError = $this->validatePaymentModeSave($mode);
		if ($modeError !== null) {
			return $modeError;
		}

		return match ($mode) {
			self::MODE_PAYPAL => $this->validatePaypalConfigPayload(
				is_array($body['paypal'] ?? null) ? $body['paypal'] : [],
			),
			self::MODE_STRIPE => $this->validateStripeConfigPayload(
				is_array($body['stripe'] ?? null) ? $body['stripe'] : [],
			),
			self::MODE_ALIPAY_F2F => $this->validateAlipayConfigPayload(
				is_array($body['alipay_f2f'] ?? null) ? $body['alipay_f2f'] : [],
			),
			default => null,
		};
	}

	public function getMockProductionWarning(): ?string {
		return $this->getConfigurationWarning();
	}

	public function getConfigurationWarning(): ?string {
		$mode = $this->getPaymentMode();
		if ($mode === self::MODE_MOCK) {
			if ($this->isProductionSite()) {
				return $this->l->t(
					'Mock payment is active. Buyers are not charged real money. Switch to a live payment provider.',
				);
			}
			return null;
		}

		return match ($mode) {
			self::MODE_PAYPAL => $this->isPaypalConfigured()
				? null
				: $this->l->t(
					'PayPal is not configured. Set Client ID and Client Secret, then select PayPal.',
				),
			self::MODE_STRIPE => $this->isStripeConfigured()
				? null
				: $this->l->t(
					'Stripe is not configured. Set the secret key and webhook secret, then select Stripe.',
				),
			self::MODE_ALIPAY_F2F => $this->isAlipayConfigured()
				? null
				: $this->l->t(
					'Alipay is not fully configured. Set App ID, application private key, and Alipay public key, then select Alipay Face-to-Face.',
				),
			default => null,
		};
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function validatePaypalConfigPayload(array $data): ?string {
		$clientId = trim((string)($data['client_id'] ?? ''));
		$clientSecret = trim((string)($data['client_secret'] ?? ''));
		if ($clientId === '' || $clientSecret === '') {
			return $this->l->t(
				'PayPal is not configured. Set Client ID and Client Secret, then select PayPal.',
			);
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function validateStripeConfigPayload(array $data): ?string {
		$secretKey = trim((string)($data['secret_key'] ?? ''));
		$webhookSecret = trim((string)($data['webhook_secret'] ?? ''));
		if ($secretKey === '' || $webhookSecret === '') {
			return $this->l->t(
				'Stripe is not configured. Set the secret key and webhook secret, then select Stripe.',
			);
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function validateAlipayConfigPayload(array $data): ?string {
		$appId = trim((string)($data['app_id'] ?? ''));
		$privateKey = trim((string)($data['private_key'] ?? ''));
		$publicKey = trim((string)($data['alipay_public_key'] ?? ''));
		if ($appId === '' || $privateKey === '' || $publicKey === '') {
			return $this->l->t(
				'Alipay is not fully configured. Set App ID, application private key, and Alipay public key, then select Alipay Face-to-Face.',
			);
		}
		return null;
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
		$sandboxDefault = $this->isProductionSite() ? 'no' : 'yes';
		$sandbox = $this->config->getAppValue('sharegate', self::KEY_SANDBOX, $sandboxDefault) !== 'no';
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
	 * @return array{
	 *   secret_key: string,
	 *   webhook_secret: string,
	 *   currency: string,
	 *   webhook_url: string,
	 *   configured: bool
	 * }
	 */
	public function getStripeConfig(): array {
		$secretKey = trim($this->config->getAppValue('sharegate', self::KEY_STRIPE_SECRET, ''));
		$webhookSecret = trim($this->config->getAppValue('sharegate', self::KEY_STRIPE_WEBHOOK, ''));
		$currency = strtolower(trim($this->config->getAppValue('sharegate', self::KEY_STRIPE_CURRENCY, 'usd')));
		if (!in_array($currency, self::INTERNATIONAL_CURRENCIES, true)) {
			$currency = 'usd';
		}

		return [
			'secret_key' => $secretKey,
			'webhook_secret' => $webhookSecret,
			'currency' => $currency,
			'webhook_url' => $this->urlGenerator->linkToRouteAbsolute('sharegate.payment.notifyStripe'),
			'configured' => $secretKey !== '' && $webhookSecret !== '',
		];
	}

	/**
	 * @return array{
	 *   client_id: string,
	 *   client_secret: string,
	 *   sandbox: bool,
	 *   webhook_id: string,
	 *   currency: string,
	 *   webhook_url: string,
	 *   configured: bool
	 * }
	 */
	public function getPaypalConfig(): array {
		$clientId = trim($this->config->getAppValue('sharegate', self::KEY_PAYPAL_CLIENT_ID, ''));
		$clientSecret = trim($this->config->getAppValue('sharegate', self::KEY_PAYPAL_CLIENT_SECRET, ''));
		$sandboxDefault = $this->isProductionSite() ? 'no' : 'yes';
		$sandbox = $this->config->getAppValue('sharegate', self::KEY_PAYPAL_SANDBOX, $sandboxDefault) !== 'no';
		$webhookId = trim($this->config->getAppValue('sharegate', self::KEY_PAYPAL_WEBHOOK_ID, ''));
		$currency = strtolower(trim($this->config->getAppValue('sharegate', self::KEY_PAYPAL_CURRENCY, 'usd')));
		if (!in_array($currency, self::INTERNATIONAL_CURRENCIES, true)) {
			$currency = 'usd';
		}

		return [
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
			'sandbox' => $sandbox,
			'webhook_id' => $webhookId,
			'currency' => $currency,
			'webhook_url' => $this->urlGenerator->linkToRouteAbsolute('sharegate.payment.notifyPaypal'),
			'configured' => $clientId !== '' && $clientSecret !== '',
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

	/**
	 * @param array<string, mixed> $data
	 */
	public function saveStripeConfig(array $data): void {
		$this->config->setAppValue('sharegate', self::KEY_STRIPE_SECRET, trim((string)($data['secret_key'] ?? '')));
		$this->config->setAppValue('sharegate', self::KEY_STRIPE_WEBHOOK, trim((string)($data['webhook_secret'] ?? '')));
		$currency = strtolower(trim((string)($data['currency'] ?? 'usd')));
		if (!in_array($currency, self::INTERNATIONAL_CURRENCIES, true)) {
			$currency = 'usd';
		}
		$this->config->setAppValue('sharegate', self::KEY_STRIPE_CURRENCY, $currency);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function savePaypalConfig(array $data): void {
		$this->config->setAppValue('sharegate', self::KEY_PAYPAL_CLIENT_ID, trim((string)($data['client_id'] ?? '')));
		$this->config->setAppValue('sharegate', self::KEY_PAYPAL_CLIENT_SECRET, trim((string)($data['client_secret'] ?? '')));
		$this->config->setAppValue('sharegate', self::KEY_PAYPAL_WEBHOOK_ID, trim((string)($data['webhook_id'] ?? '')));
		$sandbox = ($data['sandbox'] ?? true) ? 'yes' : 'no';
		$this->config->setAppValue('sharegate', self::KEY_PAYPAL_SANDBOX, $sandbox);
		$currency = strtolower(trim((string)($data['currency'] ?? 'usd')));
		if (!in_array($currency, self::INTERNATIONAL_CURRENCIES, true)) {
			$currency = 'usd';
		}
		$this->config->setAppValue('sharegate', self::KEY_PAYPAL_CURRENCY, $currency);
	}

	public function getActiveProviderName(): string {
		$mode = $this->getPaymentMode();
		if ($mode === self::MODE_ALIPAY_F2F && $this->isAlipayConfigured()) {
			return AlipayF2fProvider::NAME;
		}
		if ($mode === self::MODE_STRIPE && $this->isStripeConfigured()) {
			return StripeProvider::NAME;
		}
		if ($mode === self::MODE_PAYPAL && $this->isPaypalConfigured()) {
			return PayPalProvider::NAME;
		}
		return MockPaymentProvider::NAME;
	}

	public function isAlipayConfigured(): bool {
		$cfg = $this->getAlipayF2fConfig();
		return $cfg['configured'] && $cfg['alipay_public_key'] !== '';
	}

	public function isStripeConfigured(): bool {
		return $this->getStripeConfig()['configured'];
	}

	public function isPaypalConfigured(): bool {
		return $this->getPaypalConfig()['configured'];
	}

	public function getPaymentFlow(): string {
		$provider = $this->getActiveProviderName();
		return in_array($provider, [StripeProvider::NAME, PayPalProvider::NAME], true)
			? 'redirect'
			: 'qrcode';
	}

	public function formatPrice(int $amountCents): string {
		$mode = $this->getPaymentMode();
		if ($mode === self::MODE_STRIPE && $this->isStripeConfigured()) {
			return $this->formatInternationalPrice($amountCents, $this->getStripeConfig()['currency']);
		}
		if ($mode === self::MODE_PAYPAL && $this->isPaypalConfigured()) {
			return $this->formatInternationalPrice($amountCents, $this->getPaypalConfig()['currency']);
		}
		return '¥' . number_format($amountCents / 100, 2, '.', '');
	}

	public function getDisplayCurrency(): string {
		if ($this->getPaymentMode() === self::MODE_STRIPE && $this->isStripeConfigured()) {
			return strtoupper($this->getStripeConfig()['currency']);
		}
		if ($this->getPaymentMode() === self::MODE_PAYPAL && $this->isPaypalConfigured()) {
			return strtoupper($this->getPaypalConfig()['currency']);
		}
		return 'CNY';
	}

	public function shouldUseMock(): bool {
		return $this->getActiveProviderName() === MockPaymentProvider::NAME;
	}

	public function getProviderLabel(string $providerId): string {
		foreach (PaymentProviderCatalog::all() as $provider) {
			if ($provider['id'] === $providerId) {
				return $this->l->t($provider['label']);
			}
		}
		return $providerId;
	}

	private function isProviderConfigured(string $providerId): bool {
		return match ($providerId) {
			MockPaymentProvider::NAME => true,
			AlipayF2fProvider::NAME => $this->isAlipayConfigured(),
			StripeProvider::NAME => $this->isStripeConfigured(),
			PayPalProvider::NAME => $this->isPaypalConfigured(),
			default => false,
		};
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getProvidersForApi(): array {
		$mockSelectable = $this->isMockSelectableInAdmin();
		$providers = [];
		foreach (PaymentProviderCatalog::all() as $provider) {
			$id = $provider['id'];
			$selectable = $id !== MockPaymentProvider::NAME || $mockSelectable;
			$providers[] = [
				'id' => $id,
				'label' => $this->l->t($provider['label']),
				'description' => $this->l->t($provider['description']),
				'region' => $provider['region'],
				'configured' => $this->isProviderConfigured($id),
				'production' => $id !== MockPaymentProvider::NAME,
				'selectable' => $selectable,
			];
		}
		return $providers;
	}

	public function getAdminSummary(): array {
		$mode = $this->getPaymentMode();
		$alipay = $this->getAlipayF2fConfig();
		$stripe = $this->getStripeConfig();
		$paypal = $this->getPaypalConfig();
		$effectiveProvider = $this->getActiveProviderName();
		return [
			'payment_mode' => $mode,
			'effective_provider' => $effectiveProvider,
			'effective_provider_label' => $this->getProviderLabel($effectiveProvider),
			'payment_flow' => $this->getPaymentFlow(),
			'display_currency' => $this->getDisplayCurrency(),
			'mock_selectable' => $this->isMockSelectableInAdmin(),
			'mock_production_warning' => $this->getMockProductionWarning(),
			'is_production_site' => $this->isProductionSite(),
			'providers' => $this->getProvidersForApi(),
			'alipay_f2f' => [
				'app_id' => $alipay['app_id'],
				'private_key' => $alipay['private_key'],
				'alipay_public_key' => $alipay['alipay_public_key'],
				'sandbox' => $alipay['sandbox'],
				'notify_url_base' => $alipay['notify_url_base'],
				'notify_url' => $alipay['notify_url'],
				'configured' => $alipay['configured'],
			],
			'stripe' => [
				'secret_key' => $stripe['secret_key'],
				'webhook_secret' => $stripe['webhook_secret'],
				'currency' => $stripe['currency'],
				'webhook_url' => $stripe['webhook_url'],
				'configured' => $stripe['configured'],
			],
			'paypal' => [
				'client_id' => $paypal['client_id'],
				'client_secret' => $paypal['client_secret'],
				'sandbox' => $paypal['sandbox'],
				'webhook_id' => $paypal['webhook_id'],
				'currency' => $paypal['currency'],
				'webhook_url' => $paypal['webhook_url'],
				'configured' => $paypal['configured'],
			],
		];
	}

	private function formatInternationalPrice(int $amountCents, string $currency): string {
		$symbols = [
			'usd' => '$', 'eur' => '€', 'gbp' => '£', 'cad' => 'CA$', 'aud' => 'A$',
			'chf' => 'CHF ', 'sgd' => 'S$', 'hkd' => 'HK$', 'nzd' => 'NZ$', 'jpy' => '¥',
		];
		$symbol = $symbols[$currency] ?? strtoupper($currency) . ' ';
		if ($currency === 'jpy') {
			return $symbol . number_format($amountCents, 0, '.', ',');
		}
		return $symbol . number_format($amountCents / 100, 2, '.', ',');
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
