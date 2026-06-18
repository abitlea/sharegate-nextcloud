<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Db\AccessGrantMapper;
use OCA\ShareGate\Db\Share;
use OCA\ShareGate\Db\ShareMapper;
use OCA\ShareGate\Util\ShareFileResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;

/**
 * 对应 monorepo DownloadManager + 文件流式下载
 */
class DownloadService {
	public function __construct(
		private ShareMapper $shareMapper,
		private AccessGrantMapper $accessGrantMapper,
		private PaymentService $paymentService,
		private PaymentConfigService $paymentConfig,
		private ShareFileResolver $shareFileResolver,
		private IMimeTypeDetector $mimeTypeDetector,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function mimeIconAbsoluteUrl(string $mime): string {
		$mime = $mime !== '' ? $mime : 'application/octet-stream';

		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkTo('', 'core/mimeicon') . '?mime=' . rawurlencode($mime),
		);
	}

	public function publicFileIconUrl(string $shareId): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'sharegate.share.fileIcon',
			['shareId' => $shareId],
		);
	}

	public function tryResolveShareFile(Share $share): ?File {
		return $this->shareFileResolver->tryResolve($share);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function verifyDownload(string $shareId, ?string $providerUserId): array {
		try {
			$share = $this->shareMapper->findByShareId($shareId);
		} catch (DoesNotExistException) {
			return [
				'success' => false,
				'code' => 'SHARE_NOT_FOUND',
				'message' => '分享链接不存在',
			];
		}

		if ($this->isShareExpired($share)) {
			return [
				'success' => false,
				'code' => 'SHARE_EXPIRED',
				'message' => '分享已过期',
			];
		}

		if ($providerUserId === null || $providerUserId === '') {
			return [
				'success' => false,
				'code' => 'MISSING_TOKEN',
				'message' => '缺少下载凭证',
				'error' => '请先支付获取下载权限',
			];
		}

		try {
			if (!$this->paymentService->hasUserPaid($shareId, $providerUserId)) {
				return [
					'success' => false,
					'code' => 'ACCESS_DENIED',
					'message' => '没有下载权限，请先支付',
					'error' => '请先扫码支付后再下载',
				];
			}
			$grant = $this->accessGrantMapper->findActive($shareId, $providerUserId);
		} catch (DoesNotExistException|Exception) {
			return [
				'success' => false,
				'code' => 'ACCESS_DENIED',
				'message' => '没有下载权限或授权已过期',
			];
		}

		$downloadUrl = $this->urlGenerator->linkToRoute(
			'sharegate.share.downloadFile',
			['shareId' => $shareId],
		) . '?uid=' . rawurlencode($providerUserId);

		return [
			'success' => true,
			'code' => 'ACCESS_GRANTED',
			'message' => '下载权限验证通过',
			'file_path' => $share->getFilePath(),
			'file_name' => $share->getFileName(),
			'storage_type' => $share->getStorageType(),
			'authorized_until' => $grant->getExpiresAt() !== null
				? date('c', (int)($grant->getExpiresAt() / 1000))
				: null,
			'download_url' => $downloadUrl,
		];
	}

	/**
	 * @throws NotFoundException
	 */
	public function resolveShareFile(Share $share): File {
		$file = $this->shareFileResolver->resolve($share);
		$this->shareFileResolver->syncDisplayFields($share, $file);

		return $file;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getPublicShareInfo(string $shareId): ?array {
		try {
			$share = $this->shareMapper->findByShareId($shareId);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($this->isShareExpired($share)) {
			return null;
		}

		$file = $this->shareFileResolver->tryResolve($share);
		$mime = $this->fileMimeForShare($share, $file);

		return [
			'share_id' => $share->getShareId(),
			'title' => $share->getTitle(),
			'description' => $share->getDescription(),
			'file_name' => $share->getFileName(),
			'mime_type' => $mime,
			'icon_url' => $this->publicFileIconUrl($shareId),
			'mime_icon_url' => $this->mimeIconAbsoluteUrl($mime),
			'file_size' => $share->getFileSize(),
			'storage_type' => $share->getStorageType(),
			'price' => $share->getPrice(),
			'price_yuan' => number_format($share->getPrice() / 100, 2, '.', ''),
			'price_display' => $this->paymentConfig->formatPrice($share->getPrice()),
			'currency' => $this->paymentConfig->getDisplayCurrency(),
			'payment_flow' => $this->paymentConfig->getPaymentFlow(),
			'payment_provider' => $this->paymentConfig->getActiveProviderName(),
			'access_days' => $share->getAccessDays(),
			'created_at' => $share->getCreatedAt(),
			'expire_at' => $share->getExpireAt(),
		];
	}

	private function isShareExpired(Share $share): bool {
		$expireAt = $share->getExpireAt();
		return $expireAt !== null && $expireAt < (int)(microtime(true) * 1000);
	}

	private function fileMimeForShare(Share $share, ?File $file): string {
		if ($file !== null) {
			$mime = $file->getMimeType();
			if ($mime !== null && $mime !== '') {
				return $mime;
			}
		}

		return $this->mimeTypeDetector->detectPath($share->getFileName());
	}
}
