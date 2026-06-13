<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * 同一 NC 实例内：将已授权文件复制到当前登录用户网盘。
 */
class SaveToCloudService {
	private const DEST_FOLDER = 'ShareGate';

	public function __construct(
		private DownloadService $downloadService,
		private ShareService $shareService,
		private ShareStatsService $shareStatsService,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * @return array{success: true, path: string, file_name: string}|array{success: false, error: string}
	 */
	public function saveToCloud(string $shareId, string $providerUserId, string $targetUserId): array {
		if ($targetUserId === '') {
			return ['success' => false, 'error' => '请先登录 Nextcloud 后再转存'];
		}

		$verify = $this->downloadService->verifyDownload($shareId, $providerUserId);
		if (!($verify['success'] ?? false)) {
			return [
				'success' => false,
				'error' => (string)($verify['message'] ?? $verify['error'] ?? '没有转存权限，请先支付'),
			];
		}

		try {
			$share = $this->shareService->getShareEntity($shareId);
			$source = $this->downloadService->resolveShareFile($share);
			$destFolder = $this->resolveDestFolder($targetUserId);
			$destName = $this->uniqueFileName($destFolder, $source->getName());
			$source->copy($destFolder, $destName);
			$this->shareStatsService->recordSave($shareId);
			$relative = self::DEST_FOLDER . '/' . $destName;
			return [
				'success' => true,
				'path' => $relative,
				'file_name' => $destName,
			];
		} catch (NotFoundException $e) {
			return ['success' => false, 'error' => '文件不存在: ' . $e->getMessage()];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => '转存失败: ' . $e->getMessage()];
		}
	}

	private function resolveDestFolder(string $userId): Folder {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if (!$userFolder->nodeExists(self::DEST_FOLDER)) {
			return $userFolder->newFolder(self::DEST_FOLDER);
		}
		$node = $userFolder->get(self::DEST_FOLDER);
		if (!$node instanceof Folder) {
			throw new NotFoundException('ShareGate 目录不可用');
		}
		return $node;
	}

	private function uniqueFileName(Folder $folder, string $name): string {
		if (!$folder->nodeExists($name)) {
			return $name;
		}
		$dot = strrpos($name, '.');
		$base = $dot !== false ? substr($name, 0, $dot) : $name;
		$ext = $dot !== false ? substr($name, $dot) : '';
		$candidate = $base . ' (1)' . $ext;
		$n = 1;
		while ($folder->nodeExists($candidate)) {
			$n++;
			$candidate = $base . ' (' . $n . ')' . $ext;
		}
		return $candidate;
	}
}
