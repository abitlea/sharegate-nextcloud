<?php

declare(strict_types=1);

namespace OCA\ShareGate\Controller;

use OCA\ShareGate\AppInfo\Application;
use OCA\ShareGate\Util\CspNonce;
use OCA\ShareGate\Service\DownloadService;
use OCA\ShareGate\Service\PaymentConfigService;
use OCA\ShareGate\Service\SaveToCloudService;
use OCA\ShareGate\Service\ShareService;
use OCA\ShareGate\Service\ShareStatsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IPreview;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Util;
use OCP\AppFramework\Http;

class ShareController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ShareService $shareService,
		private PaymentConfigService $paymentConfig,
		private DownloadService $downloadService,
		private IMimeTypeDetector $mimeTypeDetector,
		private ShareStatsService $shareStatsService,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
		private IUserSession $userSession,
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

		$l = $this->l10nFactory->get(Application::APP_ID);
		$currency = $this->paymentConfig->getDisplayCurrency();
		$unit = $currency === 'CNY' ? $l->t('currency_unit_CNY') : $currency;

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
			'price_label' => $l->t('Price (%s)', [$unit]),
			'min_price_hint' => $l->t('Minimum 0.01 %s', [$unit]),
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
			return new DataResponse(['success' => false, 'error' => $this->l10n()->t('Please log in to Nextcloud')], 401);
		}

		$result = $this->shareService->getShareSettings($shareId, $userId);
		if (($result['success'] ?? false) && isset($result['share']) && is_array($result['share'])) {
			$result['display_currency'] = $this->paymentConfig->getDisplayCurrency();
			$price = (int)($result['share']['price'] ?? 0);
			$result['share']['price_display'] = $this->paymentConfig->formatPrice($price);
		}
		$status = ($result['success'] ?? false) ? 200 : 404;
		return new DataResponse($result, $status);
	}

	#[NoAdminRequired]
	public function updateShare(string $shareId): DataResponse {
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
		$l = $this->l10nFactory->get(Application::APP_ID);
		$shareInfo = $this->downloadService->getPublicShareInfo($shareId) ?? [];
		$purchasesPageUrl = $this->urlGenerator->linkToRoute('sharegate.buyer.index');

		$downloadConfig = [
			'shareId' => $shareId,
			'ncUserId' => $ncUserId ?? '',
			'linkPurchasesUrl' => $this->urlGenerator->linkToRoute('sharegate.buyer.linkPurchases'),
			'purchasesPageUrl' => $purchasesPageUrl,
			'mimeIconUrl' => $shareInfo['mime_icon_url'] ?? '',
			'previewIconUrl' => $shareInfo['icon_url'] ?? '',
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
			'recoverAccessUrl' => $this->urlGenerator->linkToRoute(
				'sharegate.buyer.recoverAccess',
				['shareId' => $shareId],
			),
			'verifyPayerUrl' => $this->urlGenerator->linkToRoute('sharegate.buyer.verifyPayer'),
			'ncLoggedIn' => $ncUserId !== null,
			'loginUrl' => $this->loginPageUrl(),
			'requestToken' => (string)$this->session->get('requesttoken'),
			'l10n' => [
				'unknown' => $l->t('Unknown'),
				'paidContentDefault' => $l->t('Paid content, scan to pay and download'),
				'days' => $l->t(' days'),
				'requestFailed' => $l->t('Request failed'),
				'loadingFailed' => $l->t('Loading failed'),
				'generatingQr' => $l->t('Generating payment QR code...'),
				'payNow' => $l->t('Pay now'),
				'scanToPay' => $l->t('Scan to pay'),
				'payWithCardHint' => $l->t('You will be redirected to Stripe to pay by card or wallet.'),
				'payWithPayPalHint' => $l->t('You will be redirected to PayPal to complete payment.'),
				'scanWithAlipay' => $l->t('Scan with Alipay to pay'),
				'redirectingToPayment' => $l->t('Redirecting to payment...'),
				'confirmingPayment' => $l->t('Confirming payment...'),
				'paymentQrCode' => $l->t('Payment QR code'),
				'waitingForPayment' => $l->t('Waiting for scan payment...'),
				'noQrReturned' => $l->t('Payment created but no QR code returned'),
				'qrGenerationFailed' => $l->t('QR code generation failed, please refresh'),
				'createPaymentFailed' => $l->t('Failed to create payment'),
				'paymentTimedOut' => $l->t('Payment timed out, please scan again'),
				'downloading' => $l->t('Downloading...'),
				'downloadPermissionDenied' => $l->t('Download permission denied'),
				'downloadFailed' => $l->t('Download failed'),
				'savingToCloud' => $l->t('Saving to your Nextcloud...'),
				'savedToCloud' => $l->t('File saved to your Nextcloud'),
				'saveToCloudFailed' => $l->t('Save to cloud failed'),
				'saveToCloudLoginHint' => $l->t('Log in to this Nextcloud account to save the file to your cloud drive.'),
				'viewMyPurchases' => $l->t('View my purchases'),
				'recoverAccessTitle' => $l->t('Already paid? Recover download access'),
				'recoverAccessHint' => $l->t('Enter the full payment account you used at checkout'),
				'recoverAccessPlaceholder' => $l->t('Alipay / PayPal / Stripe account used to pay'),
				'recoverAccessButton' => $l->t('Recover access'),
				'recoverAccessFailed' => $l->t('Recovery failed'),
				'crossDeviceLinkTitle' => $l->t('Open on another device'),
				'crossDeviceLinkHint' => $l->t('Copy this link to download on another browser or phone'),
				'copyCrossDeviceLink' => $l->t('Copy cross-device link'),
				'linkCopied' => $l->t('Link copied'),
				'purchasesLoginTitle' => $l->t('Sign in to view purchases'),
				'purchasesLoginHint' => $l->t('Enter the full payment account you used at checkout'),
				'purchasesLoginButton' => $l->t('View my purchases'),
				'purchasesLoginFailed' => $l->t('No purchases found for this payment account'),
				'purchasesLoginCancel' => $l->t('Cancel'),
			],
		];

		return new TemplateResponse(Application::APP_ID, 'buyer/view', [
			'share_id' => $shareId,
			'file_icon_url' => $shareInfo['mime_icon_url'] ?? '',
			'file_name' => $shareInfo['file_name'] ?? '',
			'purchases_page_url' => $purchasesPageUrl,
			'download_config' => json_encode(
				$downloadConfig,
				JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS,
			),
			'csp_nonce' => CspNonce::get(),
			'login_url' => $this->loginPageUrl(),
		], TemplateResponse::RENDER_AS_BASE);
	}

	private function loginPageUrl(?string $redirectUrl = null): string {
		try {
			$params = [];
			if ($redirectUrl !== null && $redirectUrl !== '') {
				$params['redirect_url'] = $redirectUrl;
			}
			return $this->urlGenerator->linkToRoute('core.login.showLoginForm', $params);
		} catch (\Throwable) {
			$base = rtrim($this->urlGenerator->getAbsoluteURL(''), '/') . '/login';
			if ($redirectUrl !== null && $redirectUrl !== '') {
				return $base . '?redirect_url=' . rawurlencode($redirectUrl);
			}
			return $base;
		}
	}

	private function saveToCloudService(): SaveToCloudService {
		if (!isset(\OC::$server)) {
			throw new \RuntimeException('Server container unavailable');
		}

		return \OC::$server->get(SaveToCloudService::class);
	}

	/** 买家页文件预览图标（缩略图或 MIME 图标） */
	#[PublicPage]
	#[NoCSRFRequired]
	public function fileIcon(string $shareId) {
		if ($this->downloadService->getPublicShareInfo($shareId) === null) {
			return new DataResponse(['error' => $this->l10n()->t('Share not found or expired')], 404);
		}

		try {
			$share = $this->shareService->getShareEntity($shareId);
			$file = $this->downloadService->tryResolveShareFile($share);
		} catch (\Throwable) {
			return new DataResponse(['error' => $this->l10n()->t('Share not found or expired')], 404);
		}

		if ($file === null) {
			$mime = $share->getFileName() !== ''
				? $this->mimeTypeDetector->detectPath($share->getFileName())
				: 'application/octet-stream';

			return new RedirectResponse($this->downloadService->mimeIconAbsoluteUrl($mime));
		}

		$preview = $this->previewService();
		if ($preview !== null) {
			try {
				$image = $this->runAsShareOwner($share->getCreatedBy(), function () use ($preview, $file) {
					if (!$preview->isAvailable($file)) {
						return null;
					}

					return $preview->getPreview($file, 256, 256, false);
				});
				if ($image !== null) {
					$response = new FileDisplayResponse($image, Http::STATUS_OK, [
						'Content-Type' => $image->getMimeType(),
					]);
					$response->cacheFor(3600);
					return $response;
				}
			} catch (\Throwable) {
				// fall through to mime icon
			}
		}

		$imageResponse = $this->imageFileResponse($share->getCreatedBy(), $file);
		if ($imageResponse !== null) {
			return $imageResponse;
		}

		return new RedirectResponse(
			$this->downloadService->mimeIconAbsoluteUrl($file->getMimeType() ?: 'application/octet-stream'),
		);
	}

	private function previewService(): ?IPreview {
		if (!class_exists(IPreview::class) || !isset(\OC::$server)) {
			return null;
		}

		try {
			/** @var IPreview $preview */
			$preview = \OC::$server->get(IPreview::class);
			return $preview;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Run preview generation as the share owner so anonymous buyers can see thumbnails.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	private function runAsShareOwner(string $ownerId, callable $callback) {
		if ($ownerId === '') {
			return $callback();
		}

		$owner = $this->userManager->get($ownerId);
		if ($owner === null) {
			return $callback();
		}

		$previousUser = $this->userSession->getUser();
		if (method_exists($this->userSession, 'setVolatileActiveUser')) {
			$this->userSession->setVolatileActiveUser($owner);
		} else {
			$this->userSession->setUser($owner);
		}

		try {
			return $callback();
		} finally {
			if (method_exists($this->userSession, 'setVolatileActiveUser')) {
				$this->userSession->setVolatileActiveUser($previousUser);
			} else {
				$this->userSession->setUser($previousUser);
			}
		}
	}

	/**
	 * For image shares, serve the original file when NC preview is unavailable.
	 */
	private function imageFileResponse(string $ownerId, File $file): ?StreamResponse {
		$mime = $file->getMimeType() ?: $this->mimeTypeDetector->detectPath($file->getName());
		if ($mime === '' || !str_starts_with($mime, 'image/')) {
			return null;
		}

		$response = $this->runAsShareOwner($ownerId, function () use ($file, $mime): ?StreamResponse {
			if (!$file->isReadable()) {
				return null;
			}

			$stream = $file->fopen('r');
			if ($stream === false) {
				return null;
			}

			$streamResponse = new \OCP\AppFramework\Http\StreamResponse($stream);
			$streamResponse->addHeader('Content-Type', $mime);
			$streamResponse->cacheFor(3600);
			return $streamResponse;
		});

		return $response instanceof StreamResponse ? $response : null;
	}

	/** 公开分享信息 JSON */
	#[PublicPage]
	#[NoCSRFRequired]
	public function getShareInfo(string $shareId): DataResponse {
		$info = $this->downloadService->getPublicShareInfo($shareId);
		if ($info === null) {
			return new DataResponse(['error' => $this->l10n()->t('Share not found or expired')], 404);
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
		$accessToken = trim((string)($body['access_token'] ?? $this->request->getParam('access_token', '')));
		$result = $this->downloadService->verifyDownload(
			$shareId,
			$providerUserId !== '' ? $providerUserId : null,
			$accessToken !== '' ? $accessToken : null,
		);
		return new DataResponse($result, ($result['success'] ?? false) ? 200 : 403);
	}

	/** 文件流式下载 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function downloadFile(string $shareId) {
		$providerUserId = (string)$this->request->getParam('uid', '');
		$accessToken = trim((string)$this->request->getParam('access_token', ''));
		$verify = $this->downloadService->verifyDownload(
			$shareId,
			$providerUserId !== '' ? $providerUserId : null,
			$accessToken !== '' ? $accessToken : null,
		);
		if (!($verify['success'] ?? false)) {
			return new DataResponse($verify, 403);
		}

		try {
			$share = $this->shareService->getShareEntity($shareId);
			/** @var File $file */
			/** @var resource $stream */
			[$file, $stream] = $this->runAsShareOwner($share->getCreatedBy(), function () use ($share) {
				$file = $this->downloadService->resolveShareFile($share);
				$stream = $file->fopen('r');
				if ($stream === false) {
					throw new \RuntimeException($this->l10n()->t('Could not read file'));
				}

				return [$file, $stream];
			});
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $this->l10n()->t('File not found: %s', [$e->getMessage()])], 404);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => $this->l10n()->t('Could not read file: %s', [$e->getMessage()])], 500);
		}

		$response = new \OCP\AppFramework\Http\StreamResponse($stream, Http::STATUS_OK, [
			'Content-Disposition' => 'attachment; filename="' . addslashes($file->getName()) . '"',
			'Content-Type' => $file->getMimeType() ?: 'application/octet-stream',
			'Content-Length' => (string)$file->getSize(),
		]);
		$this->shareStatsService->recordDownload($shareId);
		return $response;
	}

	/** 同一 NC 实例内转存到当前登录用户网盘 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function saveToCloud(string $shareId): DataResponse {
		$userId = $this->shareService->getCurrentUserId();
		if ($userId === null) {
			$l = $this->l10nFactory->get(Application::APP_ID);
			return new DataResponse([
				'success' => false,
				'error' => $l->t('Please log in to Nextcloud before saving to cloud'),
			], 401);
		}

		$body = $this->request->getParams();
		$raw = file_get_contents('php://input');
		if ($raw !== false && $raw !== '') {
			$json = json_decode($raw, true);
			if (is_array($json)) {
				$body = array_merge($body, $json);
			}
		}
		$providerUserId = (string)($body['provider_user_id'] ?? '');
		$accessToken = trim((string)($body['access_token'] ?? ''));
		try {
			$result = $this->saveToCloudService()->saveToCloud(
				$shareId,
				$providerUserId !== '' ? $providerUserId : null,
				$userId,
				$accessToken !== '' ? $accessToken : null,
			);
		} catch (\Throwable $e) {
			$l = $this->l10nFactory->get(Application::APP_ID);
			$detail = trim($e->getMessage());
			return new DataResponse([
				'success' => false,
				'error' => $detail !== ''
					? $l->t('Save to cloud failed') . ': ' . $detail
					: $l->t('Save to cloud failed'),
			], 500);
		}
		return new DataResponse($result, ($result['success'] ?? false) ? 200 : 400);
	}

	#[NoAdminRequired]
	public function disable(string $shareId): DataResponse {
		$userId = $this->shareService->getCurrentUserId();
		if ($userId === null) {
			return new DataResponse(['success' => false, 'error' => $this->l10n()->t('Please log in to Nextcloud')], 401);
		}
		$result = $this->shareService->disableShare($shareId, $userId);
		return new DataResponse($result, ($result['success'] ?? false) ? 200 : 400);
	}

	private function l10n(): IL10N {
		return $this->l10nFactory->get(Application::APP_ID);
	}
}
