<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Db\PaymentMapper;
use OCA\ShareGate\Db\ShareMapper;
use OCA\ShareGate\Db\ShareStatsMapper;

/** 站点级统计（管理员 API） */
class AdminStatsService {
	public function __construct(
		private ShareMapper $shareMapper,
		private PaymentMapper $paymentMapper,
		private ShareStatsMapper $shareStatsMapper,
	) {
	}

	/**
	 * @return array{success: true, stats: array<string, int>}
	 */
	public function getGlobalStats(): array {
		$payments = $this->paymentMapper->sumAllPaid();
		$activity = $this->shareStatsMapper->sumTotals();

		return [
			'success' => true,
			'stats' => [
				'total_shares' => $this->shareMapper->countAll(),
				'active_shares' => $this->shareMapper->countActive(),
				'paid_orders' => $payments['paid_orders'],
				'total_revenue' => $payments['total_amount'],
				'total_previews' => $activity['preview_count'],
				'total_saves' => $activity['save_count'],
				'total_downloads' => $activity['download_count'],
			],
		];
	}
}
