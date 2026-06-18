<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Db\Share;
use OCA\ShareGate\Db\ShareMapper;
use OCP\DB\Exception;
use OCA\ShareGate\Util\UserFilePath;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * 对应 monorepo LinkManager + ShareRepository 的创建逻辑。
 */
class ShareService {
	public function __construct(
		private ShareMapper $shareMapper,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getCurrentUserId(): ?string {
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : null;
	}

	/**
	 * @return array{success: true, share_id: string, share_url: string, price: int, access_days: int, share_expire_at: ?int}|array{success: false, error: string}
	 */
	public function createShare(array $data): array {
		$userId = $this->getCurrentUserId();
		if ($userId === null) {
			return ['success' => false, 'error' => '请先登录 Nextcloud'];
		}

		$filePath = trim((string)($data['file_path'] ?? ''));
		$fileName = trim((string)($data['file_name'] ?? ''));
		$title = trim((string)($data['title'] ?? ''));
		$price = (int)($data['price'] ?? 0);
		$accessDays = (int)($data['access_days'] ?? 30);
		$shareExpireDays = isset($data['share_expire_days']) ? (int)$data['share_expire_days'] : null;

		if ($filePath === '' || $fileName === '' || $title === '') {
			return ['success' => false, 'error' => '缺少必填字段: file_path, file_name, title'];
		}
		if ($price <= 0) {
			return ['success' => false, 'error' => '价格必须为正整数（单位：分）'];
		}

		$minPrice = (int)$this->config->getAppValue('sharegate', 'min_price', '1');
		$maxAccessDays = (int)$this->config->getAppValue('sharegate', 'max_access_days', '365');
		$price = max($price, $minPrice);
		$accessDays = min(max($accessDays, 1), $maxAccessDays);

		try {
			$fileId = isset($data['file_id']) ? (int)$data['file_id'] : null;
			$fileInfo = $this->resolveUserFile($userId, $filePath, $fileName, $fileId > 0 ? $fileId : null);
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => '文件不存在或无权访问: ' . $e->getMessage()];
		}

		$existing = null;
		if ($fileInfo['id'] > 0) {
			$existing = $this->shareMapper->findActiveByUserAndFileId($userId, $fileInfo['id']);
		}
		if ($existing === null) {
			$existing = $this->shareMapper->findActiveByUserAndFilePath($userId, $fileInfo['path']);
		}
		if ($existing !== null) {
			return [
				'success' => false,
				'error' => '该文件已有付费分享，请直接编辑已有链接，或先取消旧分享后再创建',
				'existing_share_id' => $existing->getShareId(),
			];
		}

		$shareId = $this->generateShareId();
		$now = (int)(microtime(true) * 1000);
		$expireAt = $shareExpireDays !== null && $shareExpireDays > 0
			? $now + $shareExpireDays * 86400000
			: null;

		$share = new Share();
		$share->setShareId($shareId);
		$share->setFilePath($fileInfo['path']);
		$share->setFileId($fileInfo['id']);
		$share->setFileName($fileInfo['name']);
		$share->setFileSize($fileInfo['size']);
		$share->setTitle($title);
		$share->setDescription((string)($data['description'] ?? ''));
		$share->setPrice($price);
		$share->setAccessDays($accessDays);
		$share->setStorageType('nextcloud');
		$share->setStatus('active');
		$share->setCreatedBy($userId);
		$share->setCreatedAt($now);
		$share->setExpireAt($expireAt);

		try {
			/** @var Share $saved */
			$saved = $this->shareMapper->insert($share);
		} catch (Exception $e) {
			return ['success' => false, 'error' => '保存分享失败: ' . $e->getMessage()];
		}

		$shareUrl = $this->urlGenerator->linkToRouteAbsolute('sharegate.share.view', ['shareId' => $shareId]);

		return [
			'success' => true,
			'share_id' => $shareId,
			'share_url' => $shareUrl,
			'price' => $price,
			'access_days' => $accessDays,
			'share_expire_at' => $expireAt,
		];
	}

	/**
	 * @return array{path: string, name: string, size: int, id: int}
	 */
	private function resolveUserFile(string $userId, string $filePath, string $fileName, ?int $fileId = null): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);

		if ($fileId !== null) {
			try {
				$nodes = $userFolder->getById($fileId);
				$node = $nodes[0] ?? null;
				if ($node instanceof File) {
					return [
						'path' => '/' . ltrim($node->getPath(), '/'),
						'name' => $node->getName(),
						'size' => $node->getSize(),
						'id' => (int)$node->getId(),
					];
				}
			} catch (\Throwable) {
				// 回退到路径解析
			}
		}

		$relative = UserFilePath::toUserRelative($userId, $filePath);
		if ($relative === '') {
			$relative = ltrim($filePath, '/');
		}

		try {
			$node = $userFolder->get($relative);
		} catch (NotFoundException $e) {
			$node = $userFolder->get($fileName);
		}

		if (!$node instanceof File) {
			throw new NotFoundException('请选择文件，不支持文件夹');
		}

		return [
			'path' => '/' . ltrim($node->getPath(), '/'),
			'name' => $node->getName(),
			'size' => $node->getSize(),
			'id' => (int)$node->getId(),
		];
	}

	private function generateShareId(): string {
		return bin2hex(random_bytes(8));
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function getShareEntity(string $shareId): Share {
		return $this->shareMapper->findByShareId($shareId);
	}

	/**
	 * @return array{success: true, share: array<string, mixed>}|array{success: false, error: string}
	 */
	public function getShareSettings(string $shareId, string $userId): array {
		try {
			$share = $this->shareMapper->findOwnedByShareId($shareId, $userId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return ['success' => false, 'error' => '分享不存在或无权访问'];
		}

		return [
			'success' => true,
			'share' => $this->shareToSettingsArray($share, $userId),
		];
	}

	/**
	 * @return array{success: true, share_id: string, share_url: string}|array{success: false, error: string}
	 */
	public function updateShare(string $shareId, string $userId, array $data): array {
		try {
			$share = $this->shareMapper->findOwnedByShareId($shareId, $userId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return ['success' => false, 'error' => '分享不存在或无权访问'];
		}

		if ($share->getStatus() !== 'active') {
			return ['success' => false, 'error' => '该分享已停用，无法编辑'];
		}

		$title = trim((string)($data['title'] ?? ''));
		$price = (int)($data['price'] ?? 0);
		$accessDays = (int)($data['access_days'] ?? 30);
		$hasExpireDays = array_key_exists('share_expire_days', $data);
		$shareExpireDays = $hasExpireDays ? (int)$data['share_expire_days'] : null;

		if ($title === '') {
			return ['success' => false, 'error' => '请填写分享标题'];
		}
		if ($price <= 0) {
			return ['success' => false, 'error' => '价格必须为正整数（单位：分）'];
		}

		$minPrice = (int)$this->config->getAppValue('sharegate', 'min_price', '1');
		$maxAccessDays = (int)$this->config->getAppValue('sharegate', 'max_access_days', '365');
		$price = max($price, $minPrice);
		$accessDays = min(max($accessDays, 1), $maxAccessDays);

		$now = (int)(microtime(true) * 1000);
		$expireAt = $share->getExpireAt();
		if ($hasExpireDays) {
			if ($shareExpireDays !== null && $shareExpireDays > 0) {
				$expireAt = $now + $shareExpireDays * 86400000;
			} else {
				$expireAt = null;
			}
		}

		$share->setTitle($title);
		$share->setDescription((string)($data['description'] ?? $share->getDescription()));
		$share->setPrice($price);
		$share->setAccessDays($accessDays);
		$share->setExpireAt($expireAt);

		try {
			$this->shareMapper->update($share);
		} catch (Exception $e) {
			return ['success' => false, 'error' => '保存失败: ' . $e->getMessage()];
		}

		return [
			'success' => true,
			'share_id' => $shareId,
			'share_url' => $this->urlGenerator->linkToRouteAbsolute('sharegate.share.view', ['shareId' => $shareId]),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function shareToSettingsArray(Share $share, string $userId): array {
		$shareId = $share->getShareId();
		$expireAt = $share->getExpireAt();
		$expireDays = null;
		if ($expireAt !== null) {
			$now = (int)(microtime(true) * 1000);
			if ($expireAt > $now) {
				$expireDays = (int)ceil(($expireAt - $now) / 86400000);
			} else {
				$expireDays = 0;
			}
		}

		return [
			'share_id' => $shareId,
			'file_path' => $this->toUserRelativePath($userId, $share->getFilePath()),
			'file_name' => $share->getFileName(),
			'file_id' => $share->getFileId(),
			'title' => $share->getTitle(),
			'description' => $share->getDescription(),
			'price' => $share->getPrice(),
			'access_days' => $share->getAccessDays(),
			'share_expire_days' => $expireDays,
			'status' => $share->getStatus(),
			'share_url' => $this->urlGenerator->linkToRouteAbsolute('sharegate.share.view', ['shareId' => $shareId]),
		];
	}

	private function toUserRelativePath(string $userId, string $storedPath): string {
		return UserFilePath::toUserRelative($userId, $storedPath);
	}

	/**
	 * @return array{success: true, share_id: string}|array{success: false, error: string}
	 */
	public function disableShare(string $shareId, string $userId): array {
		try {
			$share = $this->shareMapper->findOwnedByShareId($shareId, $userId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return ['success' => false, 'error' => '分享不存在或无权访问'];
		}
		if ($share->getStatus() !== 'active') {
			return ['success' => false, 'error' => '该分享已停用'];
		}
		try {
			$this->shareMapper->disableShare($shareId);
		} catch (\OCP\DB\Exception $e) {
			return ['success' => false, 'error' => '停用失败: ' . $e->getMessage()];
		}
		return ['success' => true, 'share_id' => $shareId];
	}
}
