<?php

declare(strict_types=1);

namespace OCA\ShareGate\Settings;

use OCA\ShareGate\AppInfo\Application;
use OCA\ShareGate\Util\CspNonce;
use OCA\ShareGate\Service\PaymentConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private PaymentConfigService $paymentConfig,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addTranslations(Application::APP_ID);
		Util::addStyle(Application::APP_ID, 'admin-settings');
		Util::addScript(Application::APP_ID, 'admin-settings');

		$summary = $this->paymentConfig->getAdminSummary();

		return new TemplateResponse(Application::APP_ID, 'settings/admin', [
			'payment_mode' => $summary['payment_mode'],
			'effective_provider' => $summary['effective_provider'],
			'alipay' => $summary['alipay_f2f'],
			'save_url' => $this->urlGenerator->linkToRoute('sharegate.admin.savePaymentConfig'),
			'config_url' => $this->urlGenerator->linkToRoute('sharegate.admin.paymentConfig'),
			'csp_nonce' => CspNonce::get(),
		]);
	}

	public function getSection(): string {
		return 'sharegate';
	}

	public function getPriority(): int {
		return 50;
	}
}
