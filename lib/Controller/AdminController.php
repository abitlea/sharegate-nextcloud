<?php

declare(strict_types=1);

namespace OCA\ShareGate\Controller;

use OCA\ShareGate\Db\PaymentMapper;
use OCA\ShareGate\Db\ShareMapper;
use OCA\ShareGate\Service\AdminStatsService;
use OCA\ShareGate\Service\PaymentConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class AdminController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PaymentConfigService $paymentConfig,
		private AdminStatsService $adminStatsService,
		private ShareMapper $shareMapper,
		private PaymentMapper $paymentMapper,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[AdminRequired]
	#[NoCSRFRequired]
	public function paymentConfig(): DataResponse {
		return new DataResponse($this->paymentConfig->getAdminSummary());
	}

	#[AdminRequired]
	#[NoCSRFRequired]
	public function savePaymentConfig(): DataResponse {
		$body = $this->parseBody();
		$mode = (string)($body['payment_mode'] ?? PaymentConfigService::MODE_MOCK);
		$this->paymentConfig->setPaymentMode($mode);

		if (isset($body['alipay_f2f']) && is_array($body['alipay_f2f'])) {
			$alipay = $body['alipay_f2f'];
			$this->paymentConfig->saveAlipayF2fConfig([
				'app_id' => $alipay['app_id'] ?? '',
				'private_key' => $alipay['private_key'] ?? '',
				'alipay_public_key' => $alipay['alipay_public_key'] ?? '',
				'sandbox' => filter_var($alipay['sandbox'] ?? true, FILTER_VALIDATE_BOOLEAN),
				'notify_url_base' => $alipay['notify_url_base'] ?? '',
			]);
		}

		return new DataResponse([
			'success' => true,
			'message' => '配置已保存',
			'summary' => $this->paymentConfig->getAdminSummary(),
		]);
	}

	#[AdminRequired]
	public function shares(): DataResponse {
		$limit = max(1, min(500, (int)$this->request->getParam('limit', 100)));
		$offset = max(0, (int)$this->request->getParam('offset', 0));
		$shares = $this->shareMapper->findAll($limit, $offset);
		$total = $this->shareMapper->countAll();
		$items = array_map(function ($share) {
			return [
				'share_id' => $share->getShareId(),
				'file_path' => $share->getFilePath(),
				'file_name' => $share->getFileName(),
				'file_size' => $share->getFileSize(),
				'title' => $share->getTitle(),
				'description' => $share->getDescription(),
				'price' => $share->getPrice(),
				'access_days' => $share->getAccessDays(),
				'storage_type' => $share->getStorageType(),
				'status' => $share->getStatus(),
				'created_by' => $share->getCreatedBy(),
				'created_at' => $share->getCreatedAt(),
				'expire_at' => $share->getExpireAt(),
				'share_url' => $this->urlGenerator->linkToRouteAbsolute('sharegate.share.view', ['shareId' => $share->getShareId()]),
			];
		}, $shares);

		return new DataResponse([
			'success' => true,
			'items' => $items,
			'total' => $total,
		]);
	}

	#[AdminRequired]
	public function payments(): DataResponse {
		$limit = max(1, min(500, (int)$this->request->getParam('limit', 100)));
		$offset = max(0, (int)$this->request->getParam('offset', 0));
		$payments = $this->paymentMapper->findAll($limit, $offset);
		$total = $this->paymentMapper->countAll();
		$items = array_map(function ($payment) {
			return [
				'order_id' => $payment->getOrderId(),
				'share_id' => $payment->getShareId(),
				'amount' => $payment->getAmount(),
				'provider' => $payment->getProvider(),
				'provider_order_id' => $payment->getProviderOrderId(),
				'client_user_id' => $payment->getClientUserId(),
				'status' => $payment->getStatus(),
				'qr_code' => $payment->getQrCode(),
				'created_at' => $payment->getCreatedAt(),
				'paid_at' => $payment->getPaidAt(),
			];
		}, $payments);

		return new DataResponse([
			'success' => true,
			'items' => $items,
			'total' => $total,
		]);
	}

	#[AdminRequired]
	public function stats(): DataResponse {
		return new DataResponse($this->adminStatsService->getGlobalStats());
	}

	/** @deprecated 使用 savePaymentConfig */
	#[AdminRequired]
	public function settings(): DataResponse {
		return $this->savePaymentConfig();
	}

	/** @return array<string, mixed> */
	private function parseBody(): array {
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
