<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Util\UserFilePath;
use OCA\ShareGate\Db\Share;
use OCA\ShareGate\Db\ShareMapper;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * 你的共享：仅列出用户已在 NC「文件」中生成公开链接的文件。
 */
class PublicLinkService {
	/** NC 公开链接类型，等同 {@see IShare::TYPE_LINK} */
	private const NC_SHARE_TYPE_LINK = 3;

	public function __construct(
		private ShareMapper $shareMapper,
		private IRootFolder $rootFolder,
		private IManager $shareManager,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @return array{success: true, items: list<array<string, mixed>>, total: int}|array{success: false, error: string}
	 */
	public function listFiles(string $userId, string $query, int $limit, int $offset): array {
		try {
			$shareIndex = $this->buildShareIndex($userId);
			$items = $this->collectPublicLinkedFiles($userId, $shareIndex);
			$queryLower = $this->lower($query);

			if ($queryLower !== '') {
				$items = array_values(array_filter(
					$items,
					fn (array $row): bool => str_contains(
						$this->lower((string)($row['file_name'] ?? '')),
						$queryLower,
					),
				));
			}

			usort($items, static function (array $a, array $b): int {
				return ($b['file_mtime'] ?? 0) <=> ($a['file_mtime'] ?? 0);
			});

			$total = count($items);
			$items = array_slice($items, $offset, $limit);

			return [
				'success' => true,
				'items' => $items,
				'total' => $total,
			];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => '读取你的共享失败: ' . $e->getMessage()];
		}
	}

	public function countFiles(string $userId): int {
		try {
			return count($this->collectPublicLinkedFiles($userId, $this->buildShareIndex($userId)));
		} catch (\Throwable) {
			return 0;
		}
	}

	/**
	 * @param array{by_full: array<string, Share>, by_relative: array<string, Share>} $shareIndex
	 * @return list<array<string, mixed>>
	 */
	private function collectPublicLinkedFiles(string $userId, array $shareIndex): array {
		$linkShares = $this->shareManager->getSharesBy(
			$userId,
			self::NC_SHARE_TYPE_LINK,
			null,
			false,
			-1,
			0,
			true,
		);

		/** @var array<int, array{share: IShare, node: File, share_time: int}> $byFileId */
		$byFileId = [];

		foreach ($linkShares as $share) {
			if ($share->isExpired()) {
				continue;
			}
			if ($share->getToken() === '') {
				continue;
			}
			try {
				$node = $share->getNode();
			} catch (NotFoundException) {
				continue;
			} catch (\Throwable) {
				continue;
			}
			if (!($node instanceof File)) {
				continue;
			}

			$fileId = $this->safeFileId($node);
			if ($fileId === 0) {
				continue;
			}

			$shareTime = (int)$share->getShareTime()->getTimestamp() * 1000;
			if (!isset($byFileId[$fileId]) || $shareTime > $byFileId[$fileId]['share_time']) {
				$byFileId[$fileId] = [
					'share' => $share,
					'node' => $node,
					'share_time' => $shareTime,
				];
			}
		}

		$items = [];
		foreach ($byFileId as $entry) {
			/** @var IShare $ncShare */
			$ncShare = $entry['share'];
			/** @var File $file */
			$file = $entry['node'];
			$relative = $this->relativePathFromStored($userId, $file->getPath());
			$sgShare = $this->matchShare($userId, $file, $relative, $shareIndex);

			$items[] = [
				'file_name' => $this->safeText($file->getName()),
				'file_size' => $this->safeFileSize($file),
				'file_mtime' => $this->safeMtime($file),
				'file_path' => $relative,
				'file_id' => $this->safeFileId($file),
				'mime_type' => $this->safeMimeType($file),
				'has_share' => $sgShare !== null,
				'share_id' => $sgShare?->getShareId(),
				'public_share_url' => $this->publicShareUrl($ncShare),
				'nc_share_token' => $ncShare->getToken(),
				'nc_share_time' => $entry['share_time'],
			];
		}

		return $items;
	}

	private function publicShareUrl(IShare $share): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'files_sharing.sharecontroller.showShare',
			['token' => $share->getToken()],
		);
	}

	/**
	 * @return array{by_full: array<string, Share>, by_relative: array<string, Share>}
	 */
	private function buildShareIndex(string $userId): array {
		$byFull = [];
		$byRelative = [];

		try {
			$shares = $this->shareMapper->findByUser($userId);
		} catch (\Throwable) {
			return ['by_full' => $byFull, 'by_relative' => $byRelative];
		}

		foreach ($shares as $share) {
			if (!$this->isUsefulShare($share)) {
				continue;
			}
			$stored = $this->normalizePath($share->getFilePath());
			$current = $byFull[$stored] ?? null;
			if ($current === null || $share->getCreatedAt() > $current->getCreatedAt()) {
				$byFull[$stored] = $share;
			}
			$relative = $this->relativePathFromStored($userId, $share->getFilePath());
			if ($relative !== '') {
				$relCurrent = $byRelative[$relative] ?? null;
				if ($relCurrent === null || $share->getCreatedAt() > $relCurrent->getCreatedAt()) {
					$byRelative[$relative] = $share;
				}
			}
		}

		return ['by_full' => $byFull, 'by_relative' => $byRelative];
	}

	/**
	 * @param array{by_full: array<string, Share>, by_relative: array<string, Share>} $index
	 */
	private function matchShare(string $userId, Node $file, string $relative, array $index): ?Share {
		$full = $this->normalizePath($file->getPath());
		if (isset($index['by_full'][$full])) {
			return $index['by_full'][$full];
		}
		if ($relative !== '' && isset($index['by_relative'][$relative])) {
			return $index['by_relative'][$relative];
		}
		return null;
	}

	private function isUsefulShare(Share $share): bool {
		if ($share->getStatus() !== 'active') {
			return false;
		}
		$expireAt = $share->getExpireAt();
		if ($expireAt === null) {
			return true;
		}
		return $expireAt > (int)(microtime(true) * 1000);
	}

	private function relativePathFromStored(string $userId, string $storedPath): string {
		return UserFilePath::toUserRelative($userId, $storedPath);
	}

	private function normalizePath(string $path): string {
		return '/' . trim($path, '/');
	}

	private function lower(string $text): string {
		if (function_exists('mb_strtolower')) {
			return mb_strtolower($text);
		}
		return strtolower($text);
	}

	private function safeText(string $text): string {
		if ($text === '') {
			return '';
		}
		if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
			return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}
		return $text;
	}

	private function safeFileSize(File $file): int {
		try {
			return (int)$file->getSize();
		} catch (\Throwable) {
			return 0;
		}
	}

	private function safeMtime(File $file): int {
		try {
			return (int)$file->getMTime() * 1000;
		} catch (\Throwable) {
			return 0;
		}
	}

	private function safeFileId(File $file): int {
		try {
			return (int)$file->getId();
		} catch (\Throwable) {
			return 0;
		}
	}

	private function safeMimeType(File $file): string {
		try {
			return (string)$file->getMimeType();
		} catch (\Throwable) {
			return 'application/octet-stream';
		}
	}
}
