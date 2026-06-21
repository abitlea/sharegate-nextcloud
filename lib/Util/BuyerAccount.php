<?php

declare(strict_types=1);

namespace OCA\ShareGate\Util;

/**
 * Buyer identity for purchase history: payment provider account after pay
 * (Alipay logon, Stripe/PayPal email, etc.), not Nextcloud login.
 */
final class BuyerAccount {
	public static function normalizePayerId(string $payerId): ?string {
		$payerId = trim($payerId);
		if ($payerId === '' || strlen($payerId) > 128) {
			return null;
		}
		if (self::isBrowserSessionId($payerId)) {
			return null;
		}
		if (preg_match('/^[^\s<>"\'`;]+$/u', $payerId)) {
			return $payerId;
		}
		return null;
	}

	/** Grant holder id (payment account or browser session after checkout). */
	public static function normalizeGrantHolderId(string $id): ?string {
		$id = trim($id);
		if ($id === '' || strlen($id) > 128) {
			return null;
		}
		if (self::isBrowserSessionId($id)) {
			return $id;
		}
		return self::normalizePayerId($id);
	}

	/** Anonymous checkout session id — not a payment account for purchase history. */
	public static function isBrowserSessionId(string $id): bool {
		return preg_match('/^buyer_[a-zA-Z0-9]+$/', trim($id)) === 1;
	}

	/** Normalize id values that may arrive as int from PHP array keys or JSON. */
	public static function coerceIdString(string|int|float $id): string {
		return trim((string)$id);
	}

	public static function isPlaceholderPayer(string $id): bool {
		return in_array(trim($id), ['alipay_user', 'stripe_customer', 'paypal_user'], true);
	}

	/** Alipay user id returned by payment APIs (2088…, not masked). */
	public static function isAlipayUid(string $id): bool {
		return preg_match('/^2088\d{12,}$/', trim($id)) === 1;
	}

	/** buyer_logon_id from Alipay is always masked (e.g. sbi***@sandbox.com). */
	public static function isMaskedAlipayLogon(string $id): bool {
		return str_contains(trim($id), '***');
	}

	public static function matchesMaskedAlipayLogon(string $masked, string $full): bool {
		$masked = trim($masked);
		$full = trim($full);
		if ($masked === '' || $full === '') {
			return false;
		}
		if ($masked === $full) {
			return true;
		}
		if (!self::isMaskedAlipayLogon($masked)) {
			return false;
		}
		$starsPos = strpos($masked, '***');
		if ($starsPos === false) {
			return false;
		}
		$prefix = substr($masked, 0, $starsPos);
		$suffix = substr($masked, $starsPos + 3);
		return $prefix !== ''
			&& $suffix !== ''
			&& str_starts_with($full, $prefix)
			&& str_ends_with($full, $suffix)
			&& strlen($full) > strlen($prefix) + strlen($suffix);
	}

	/**
	 * @param list<string> $grantHolderIds
	 */
	public static function resolveAlipayUid(array $grantHolderIds): ?string {
		foreach ($grantHolderIds as $id) {
			$id = trim((string)$id);
			if (self::isAlipayUid($id)) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Seller ledger display: prefer Alipay UID when logon is masked.
	 *
	 * @param list<string> $grantHolderIds
	 */
	public static function resolveLedgerPayerAccount(array $grantHolderIds, string $provider): ?string {
		$account = self::resolvePayerAccountId($grantHolderIds);
		if ($provider !== 'alipay_f2f') {
			return $account;
		}
		$uid = self::resolveAlipayUid($grantHolderIds);
		if ($uid !== null && ($account === null || self::isMaskedAlipayLogon($account))) {
			return $uid;
		}
		return $account;
	}

	/**
	 * Masked buyer_logon_id when Alipay UID is shown separately.
	 *
	 * @param list<string> $grantHolderIds
	 */
	public static function resolveMaskedAlipayLogon(array $grantHolderIds): ?string {
		foreach ($grantHolderIds as $id) {
			$id = trim((string)$id);
			if ($id !== '' && self::isMaskedAlipayLogon($id)) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Real payment account from grant holder ids (Alipay logon, Stripe/PayPal email, etc.).
	 *
	 * @param list<string> $grantHolderIds
	 */
	public static function resolvePayerAccountId(array $grantHolderIds): ?string {
		foreach ($grantHolderIds as $id) {
			$normalized = self::normalizePayerId((string)$id);
			if ($normalized !== null && !self::isPlaceholderPayer($normalized)) {
				return $normalized;
			}
		}
		return null;
	}

	/**
	 * Payer id for APIs: payment account when known, else browser session / client id.
	 *
	 * @param list<string> $grantHolderIds
	 */
	public static function resolvePayerId(array $grantHolderIds, ?string $clientUserId = null): string {
		$account = self::resolvePayerAccountId($grantHolderIds);
		if ($account !== null) {
			return $account;
		}
		foreach ($grantHolderIds as $id) {
			$id = trim((string)$id);
			if ($id !== '' && !self::isPlaceholderPayer($id)) {
				return $id;
			}
		}
		return trim((string)($clientUserId ?? ''));
	}

	/**
	 * @param list<mixed> $ids
	 * @return list<string>
	 */
	public static function normalizeGrantHolderIds(array $ids): array {
		$unique = [];
		foreach ($ids as $id) {
			$normalized = self::normalizeGrantHolderId(self::coerceIdString($id));
			if ($normalized === null || self::isBrowserSessionId($normalized)) {
				continue;
			}
			$unique[$normalized] = true;
		}
		return array_keys($unique);
	}

	/**
	 * @param list<mixed> $ids
	 * @return list<string>
	 */
	public static function normalizePayerIds(array $ids): array {
		$unique = [];
		foreach ($ids as $id) {
			$normalized = self::normalizePayerId(self::coerceIdString($id));
			if ($normalized !== null) {
				$unique[$normalized] = true;
			}
		}
		return array_keys($unique);
	}

	/** @deprecated Use normalizeGrantHolderId for buyer_xxx session ids */
	public static function normalize(string $buyerId): ?string {
		return self::normalizeGrantHolderId($buyerId);
	}
}
