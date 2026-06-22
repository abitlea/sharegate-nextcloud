<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Db\PaymentMapper;
use OCA\ShareGate\Db\Share;
use OCA\ShareGate\Db\ShareMapper;
use OCA\ShareGate\Util\ShareFileResolver;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * 用户管理台：分享列表、过滤器计数、统计与账户绑定摘要。
 */
class DashboardService {
	/** 所有公开链接（仍有效） */
	public const FILTER_ALL = 'all';
	/** 有效付费分享（active 且未过期，含待首笔收款） */
	public const FILTER_ACTIVE = 'active';
	/** 侧栏「统计相关」角标：仍有效但尚无收款 */
	public const FILTER_STATS = 'stats';

	public function __construct(
		private ShareMapper $shareMapper,
		private PaymentMapper $paymentMapper,
		private PaymentConfigService $paymentConfig,
		private IURLGenerator $urlGenerator,
		private ShareFileResolver $shareFileResolver,
		private PublicLinkService $publicLinkService,
		private ShareStatsService $shareStatsService,
		private IL10N $l,
	) {
	}

	/**
	 * @return array<string, int>
	 */
	public function getFilterCounts(string $userId): array {
		return [
			self::FILTER_ALL => $this->publicLinkService->countFiles($userId),
			self::FILTER_ACTIVE => $this->shareMapper->countActiveByUserForList($userId, ''),
			self::FILTER_STATS => $this->shareStatsService->countForSeller($userId),
		];
	}

	/**
	 * @return array{success: true, stats: array<string, mixed>}|array{success: false, error: string}
	 */
	public function getSellerStats(string $userId): array {
		$shares = $this->shareMapper->findByUser($userId);
		$shareIds = array_map(static fn (Share $s) => $s->getShareId(), $shares);
		$paidCounts = $this->paymentMapper->countPaidByShareIds($shareIds);
		$sums = $this->paymentMapper->sumPaidForShareIds($shareIds);

		$publicLinks = 0;
		$withRevenue = 0;
		$awaitingFirst = 0;

		foreach ($shares as $share) {
			if (!$this->isUseful($share)) {
				continue;
			}
			$publicLinks++;
			$paid = $paidCounts[$share->getShareId()] ?? 0;
			if ($paid > 0) {
				$withRevenue++;
			} else {
				$awaitingFirst++;
			}
		}

		$recentPayments = $this->paymentMapper->findPaidByShareIds($shareIds);
		$shareTitles = [];
		foreach ($shares as $s) {
			$shareTitles[$s->getShareId()] = $s->getTitle();
		}
		foreach ($recentPayments as &$p) {
			$p['title'] = $shareTitles[$p['share_id']] ?? $p['share_id'];
		}
		unset($p);

		return [
			'success' => true,
			'stats' => [
				'public_links' => $publicLinks,
				'links_with_revenue' => $withRevenue,
				'awaiting_first_payment' => $awaitingFirst,
				'paid_orders' => $sums['paid_orders'],
				'total_amount' => $sums['total_amount'],
				'recent_payments' => $recentPayments,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getAccountBindingSummary(bool $isAdmin): array {
		$summary = $this->paymentConfig->getAdminSummary();
		$alipay = $summary['alipay_f2f'];
		$mode = $summary['payment_mode'];

		$binding = [
			'payment_mode' => $mode,
			'effective_provider' => $summary['effective_provider'],
			'effective_provider_label' => $summary['effective_provider_label'] ?? $summary['effective_provider'],
			'payment_flow' => $summary['payment_flow'] ?? 'qrcode',
			'display_currency' => $summary['display_currency'] ?? 'CNY',
			'providers' => $summary['providers'] ?? [],
			'alipay_configured' => $alipay['configured'] && $alipay['alipay_public_key'] !== '',
			'stripe_configured' => ($summary['stripe']['configured'] ?? false),
			'paypal_configured' => ($summary['paypal']['configured'] ?? false),
			'alipay_sandbox' => $alipay['sandbox'],
			'notify_url' => $alipay['notify_url'],
			'is_admin' => $isAdmin,
		];

		if ($isAdmin) {
			$binding['alipay_app_id'] = $alipay['app_id'];
		}

		return $binding;
	}

	/**
	 * @return array{success: true, items: list<array<string, mixed>>, total: int}|array{success: false, error: string}
	 */
	public function listShares(
		string $userId,
		string $filter,
		string $query,
		int $limit,
		int $offset,
	): array {
		try {
			if (function_exists('set_time_limit')) {
				@set_time_limit(120);
			}

			// 付费分享页：DB 分页 + 仅对当前页查支付统计，避免全量 I/O/SQL 导致空 500
			if ($filter === self::FILTER_ACTIVE || $filter === self::FILTER_ALL) {
				return $this->listActiveSharesPaginated($userId, $query, $limit, $offset);
			}

			return ['success' => false, 'error' => $this->l->t('Unsupported filter')];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $this->l->t('Share list failed: %s', [$e->getMessage()])];
		}
	}

	/**
	 * @return array{success: true, items: list<array<string, mixed>>, total: int}
	 */
	private function listActiveSharesPaginated(
		string $userId,
		string $query,
		int $limit,
		int $offset,
	): array {
		$total = $this->shareMapper->countActiveByUserForList($userId, $query);
		$shares = $this->shareMapper->findActiveByUserForList($userId, $query, $limit, $offset);
		$shareIds = array_map(static fn (Share $s) => $s->getShareId(), $shares);
		$paidCounts = $this->loadPaidCounts($shareIds);
		$lastPaid = $this->loadLastPaidAt($shareIds);

		$items = [];
		foreach ($shares as $share) {
			try {
				$shareId = $share->getShareId();
				$paid = $paidCounts[$shareId] ?? 0;
				$items[] = $this->serializeShareForList($share, $paid, $lastPaid[$shareId] ?? null);
			} catch (\Throwable) {
				continue;
			}
		}

		return [
			'success' => true,
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * @param list<string> $shareIds
	 * @return array<string, int>
	 */
	private function loadPaidCounts(array $shareIds): array {
		if ($shareIds === []) {
			return [];
		}
		$counts = [];
		foreach (array_chunk($shareIds, 200) as $chunk) {
			try {
				$counts += $this->paymentMapper->countPaidByShareIds($chunk);
			} catch (\Throwable) {
				// 支付统计失败不阻断列表
			}
		}
		return $counts;
	}

	/**
	 * @param list<string> $shareIds
	 * @return array<string, int>
	 */
	private function loadLastPaidAt(array $shareIds): array {
		if ($shareIds === []) {
			return [];
		}
		$map = [];
		foreach (array_chunk($shareIds, 200) as $chunk) {
			try {
				$map += $this->paymentMapper->lastPaidAtByShareIds($chunk);
			} catch (\Throwable) {
				// 支付统计失败不阻断列表
			}
		}
		return $map;
	}

	private function matchesSearch(Share $share, string $query): bool {
		$q = $this->lower($query);
		$haystack = $this->lower(
			$share->getTitle() . ' ' . $share->getFileName() . ' ' . $share->getShareId(),
		);
		return str_contains($haystack, $q);
	}

	private function matchesFilter(Share $share, string $filter, int $paidCount): bool {
		return match ($filter) {
			self::FILTER_ALL => $this->isUseful($share),
			self::FILTER_ACTIVE => $this->isUseful($share),
			default => false,
		};
	}

	private function isUseful(Share $share): bool {
		if ($share->getStatus() !== 'active') {
			return false;
		}
		$expireAt = $share->getExpireAt();
		if ($expireAt === null) {
			return true;
		}
		return $expireAt > $this->nowMs();
	}

	/**
	 * 管理台列表：不探测网盘文件，避免 N 次 I/O 导致超时/空响应。
	 *
	 * @return array<string, mixed>
	 */
	private function serializeShareForList(Share $share, int $paidCount, ?int $lastPaidAt): array {
		$shareId = $share->getShareId();
		$filePath = $share->getFilePath();

		return [
			'file_size' => $share->getFileSize(),
			'share_id' => $shareId,
			'title' => $this->safeText($share->getTitle()),
			'file_name' => $this->safeText($share->getFileName()),
			'file_mtime' => null,
			'file_path' => $filePath,
			'file_id' => $share->getFileId() > 0
				? $share->getFileId()
				: $this->safeFileIdForShare($share),
			'folder' => $this->extractFolder($filePath),
			'price' => $share->getPrice(),
			'status' => $share->getStatus(),
			'display_status' => $this->displayStatus($share, $paidCount),
			'file_exists' => null,
			'expire_at' => $share->getExpireAt(),
			'created_at' => $share->getCreatedAt(),
			'share_url' => $this->urlGenerator->linkToRouteAbsolute(
				'sharegate.share.view',
				['shareId' => $shareId],
			),
			'settings_url' => $this->urlGenerator->linkToRoute('sharegate.dashboard.index')
				. '?edit=' . rawurlencode($shareId)
				. '#paid',
			'payment_count' => $paidCount,
			'last_paid_at' => $lastPaidAt,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serializeShare(Share $share, int $paidCount, ?int $lastPaidAt): array {
		$row = $this->serializeShareForList($share, $paidCount, $lastPaidAt);
		$row['file_mtime'] = $this->getFileMTime($share);
		$row['file_exists'] = $this->checkFileExists($share);
		return $row;
	}

	private function displayStatus(Share $share, int $paidCount): string {
		if ($share->getStatus() !== 'active') {
			return 'disabled';
		}
		$expireAt = $share->getExpireAt();
		if ($expireAt !== null && $expireAt <= $this->nowMs()) {
			return 'expired';
		}
		if ($paidCount === 0) {
			return 'awaiting_payment';
		}
		return 'active';
	}

	private function extractFolder(string $filePath): string {
		$parts = explode('/', trim($filePath, '/'));
		if ($parts !== []) {
			array_pop($parts);
		}
		$filesIdx = array_search('files', $parts, true);
		if ($filesIdx !== false) {
			$parts = array_slice($parts, $filesIdx + 2);
		}
		return $parts === [] ? '/' : implode('/', $parts);
	}

	private function safeFileIdForShare(Share $share): int {
		if ($share->getFileId() > 0) {
			return $share->getFileId();
		}

		$file = $this->shareFileResolver->tryResolve($share);
		return $file !== null ? (int)$file->getId() : 0;
	}

	private function getFileMTime(Share $share): ?int {
		$file = $this->shareFileResolver->tryResolve($share);
		if ($file === null) {
			return null;
		}

		try {
			return (int)$file->getMTime() * 1000;
		} catch (\Throwable) {
			return null;
		}
	}

	private function checkFileExists(Share $share): bool {
		return $this->shareFileResolver->tryResolve($share) !== null;
	}

	private function nowMs(): int {
		return (int)(microtime(true) * 1000);
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
		if (function_exists('iconv')) {
			$clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
			if ($clean !== false) {
				return $clean;
			}
		}
		if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
			$converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
			return is_string($converted) ? $converted : '';
		}
		return $text;
	}
}
