<?php

declare(strict_types=1);

namespace OCA\ShareGate\Controller;

use OCA\ShareGate\AppInfo\Application;
use OCA\ShareGate\Http\SvgImageResponse;
use OCA\ShareGate\Util\CspNonce;
use OCA\ShareGate\Service\DownloadService;
use OCA\ShareGate\Service\PaymentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PaymentController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PaymentService $paymentService,
		private DownloadService $downloadService,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): DataResponse {
		$body = $this->parseJsonBody();
		$result = $this->paymentService->createPayment($body);
		if (($result['success'] ?? false) && !empty($result['order_id'])) {
			$result['qr_url'] = $this->urlGenerator->linkToRoute(
				'sharegate.payment.qrImage',
				['orderId' => $result['order_id']],
			);
		}
		$status = ($result['success'] ?? false) ? 200 : 400;
		return new DataResponse($result, $status);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function qrImage(string $orderId) {
		$svg = $this->paymentService->getQrSvgForOrder($orderId);
		if ($svg === null) {
			return new DataResponse(['error' => $this->l->t('Payment QR code unavailable')], 404);
		}
		return new SvgImageResponse($svg);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function check(string $shareId): DataResponse {
		$accessToken = trim((string)$this->request->getParam('access_token', ''));
		if ($accessToken !== '') {
			return new DataResponse([
				'has_access' => $this->downloadService->hasDownloadAccess($shareId, null, $accessToken),
			]);
		}

		$providerUserId = (string)$this->request->getParam('provider_user_id', '');
		if ($providerUserId === '') {
			return new DataResponse(['error' => $this->l->t('Missing provider_user_id')], 400);
		}
		return new DataResponse([
			'has_access' => $this->paymentService->hasUserPaid($shareId, $providerUserId),
		]);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function verify(): DataResponse {
		$body = $this->parseJsonBody();
		$shareId = (string)($body['share_id'] ?? '');
		$providerUserId = (string)($body['provider_user_id'] ?? '');
		$accessToken = trim((string)($body['access_token'] ?? ''));
		$result = $this->downloadService->verifyDownload(
			$shareId,
			$providerUserId !== '' ? $providerUserId : null,
			$accessToken !== '' ? $accessToken : null,
		);
		return new DataResponse($result, ($result['success'] ?? false) ? 200 : 403);
	}

	/** Mock 支付回调（阶段 2） */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function webhook(): DataResponse {
		$body = $this->parseJsonBody();
		$orderId = (string)($body['order_id'] ?? '');
		$providerUserId = (string)($body['provider_user_id'] ?? '');

		if ($orderId === '' || $providerUserId === '') {
			return new DataResponse(['success' => false, 'error' => $this->l->t('Missing order_id or provider_user_id')], 400);
		}

		$result = $this->paymentService->confirmPayment($orderId, $providerUserId);
		return new DataResponse($result, ($result['success'] ?? false) ? 200 : 400);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(string $orderId): DataResponse {
		$paypalToken = trim((string)$this->request->getParam('paypal_token', ''));
		$cancelledParam = $this->request->getParam('cancelled', '');
		$buyerCancelled = $cancelledParam === '1'
			|| $cancelledParam === 'true'
			|| $cancelledParam === true;
		$result = $this->paymentService->queryOrderStatus(
			$orderId,
			$paypalToken !== '' ? $paypalToken : null,
			$buyerCancelled,
		);
		return new DataResponse($result, 200);
	}

	/** 浏览器探测：GET 返回说明；支付宝异步通知走 POST */
	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function notifyAlipayHealth(): TextPlainResponse {
		return new TextPlainResponse(
			'ShareGate alipay_f2f notify OK. Alipay must POST payment callbacks to this URL.',
		);
	}

	/** Stripe webhook health check (GET) */
	#[PublicPage]
	#[NoCSRFRequired]
	public function notifyStripeHealth(): TextPlainResponse {
		return new TextPlainResponse(
			'ShareGate stripe notify OK. Stripe must POST checkout.session.completed webhooks to this URL.',
		);
	}

	/** Stripe Checkout webhook */
	#[PublicPage]
	#[NoCSRFRequired]
	public function notifyStripe(): DataResponse {
		$payload = file_get_contents('php://input');
		if (!is_string($payload)) {
			$payload = '';
		}
		$signature = $this->request->getHeader('Stripe-Signature');
		if (is_array($signature)) {
			$signature = $signature[0] ?? '';
		}

		$result = $this->paymentService->handleStripeNotify($payload, (string)$signature);
		$status = ($result['success'] ?? false) ? 200 : 400;
		return new DataResponse(['received' => (bool)($result['success'] ?? false)], $status);
	}

	/** PayPal webhook health check (GET) */
	#[PublicPage]
	#[NoCSRFRequired]
	public function notifyPaypalHealth(): TextPlainResponse {
		return new TextPlainResponse(
			'ShareGate paypal notify OK. PayPal must POST checkout/payment webhooks to this URL.',
		);
	}

	/** PayPal Checkout webhook */
	#[PublicPage]
	#[NoCSRFRequired]
	public function notifyPaypal(): DataResponse {
		$payload = file_get_contents('php://input');
		if (!is_string($payload)) {
			$payload = '';
		}

		$result = $this->paymentService->handlePaypalNotify($payload, $this->request->getHeaders());
		$status = ($result['success'] ?? false) ? 200 : 400;
		return new DataResponse(['received' => (bool)($result['success'] ?? false)], $status);
	}

	/** 支付宝当面付异步通知 */
	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function notifyAlipay(): TextPlainResponse {
		$params = $this->request->getParams();
		if ($params === []) {
			$raw = file_get_contents('php://input');
			if (is_string($raw) && $raw !== '') {
				parse_str($raw, $parsed);
				if (is_array($parsed)) {
					$params = $parsed;
				}
			}
		}

		$result = $this->paymentService->handleAlipayNotify($params);
		$text = ($result['success'] ?? false) ? 'success' : 'fail';
		return new TextPlainResponse($text);
	}

	/** 模拟支付页 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function mockPay(string $orderId): TemplateResponse {
		Util::addTranslations(Application::APP_ID);
		Util::addStyle(Application::APP_ID, 'mock-pay');

		$providerUserId = (string)$this->request->getParam('provider_user_id', 'buyer_' . $orderId);

		return new TemplateResponse(Application::APP_ID, 'payment/mock', [
			'order_id' => $orderId,
			'provider_user_id' => $providerUserId,
			'webhook_url' => $this->urlGenerator->linkToRoute('sharegate.payment.webhook'),
			'csp_nonce' => CspNonce::get(),
		], TemplateResponse::RENDER_AS_BASE);
	}

	#[NoAdminRequired]
	public function manualConfirm(): DataResponse {
		$body = $this->parseJsonBody();
		$result = $this->paymentService->confirmPayment(
			(string)($body['order_id'] ?? ''),
			(string)($body['provider_user_id'] ?? ''),
			isset($body['provider_order_id']) ? (string)$body['provider_order_id'] : null,
		);
		return new DataResponse($result, ($result['success'] ?? false) ? 200 : 400);
	}

	/** @return array<string, mixed> */
	private function parseJsonBody(): array {
		$body = $this->request->getParams();
		$raw = file_get_contents('php://input');
		if ($raw !== false && $raw !== '') {
			$json = json_decode($raw, true);
			if (is_array($json)) {
				$body = array_merge($body, $json);
			}
		}
		return $body;
	}
}
