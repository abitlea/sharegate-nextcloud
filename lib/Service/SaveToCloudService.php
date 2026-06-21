<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * 同一 NC 实例内：将已授权文件复制到当前登录用户网盘。
 */
class SaveToCloudService {
	private const DEST_FOLDER = 'ShareGate';

	private \OCP\IL10N $l;

	public function __construct(
		private DownloadService $downloadService,
		private ShareService $shareService,
		private ShareStatsService $shareStatsService,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private IUserSession $userSession,
		IFactory $l10nFactory,
	) {
		$this->l = $l10nFactory->get('sharegate');
	}

	/**
	 * @return array{success: true, path: string, file_name: string}|array{success: false, error: string}
	 */
	public function saveToCloud(string $shareId, ?string $providerUserId, string $targetUserId, ?string $accessToken = null): array {
		if ($targetUserId === '') {
			return [
				'success' => false,
				'error' => $this->l->t('Please log in to Nextcloud before saving to cloud'),
			];
		}

		$providerUserId = $providerUserId !== null ? trim($providerUserId) : '';
		$accessToken = $accessToken !== null ? trim($accessToken) : '';
		if ($providerUserId === '' && $accessToken === '') {
			return [
				'success' => false,
				'error' => $this->l->t('Missing provider_user_id'),
			];
		}

		$verify = $this->downloadService->verifyDownload(
			$shareId,
			$providerUserId !== '' ? $providerUserId : null,
			$accessToken !== '' ? $accessToken : null,
		);
		if (!($verify['success'] ?? false)) {
			return [
				'success' => false,
				'error' => $this->verifyErrorMessage($verify),
			];
		}

		$sourceStream = null;
		$destStream = null;

		try {
			$share = $this->shareService->getShareEntity($shareId);
			$ownerId = $share->getCreatedBy();

			/** @var array{name: string, stream: resource} $payload */
			$payload = $this->runAsShareOwner($ownerId, function () use ($share) {
				$source = $this->downloadService->resolveShareFile($share);
				$stream = $source->fopen('r');
				if ($stream === false) {
					throw new \RuntimeException($this->l->t('Unable to read source file'));
				}

				return [
					'name' => $source->getName(),
					'stream' => $stream,
				];
			});

			$sourceStream = $payload['stream'];
			$destFolder = $this->resolveDestFolder($targetUserId);
			$destName = $this->uniqueFileName($destFolder, $payload['name']);
			$destFile = $destFolder->newFile($destName);
			$destStream = $destFile->fopen('w');
			if ($destStream === false) {
				throw new \RuntimeException($this->l->t('Unable to write destination file'));
			}

			$copied = stream_copy_to_stream($sourceStream, $destStream);
			if ($copied === false) {
				throw new \RuntimeException($this->l->t('Unable to copy file to your cloud drive'));
			}

			$this->shareStatsService->recordSave($shareId);
			$relative = self::DEST_FOLDER . '/' . $destName;
			return [
				'success' => true,
				'path' => $relative,
				'file_name' => $destName,
			];
		} catch (NotFoundException $e) {
			return $this->failWithDetail($e->getMessage());
		} catch (\Throwable $e) {
			return $this->failWithDetail($e->getMessage());
		} finally {
			if (is_resource($sourceStream)) {
				fclose($sourceStream);
			}
			if (is_resource($destStream)) {
				fclose($destStream);
			}
		}
	}

	/**
	 * @param array<string, mixed> $verify
	 */
	private function verifyErrorMessage(array $verify): string {
		$detail = trim((string)($verify['error'] ?? $verify['message'] ?? ''));
		if ($detail === '') {
			return $this->l->t('Save to cloud failed');
		}

		return $this->l->t('Save to cloud failed') . ': ' . $detail;
	}

	/**
	 * @return array{success: false, error: string}
	 */
	private function failWithDetail(string $detail): array {
		$detail = trim($detail);
		if ($detail === '') {
			return ['success' => false, 'error' => $this->l->t('Save to cloud failed')];
		}

		return ['success' => false, 'error' => $this->l->t('Save to cloud failed') . ': ' . $detail];
	}

	/**
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

	private function resolveDestFolder(string $userId): Folder {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if (!$userFolder->nodeExists(self::DEST_FOLDER)) {
			return $userFolder->newFolder(self::DEST_FOLDER);
		}
		$node = $userFolder->get(self::DEST_FOLDER);
		if (!$node instanceof Folder) {
			throw new NotFoundException('ShareGate folder unavailable');
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
