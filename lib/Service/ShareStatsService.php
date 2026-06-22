<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Db\PaymentMapper;
use OCA\ShareGate\Db\Share;
use OCA\ShareGate\Db\ShareMapper;
use OCA\ShareGate\Db\ShareStatsMapper;
use OCA\ShareGate\Util\ShareFileResolver;
use OCP\IL10N;

class ShareStatsService {
	public function __construct(
		private ShareMapper $shareMapper,
		private ShareStatsMapper $shareStatsMapper,
		private PaymentMapper $paymentMapper,
		private ShareFileResolver $shareFileResolver,
		private IL10N $l,
	) {
	}

	public function recordPreview(string $shareId): void {
		try {
			$this->shareStatsMapper->increment($shareId, 'preview_count');
		} catch (\Throwable $e) {
			// 统计失败不影响主流程
		}
	}

	public function recordDownload(string $shareId): void {
		try {
			$this->shareStatsMapper->increment($shareId, 'download_count');
		} catch (\Throwable $e) {
		}
	}

	public function recordSave(string $shareId): void {
		try {
			$this->shareStatsMapper->increment($shareId, 'save_count');
		} catch (\Throwable $e) {
		}
	}

	/**
	 * @return array{success: true, items: list<array<string, mixed>>, total: int}
	 */
	public function listForSeller(string $userId): array {
		try {
			$shares = $this->shareMapper->findByUser($userId);
			$shareIds = array_map(static fn (Share $s) => $s->getShareId(), $shares);
			$statsMap = $shareIds === [] ? [] : $this->shareStatsMapper->findByShareIds($shareIds);
			$revenueMap = $shareIds === [] ? [] : $this->paymentMapper->sumPaidAmountByShareIds($shareIds);

			$items = [];
			foreach ($shares as $share) {
				try {
					$sid = $share->getShareId();
					$stats = $statsMap[$sid] ?? null;
					$fileMeta = $this->fileMetaForShare($share);
					$items[] = [
						'share_id' => $sid,
						'file_name' => $share->getFileName(),
						'file_path' => $share->getFilePath(),
						'file_id' => $fileMeta['file_id'],
						'mime_type' => $fileMeta['mime_type'],
						'file_mtime' => $fileMeta['file_mtime'],
						'share_status_label' => $this->shareStatusLabel($share),
						'created_at' => $share->getCreatedAt(),
						'price' => $share->getPrice(),
						'revenue' => $revenueMap[$sid] ?? 0,
						'preview_count' => $stats?->getPreviewCount() ?? 0,
						'save_count' => $stats?->getSaveCount() ?? 0,
						'download_count' => $stats?->getDownloadCount() ?? 0,
					];
				} catch (\Throwable) {
					continue;
				}
			}

			return [
				'success' => true,
				'items' => $items,
				'total' => count($items),
			];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $this->l->t('Statistics API failed: %s', [$e->getMessage()])];
		}
	}

	public function countForSeller(string $userId): int {
		return count($this->shareMapper->findByUser($userId));
	}

	private function shareStatusLabel(Share $share): string {
		if ($share->getStatus() !== 'active') {
			return 'disabled';
		}
		$expireAt = $share->getExpireAt();
		if ($expireAt !== null && $expireAt <= (int)(microtime(true) * 1000)) {
			return 'expired';
		}
		if ($expireAt === null) {
			return 'permanent';
		}
		return 'limited';
	}

	/**
	 * @return array{file_id: int, mime_type: string, file_mtime: ?int}
	 */
	private function fileMetaForShare(Share $share): array {
		$empty = ['file_id' => 0, 'mime_type' => '', 'file_mtime' => null];
		if ($share->getFileId() > 0) {
			$empty['file_id'] = $share->getFileId();
		}

		$file = $this->shareFileResolver->tryResolve($share);
		if ($file === null) {
			return $empty;
		}

		try {
			return [
				'file_id' => (int)$file->getId(),
				'mime_type' => $file->getMimeType(),
				'file_mtime' => (int)$file->getMTime() * 1000,
			];
		} catch (\Throwable) {
			return $empty;
		}
	}
}
