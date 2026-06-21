<?php

declare(strict_types=1);

namespace OCA\ShareGate\Controller;

use OCA\ShareGate\AppInfo\Application;
use OCA\ShareGate\Service\BuyerPurchaseService;
use OCA\ShareGate\Service\BuyerPurchasesTokenService;
use OCA\ShareGate\Service\BuyerRecoveryService;
use OCA\ShareGate\Service\ShareService;
use OCA\ShareGate\Util\BuyerAccount;
use OCA\ShareGate\Util\CspNonce;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\ISession;
use OCP\L10N\IFactory;
use OCP\Util;

class BuyerController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ShareService $shareService,
		private BuyerPurchaseService $buyerPurchaseService,
		private BuyerRecoveryService $buyerRecoveryService,
		private BuyerPurchasesTokenService $purchasesTokenService,
		private IURLGenerator $urlGenerator,
		private ISession $session,
		private IFactory $l10nFactory,
	) {
		parent::__construct($appName, $request);
	}

	/** Buyer purchase history — keyed by payment account(s), not Nextcloud login. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addTranslations(Application::APP_ID);
		Util::addStyle(Application::APP_ID, 'download');
		Util::addStyle(Application::APP_ID, 'buyer-purchases');
		Util::addScript(Application::APP_ID, 'buyer-purchases');

		$ncUserId = $this->shareService->getCurrentUserId();
		$config = [
			'purchasesUrl' => $this->urlGenerator->linkToRoute('sharegate.buyer.purchases'),
			'verifyPayerUrl' => $this->urlGenerator->linkToRoute('sharegate.buyer.verifyPayer'),
			'ncLoggedIn' => $ncUserId !== null,
			'requestToken' => (string)$this->session->get('requesttoken'),
		];

		return new TemplateResponse(Application::APP_ID, 'buyer/purchases', [
			'buyer_purchases_config' => json_encode(
				$config,
				JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS,
			),
			'csp_nonce' => CspNonce::get(),
		], TemplateResponse::RENDER_AS_BASE);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function purchases(): DataResponse {
		try {
			$token = trim((string)$this->request->getParam('purchases_token', ''));
			if ($token === '') {
				return new DataResponse([
					'success' => false,
					'code' => 'PURCHASES_TOKEN_REQUIRED',
					'error' => $this->l10n()->t('Purchases session required. Verify your payment account first.'),
				], 401);
			}

			$payload = $this->purchasesTokenService->validate($token);
			if ($payload === null) {
				return new DataResponse([
					'success' => false,
					'code' => 'PURCHASES_TOKEN_INVALID',
					'error' => $this->l10n()->t('Invalid or expired purchases session'),
				], 403);
			}

			$limit = min(200, max(1, (int)$this->request->getParam('limit', 100)));
			$result = $this->buyerPurchaseService->listPurchasesForPayers($payload['payer_ids'], $limit);
			$status = ($result['success'] ?? false) ? 200 : 400;
			return new DataResponse($result, $status);
		} catch (\Throwable $e) {
			return new DataResponse([
				'success' => false,
				'error' => $this->l10n()->t('Failed to load purchases: %s', [$e->getMessage()]),
			], 500);
		}
	}

	/** Optional: merge browser buyer_xxx grants into logged-in NC user (save-to-cloud). */
	#[NoAdminRequired]
	public function linkPurchases(): DataResponse {
		$userId = $this->shareService->getCurrentUserId();
		if ($userId === null) {
			return new DataResponse(['success' => false, 'error' => $this->l10n()->t('Please log in to Nextcloud')], 401);
		}

		$body = $this->request->getParams();
		$raw = file_get_contents('php://input');
		if ($raw !== false && $raw !== '') {
			$json = json_decode($raw, true);
			if (is_array($json)) {
				$body = array_merge($body, $json);
			}
		}

		$anonymousId = BuyerAccount::normalize((string)($body['anonymous_buyer_id'] ?? ''));
		if ($anonymousId === null) {
			return new DataResponse(['success' => false, 'error' => $this->l10n()->t('Invalid anonymous buyer id')], 400);
		}

		$result = $this->buyerPurchaseService->linkAnonymousPurchases($userId, $anonymousId);
		return new DataResponse($result, ($result['success'] ?? false) ? 200 : 400);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function recoverAccess(string $shareId): DataResponse {
		$body = $this->request->getParams();
		$raw = file_get_contents('php://input');
		if ($raw !== false && $raw !== '') {
			$json = json_decode($raw, true);
			if (is_array($json)) {
				$body = array_merge($body, $json);
			}
		}
		$payerId = (string)($body['payer_id'] ?? $body['provider_user_id'] ?? '');
		$result = $this->buyerRecoveryService->recoverShareAccess($shareId, $payerId);
		return new DataResponse($result, ($result['success'] ?? false) ? 200 : 400);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function verifyPayer(): DataResponse {
		try {
			$body = $this->request->getParams();
			$raw = file_get_contents('php://input');
			if ($raw !== false && $raw !== '') {
				$json = json_decode($raw, true);
				if (is_array($json)) {
					$body = array_merge($body, $json);
				}
			}
			$payerId = (string)($body['payer_id'] ?? $body['provider_user_id'] ?? '');
			$existingToken = trim((string)($body['purchases_token'] ?? ''));
			$result = $this->buyerRecoveryService->verifyPayerAccount($payerId, $existingToken !== '' ? $existingToken : null);
			return new DataResponse($result, ($result['success'] ?? false) ? 200 : 400);
		} catch (\Throwable $e) {
			return new DataResponse([
				'success' => false,
				'error' => $this->l10n()->t('Recovery failed: %s', [$e->getMessage()]),
			], 500);
		}
	}

	private function l10n(): IL10N {
		return $this->l10nFactory->get(Application::APP_ID);
	}
}
