<?php

declare(strict_types=1);

namespace OCA\ShareGate\Controller;

use OCA\ShareGate\AppInfo\Application;
use OCA\ShareGate\Util\CspNonce;
use OCA\ShareGate\Service\DashboardService;
use OCA\ShareGate\Service\PaymentConfigService;
use OCA\ShareGate\Service\PaymentLedgerService;
use OCA\ShareGate\Service\ShareService;
use OCA\ShareGate\Service\ShareStatsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\ISession;
use OCP\Util;

class DashboardController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private DashboardService $dashboardService,
		private ShareService $shareService,
		private PaymentConfigService $paymentConfig,
		private ShareStatsService $shareStatsService,
		private PaymentLedgerService $paymentLedgerService,
		private IGroupManager $groupManager,
		private IURLGenerator $urlGenerator,
		private ISession $session,
		private IFactory $l10nFactory,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addTranslations(Application::APP_ID);
		// Vue 管理台（src/ → js/dashboard.js）
		Util::addStyle(Application::APP_ID, 'dashboard');
		Util::addStyle(Application::APP_ID, 'share-settings');
		Util::addScript(Application::APP_ID, 'dashboard');

		$userId = $this->shareService->getCurrentUserId();
		$isAdmin = $userId !== null && $this->groupManager->isAdmin($userId);
		$shareIdPlaceholder = '__SHARE_ID__';
		$config = [
			'isAdmin' => $isAdmin,
			'userId' => $userId ?? '',
			'dashboardUrl' => $this->urlGenerator->linkToRoute('sharegate.dashboard.index'),
			'publicLinksUrl' => $this->urlGenerator->linkToRoute('sharegate.files.publicLinks'),
			'summaryUrl' => $this->urlGenerator->linkToRoute('sharegate.dashboard.summary'),
			'accountUrl' => $this->urlGenerator->linkToRoute('sharegate.dashboard.account'),
			'statsUrl' => $this->urlGenerator->linkToRoute('sharegate.dashboard.stats'),
			'paymentLedgerUrl' => $this->urlGenerator->linkToRoute('sharegate.dashboard.paymentLedger'),
			'linkPurchasesUrl' => $this->urlGenerator->linkToRoute('sharegate.buyer.linkPurchases'),
			'paymentVerifyUrl' => $this->urlGenerator->linkToRoute('sharegate.payment.verify'),
			'listUrl' => $this->urlGenerator->linkToRoute('sharegate.dashboard.list'),
			'createUrl' => $this->urlGenerator->linkToRoute('sharegate.share.createEmbed'),
			'createShareUrl' => $this->urlGenerator->linkToRoute('sharegate.share.createShare'),
			'publicBase' => $this->urlGenerator->getAbsoluteURL(''),
			'shareGetUrlTemplate' => $this->urlGenerator->linkToRoute(
				'sharegate.share.getShareSettings',
				['shareId' => $shareIdPlaceholder],
			),
			'shareUpdateUrlTemplate' => $this->urlGenerator->linkToRoute(
				'sharegate.share.updateShare',
				['shareId' => $shareIdPlaceholder],
			),
			'disableUrlTemplate' => $this->urlGenerator->linkToRoute(
				'sharegate.share.disable',
				['shareId' => $shareIdPlaceholder],
			),
			'requestToken' => (string)$this->session->get('requesttoken'),
			'display_currency' => $this->paymentConfig->getDisplayCurrency(),
		];

		if ($isAdmin) {
			$config['paymentConfigUrl'] = $this->urlGenerator->linkToRoute('sharegate.admin.paymentConfig');
			$config['paymentSaveUrl'] = $this->urlGenerator->linkToRoute('sharegate.admin.savePaymentConfig');
		}

		return new TemplateResponse(Application::APP_ID, 'dashboard/index', [
			'nav_base' => $this->urlGenerator->linkToRoute('sharegate.dashboard.index'),
			'nav_icon' => $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
			'dashboard_config' => json_encode(
				$config,
				JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS,
			),
			'csp_nonce' => CspNonce::get(),
		], 'user');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function summary(): DataResponse {
		$userId = $this->shareService->getCurrentUserId();
		if ($userId === null) {
			return new DataResponse(['success' => false, 'error' => $this->l10n()->t('Please log in to Nextcloud')], 401);
		}

		$isAdmin = $this->groupManager->isAdmin($userId);

		$payload = ['success' => true];
		try {
			$payload['filters'] = $this->dashboardService->getFilterCounts($userId);
			$payload['filters']['ledger'] = $this->paymentLedgerService->countForSeller($userId);
		} catch (\Throwable $e) {
			$payload['filters'] = [
				DashboardService::FILTER_ALL => 0,
				DashboardService::FILTER_ACTIVE => 0,
				DashboardService::FILTER_STATS => 0,
			];
			$payload['filters_error'] = $e->getMessage();
		}

		try {
			$payload['account'] = $this->dashboardService->getAccountBindingSummary($isAdmin);
			if ($isAdmin) {
				$payload['payment_config'] = $this->paymentConfig->getAdminSummary();
			}
		} catch (\Throwable $e) {
			return new DataResponse([
				'success' => false,
				'error' => $this->l10n()->t('Account summary failed: %s', [$e->getMessage()]),
			], 500);
		}

		return new DataResponse($payload);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function account(): DataResponse {
		$userId = $this->shareService->getCurrentUserId();
		if ($userId === null) {
			return new DataResponse(['success' => false, 'error' => $this->l10n()->t('Please log in to Nextcloud')], 401);
		}

		$isAdmin = $this->groupManager->isAdmin($userId);

		try {
			$payload = [
				'success' => true,
				'account' => $this->dashboardService->getAccountBindingSummary($isAdmin),
			];
			if ($isAdmin) {
				$payload['payment_config'] = $this->paymentConfig->getAdminSummary();
			}
			return new DataResponse($payload);
		} catch (\Throwable $e) {
			return new DataResponse([
				'success' => false,
				'error' => $this->l10n()->t('Account API failed: %s', [$e->getMessage()]),
			], 500);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function paymentLedger(): DataResponse {
		try {
			$userId = $this->shareService->getCurrentUserId();
			if ($userId === null) {
				return new DataResponse(['success' => false, 'error' => $this->l10n()->t('Please log in to Nextcloud')], 401);
			}

			$status = (string)$this->request->getParam('status', PaymentLedgerService::STATUS_ALL);
			$search = trim((string)$this->request->getParam('q', ''));
			$limit = min(200, max(1, (int)$this->request->getParam('limit', 50)));
			$offset = max(0, (int)$this->request->getParam('offset', 0));

			$result = $this->paymentLedgerService->listForSeller($userId, $status, $search, $limit, $offset);
			$httpStatus = ($result['success'] ?? false) ? 200 : 400;
			return new DataResponse($result, $httpStatus);
		} catch (\Throwable $e) {
			return new DataResponse([
				'success' => false,
				'error' => $this->l10n()->t('Failed to load payment ledger: %s', [$e->getMessage()]),
			], 500);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stats(): DataResponse {
		try {
			$userId = $this->shareService->getCurrentUserId();
			if ($userId === null) {
				return new DataResponse(['success' => false, 'error' => $this->l10n()->t('Please log in to Nextcloud')], 401);
			}

			$result = $this->shareStatsService->listForSeller($userId);
			$status = ($result['success'] ?? false) ? 200 : 400;
			return new DataResponse($result, $status);
		} catch (\Throwable $e) {
			return new DataResponse([
				'success' => false,
				'error' => $this->l10n()->t('Statistics API failed: %s', [$e->getMessage()]),
			], 500);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function list(): DataResponse {
		try {
			$userId = $this->shareService->getCurrentUserId();
			if ($userId === null) {
				return new DataResponse(['success' => false, 'error' => $this->l10n()->t('Please log in to Nextcloud')], 401);
			}

			$filter = (string)$this->request->getParam('filter', DashboardService::FILTER_ALL);
			$query = trim((string)$this->request->getParam('q', ''));
			$limit = min(100, max(1, (int)$this->request->getParam('limit', 50)));
			$offset = max(0, (int)$this->request->getParam('offset', 0));

			$allowed = [
				DashboardService::FILTER_ALL,
				DashboardService::FILTER_ACTIVE,
			];
			if (!in_array($filter, $allowed, true)) {
				$filter = DashboardService::FILTER_ALL;
			}

			$result = $this->dashboardService->listShares($userId, $filter, $query, $limit, $offset);
			$status = ($result['success'] ?? false) ? 200 : 400;
			return new DataResponse($result, $status);
		} catch (\Throwable $e) {
			$message = $this->l10n()->t('Share list failed: %s', [$e->getMessage()]);
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)->error(
				'ShareGate dashboard list failed',
				['exception' => $e],
			);
			return new DataResponse([
				'success' => false,
				'error' => $this->safeApiText($message),
			], 500);
		}
	}

	private function l10n(): IL10N {
		return $this->l10nFactory->get(Application::APP_ID);
	}

	private function safeApiText(string $text): string {
		if (function_exists('iconv')) {
			$clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
			if ($clean !== false) {
				return $clean;
			}
		}
		return $text;
	}
}
