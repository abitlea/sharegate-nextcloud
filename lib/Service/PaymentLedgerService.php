<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\Db\AccessGrant;
use OCA\ShareGate\Db\AccessGrantMapper;
use OCA\ShareGate\Db\Payment;
use OCA\ShareGate\Db\PaymentMapper;
use OCA\ShareGate\Db\Share;
use OCA\ShareGate\Db\ShareMapper;
use OCA\ShareGate\Util\BuyerAccount;
use OCA\ShareGate\Payment\AlipayF2fProvider;
use OCA\ShareGate\Payment\MockPaymentProvider;
use OCA\ShareGate\Payment\PayPalProvider;
use OCA\ShareGate\Payment\StripeProvider;
use OCP\DB\Exception;
use OCP\IL10N;

/**
 * Seller-facing payment order ledger (per-order detail).
 */
class PaymentLedgerService {
	public const STATUS_ALL = 'all';

	public function __construct(
		private ShareMapper $shareMapper,
		private PaymentMapper $paymentMapper,
		private AccessGrantMapper $accessGrantMapper,
		private PaymentConfigService $paymentConfig,
		private AlipayF2fProvider $alipayProvider,
		private IL10N $l,
	) {
	}

	/**
	 * @return array{success: true, items: list<array<string, mixed>>, total: int}|array{success: false, error: string}
	 */
	public function listForSeller(
		string $userId,
		string $statusFilter,
		string $search,
		int $limit,
		int $offset,
	): array {
		if ($userId === '') {
			return ['success' => false, 'error' => $this->l->t('Please log in to Nextcloud')];
		}

		$status = $this->normalizeStatusFilter($statusFilter);
		if ($status === null) {
			return ['success' => false, 'error' => $this->l->t('Invalid payment status filter')];
		}

		try {
			$shares = $this->shareMapper->findByUser($userId);
			$shareIds = array_map(static fn (Share $s) => $s->getShareId(), $shares);
			$shareMap = [];
			foreach ($shares as $share) {
				$shareMap[$share->getShareId()] = $share;
			}

			$dbStatus = $status === self::STATUS_ALL ? null : $status;
			$payments = $this->paymentMapper->findLedgerByShareIds(
				$shareIds,
				$dbStatus,
				$search,
				$limit,
				$offset,
			);
			$total = $this->paymentMapper->countLedgerByShareIds($shareIds, $dbStatus, $search);

			/** @var array<string, string> */
			$alipayMaskedLogonCache = [];
			$items = array_map(
				fn (Payment $payment) => $this->formatRow(
					$payment,
					$shareMap[$payment->getShareId()] ?? null,
					$alipayMaskedLogonCache,
				),
				$payments,
			);

			return [
				'success' => true,
				'items' => $items,
				'total' => $total,
			];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $this->l->t('Failed to load payment ledger: %s', [$e->getMessage()])];
		}
	}

	public function countForSeller(string $userId): int {
		if ($userId === '') {
			return 0;
		}
		try {
			$shares = $this->shareMapper->findByUser($userId);
			$shareIds = array_map(static fn (Share $s) => $s->getShareId(), $shares);
			return $this->paymentMapper->countLedgerByShareIds($shareIds, null, '');
		} catch (Exception) {
			return 0;
		}
	}

	/**
	 * @param array<string, string> $alipayMaskedLogonCache
	 * @return array<string, mixed>
	 */
	private function formatRow(Payment $payment, ?Share $share, array &$alipayMaskedLogonCache = []): array {
		$status = $payment->getStatus();
		$message = trim((string)($payment->getStatusMessage() ?? ''));
		try {
			$grants = $this->accessGrantMapper->findLedgerGrantsForPayment($payment);
		} catch (Exception) {
			$grants = [];
		}
		$grantHolderIds = array_map(static fn (AccessGrant $grant) => $grant->getProviderUserId(), $grants);
		$payerAccount = BuyerAccount::resolveLedgerPayerAccount($grantHolderIds, $payment->getProvider()) ?? '';
		$payerLogonMasked = $this->resolvePayerLogonMasked($payment, $grantHolderIds, $alipayMaskedLogonCache);
		if ($payerLogonMasked !== '' && $payerLogonMasked === $payerAccount) {
			$payerLogonMasked = '';
		}

		return [
			'order_id' => $payment->getOrderId(),
			'share_id' => $payment->getShareId(),
			'share_title' => $share !== null ? $share->getTitle() : '',
			'file_name' => $share !== null ? $share->getFileName() : '',
			'amount' => $payment->getAmount(),
			'amount_display' => $this->paymentConfig->formatPrice($payment->getAmount()),
			'provider' => $payment->getProvider(),
			'provider_label' => $this->providerLabel($payment->getProvider()),
			'provider_order_id' => $payment->getProviderOrderId(),
			'buyer_id' => $payment->getClientUserId(),
			'payer_account' => $payerAccount,
			'payer_logon_masked' => $payerLogonMasked,
			'status' => $status,
			'status_label' => $this->statusLabel($status),
			'status_message' => $message,
			'failure_reason' => in_array($status, ['failed', 'cancelled', 'refunded'], true) ? $message : '',
			'created_at' => $payment->getCreatedAt(),
			'paid_at' => $payment->getPaidAt(),
			'refunded_at' => $payment->getRefundedAt(),
		];
	}

	private function providerLabel(string $provider): string {
		return match ($provider) {
			StripeProvider::NAME => $this->l->t('Stripe'),
			PayPalProvider::NAME => $this->l->t('PayPal'),
			AlipayF2fProvider::NAME => $this->l->t('Alipay Face-to-Face'),
			MockPaymentProvider::NAME => $this->l->t('Mock payment'),
			default => $provider !== '' ? $provider : $this->l->t('Unknown'),
		};
	}

	private function statusLabel(string $status): string {
		return match ($status) {
			'paid' => $this->l->t('Paid'),
			'pending' => $this->l->t('Pending'),
			'failed' => $this->l->t('Failed'),
			'cancelled' => $this->l->t('Cancelled'),
			'refunded' => $this->l->t('Refunded'),
			default => $status,
		};
	}

	private function normalizeStatusFilter(string $filter): ?string {
		$filter = strtolower(trim($filter));
		if ($filter === '' || $filter === self::STATUS_ALL) {
			return self::STATUS_ALL;
		}
		if (in_array($filter, ['paid', 'pending', 'failed', 'cancelled', 'refunded'], true)) {
			return $filter;
		}
		return null;
	}

	/**
	 * @param list<string> $grantHolderIds
	 * @param array<string, string> $alipayMaskedLogonCache
	 */
	private function resolvePayerLogonMasked(Payment $payment, array $grantHolderIds, array &$alipayMaskedLogonCache): string {
		if ($payment->getProvider() !== AlipayF2fProvider::NAME) {
			return '';
		}
		$masked = BuyerAccount::resolveMaskedAlipayLogon($grantHolderIds);
		if ($masked !== null) {
			return $masked;
		}
		if ($payment->getStatus() !== 'paid') {
			return '';
		}
		$orderId = $payment->getOrderId();
		if (array_key_exists($orderId, $alipayMaskedLogonCache)) {
			return $alipayMaskedLogonCache[$orderId];
		}
		$logon = '';
		if ($this->alipayProvider->isAvailable()) {
			$query = $this->alipayProvider->queryOrder($orderId);
			if ($query['success'] ?? false) {
				$candidate = trim((string)($query['payer_user_id'] ?? ''));
				if ($candidate !== '' && BuyerAccount::isMaskedAlipayLogon($candidate)) {
					$logon = $candidate;
				}
			}
		}
		$alipayMaskedLogonCache[$orderId] = $logon;
		return $logon;
	}
}
