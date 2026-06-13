<?php

declare(strict_types=1);

namespace OCA\ShareGate\Controller;

use OCA\ShareGate\AppInfo\Application;
use OCA\ShareGate\Util\CspNonce;
use OCA\ShareGate\Service\DownloadService;
use OCA\ShareGate\Service\SaveToCloudService;
use OCA\ShareGate\Service\ShareService;
use OCA\ShareGate\Service\ShareStatsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Util;

class ShareController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ShareService $shareService,
		private DownloadService $downloadService,
		private ShareStatsService $shareStatsService,
		private SaveToCloudService $saveToCloudService,
		private IURLGenerator $urlGenerator,
		private \OCP\ISession $session,
		private IFactory $l10nFactory,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createEmbed(): TemplateResponse {
		Util::addTranslations(Application::APP_ID);
		Util::addStyle(Application::APP_ID, 'embed-create');
		Util::addScript(Application::APP_ID, 'embed-create');
		Util::addScript('core', 'common');

		$path = (string)$this->request->getParam('path', '');
		$name = (string)$this->request->getParam('name', '');

		$embedConfig = [
			'platform' => 'nextcloud',
			'createUrl' => $this->urlGenerator->linkToRoute('sharegate.share.createShare'),
			'dashboardUrl' => $this->urlGenerator->linkToRoute('sharegate.dashboard.index'),
			'storageType' => 'nextcloud',
			'requireQueryParams' => false,
			'pathEditable' => true,
			'authType' => 'ncCsrf',
			'requestToken' => (string)$this->session->get('requesttoken'),
			'publicBase' => $this->urlGenerator->getAbsoluteURL(''),
			'adminLink' => $this->urlGenerator->linkToRoute(
				'settings.AdminSettings.index',
				['section' => 'sharegate'],
			),
			'missingParamsMessage' => $this->l10nFactory->get(Application::APP_ID)
				->t('Please enter file path and name'),
			'initialPath' => $path,
			'initialName' => $name,
		];

		return new TemplateResponse(Application::APP_ID, 'embed/create', [
			'embed_config' => json_encode(
				$embedConfig,
				JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS,
			),
			'csp_nonce' => CspNonce::get(),
		], TemplateResponse::RENDER_AS_BASE);
	}

	#[NoAdminRequired]
	public function createShare(): DataResponse {
		$body = $this->request->getParams();
		$raw = file_get_contents('php://input');
		if ($raw !== false && $raw !== '') {
			$json = json_decode($raw, true);
			if (is_array($json)) {
				$body = array_merge($body, $json);
			}
		}

		$result = $this->shareService->createShare($body);
		$status = ($result['success'] ?? false) ? 200 : 400;
		return new DataResponse($result, $status);
	}

	/**
	 * @deprecated 1.3+ 重定向至管理台 ocdialog（#paid?edit=shareId）
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(string $shareId): RedirectResponse {
		$url = $this->urlGenerator->linkToRoute('sharegate.dashboard.index')
			. '?edit=' . rawurlencode($shareId)
			. '#paid';
		return new RedirectResponse($url);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getShareSettings(string $shareId): DataResponse {
		$userId = $this->shareService->getCurrentUserId();
		if ($userId === null) {
			return new DataResponse(['success' => false, 'error' => '未登录'], 401);
		}

		$result = $this->shareService->getShareSettings($shareId, $userId);
		$status = ($result['success'] ?? false) ? 200 : 404;
		return new DataResponse($result, $status);
	}

	#[NoAdminRequired]
	public function updateShare(string $shareId): DataResponse {
		$userId = $this->shareService->getCurrentUserId();
		if ($userId === null) {
			return new DataResponse(['success' => false, 'error' => '未登录'], 401);
		}

		$body = $this->request->getParams();
		$raw = file_get_contents('php://input');
		if ($raw !== false && $raw !== '') {
			$json = json_decode($raw, true);
			if (is_array($json)) {
				$body = array_merge($body, $json);
			}
		}

		$result = $this->shareService->updateShare($shareId, $userId, $body);
		$status = ($result['success'] ?? false) ? 200 : 400;
		return new DataResponse($result, $status);
	}

	/** 买家付费下载�?*/
	#[PublicPage]
	#[NoCSRFRequired]
	public function view(string $shareId): TemplateResponse {
		Util::addTranslations(Application::APP_ID);
		Util::addStyle(Application::APP_ID, 'download');
		Util::addScript(Application::APP_ID, 'download');

		try {
			$this->shareService->getShareEntity($shareId);
			$this->shareStatsService->recordPreview($shareId);
		} catch (\Throwable $e) {
			// 无效分享不计预览
		}

		$ncUserId = $this->shareService->getCurrentUserId();

		$downloadConfig = [
			'shareId' => $shareId,
			'shareInfoUrl' => $this->urlGenerator->linkToRoute(
				'sharegate.share.getShareInfo',
				['shareId' => $shareId],
			),
			'paymentCreateUrl' => $this->urlGenerator->linkToRoute('sharegate.payment.create'),
			'paymentCheckUrl' => $this->urlGenerator->linkToRoute(
				'sharegate.payment.check',
				['shareId' => $shareId],
			),
			'paymentVerifyUrl' => $this->urlGenerator->linkToRoute('sharegate.payment.verify'),
			'paymentStatusUrlTemplate' => $this->urlGenerator->linkToRoute(
				'sharegate.payment.status',
				['orderId' => '__OID__'],
			),
			'downloadUrl' => $this->urlGenerator->linkToRoute(
				'sharegate.share.downloadFile',
				['shareId' => $shareId],
			),
			'saveToCloudUrl' => $this->urlGenerator->linkToRoute(
				'sharegate.share.saveToCloud',
				['shareId' => $shareId],
			),
			'ncLoggedIn' => $ncUserId !== null,
			'requestToken' => (string)$this->session->get('requesttoken'),
		];

		return new TemplateResponse(Application::APP_ID, 'buyer/view', [
			'share_id' => $shareId,
			'download_config' => json_encode(
				$downloadConfig,
				JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS,
			),
			'csp_nonce' => CspNonce::get(),
		], TemplateResponse::RENDER_AS_BASE);
	}

	/** 公开分享信息 JSON */
	#[PublicPage]
	#[NoCSRFRequired]
	public function getShareInfo(string $shareId): DataResponse {
		$info = $this->downloadService->getPublicShareInfo($shareId);
		if ($info === null) {
			return new DataResponse(['error' => '分享链接不存在或已过期'], 404);
		}
		return new DataResponse($info);
	}

	/** 验证下载权限（JSON�?*/
	#[PublicPage]
	#[NoCSRFRequired]
	public function download(string $shareId): DataResponse {
		$body = $this->request->getParams();
		$raw = file_get_contents('php://input');
		if ($raw !== false && $raw !== '') {
			$json = json_decode($raw, true);
			if (is_array($json)) {
				$body = array_merge($body, $json);
			}
		}

		$providerUserId = (string)($body['provider_user_id'] ?? $this->request->getParam('uid', ''));
		$result = $this->downloadService->verifyDownload($shareId, $providerUserId);
		return new DataResponse($result, ($result['success'] ?? false) ? 200 : 403);
	}

	/** 文件流式下载 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function downloadFile(string $shareId) {
		$providerUserId = (string)$this->request->getParam('uid', '');
		$verify = $this->downloadService->verifyDownload($shareId, $providerUserId);
		if (!($verify['success'] ?? false)) {
			return new DataResponse($verify, 403);
		}

		try {
			$info = $this->downloadService->getPublicShareInfo($shareId);
			if ($info === null) {
				return new DataResponse(['error' => '分享不存在'], 404);
			}
			$share = $this->shareService->getShareEntity($shareId);
			$file = $this->downloadService->resolveShareFile($share);
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => '文件不存在: ' . $e->getMessage()], 404);
		}

		$stream = $file->fopen('r');
		if ($stream === false) {
			return new DataResponse(['error' => '无法读取文件'], 500);
		}

		$response = new StreamResponse($stream);
		$response->addHeader(
			'Content-Disposition',
			'attachment; filename="' . addslashes($file->getName()) . '"',
		);
		$response->addHeader('Content-Type', $file->getMimeType() ?: 'application/octet-stream');
		$response->addHeader('Content-Length', (string)$file->getSize());
		$this->shareStatsService->recordDownload($shareId);
		return $response;
	}

	/** 同一 NC 实例内转存到当前登录用户网盘 */
	#[NoAdminRequired]
	public function saveToCloud(string $shareId): DataResponse {
		$userId = $this->shareService->getCurrentUserId();
		$body = $this->request->getParams();
		$raw = file_get_contents('php://input');
		if ($raw !== false && $raw !== '') {
			$json = json_decode($raw, true);
			if (is_array($json)) {
				$body = array_merge($body, $json);
			}
		}
		$providerUserId = (string)($body['provider_user_id'] ?? '');
		$result = $this->saveToCloudService->saveToCloud(
			$shareId,
			$providerUserId,
			$userId ?? '',
		);
		return new DataResponse($result, ($result['success'] ?? false) ? 200 : 400);
	}

	#[NoAdminRequired]
	public function disable(string $shareId): DataResponse {
		$userId = $this->shareService->getCurrentUserId();
		if ($userId === null) {
			return new DataResponse(['success' => false, 'error' => '未登录'], 401);
		}
		$result = $this->shareService->disableShare($shareId, $userId);
		return new DataResponse($result, ($result['success'] ?? false) ? 200 : 400);
	}
}
