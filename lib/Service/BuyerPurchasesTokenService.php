<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\AppInfo\Application;
use OCA\ShareGate\Db\AccessGrantMapper;
use OCA\ShareGate\Util\BuyerAccount;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * Signed token for buyer purchase list (one or more payer ids + expiry).
 */
class BuyerPurchasesTokenService {
	private const CONFIG_SECRET = 'purchases_token_secret';
	private const VERSION = 'p1';
	private const DEFAULT_TTL_MS = 7_776_000_000; // 90 days

	public function __construct(
		private IConfig $config,
		private ISecureRandom $secureRandom,
		private IURLGenerator $urlGenerator,
		private AccessGrantMapper $accessGrantMapper,
	) {
	}

	/**
	 * @param list<string> $payerIds
	 */
	public function create(array $payerIds, ?int $expiresAtMs = null): string {
		$payerIds = BuyerAccount::normalizeGrantHolderIds($payerIds);
		if ($payerIds === []) {
			return '';
		}

		$now = (int)(microtime(true) * 1000);
		$expiresAt = $expiresAtMs ?? ($now + self::DEFAULT_TTL_MS);
		if ($expiresAt <= $now) {
			return '';
		}

		$payload = json_encode([
			'v' => 1,
			'p' => $payerIds,
			'e' => $expiresAt,
		], JSON_UNESCAPED_UNICODE);
		if ($payload === false) {
			return '';
		}

		$encoded = $this->base64UrlEncode($payload);
		$sig = hash_hmac('sha256', $encoded, $this->getSecret(), true);

		return self::VERSION . '.' . $encoded . '.' . $this->base64UrlEncode($sig);
	}

	public function createForPayer(string $payerId, ?int $expiresAtMs = null): string {
		$payerId = BuyerAccount::normalizeGrantHolderId($payerId) ?? '';
		if ($payerId === '') {
			return '';
		}
		return $this->create([$payerId], $expiresAtMs);
	}

	/**
	 * @return array{payer_ids: list<string>, expires_at: int}|null
	 */
	public function validate(string $token): ?array {
		$token = trim($token);
		if ($token === '') {
			return null;
		}

		$parts = explode('.', $token, 3);
		if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
			return null;
		}

		$encoded = $parts[1];
		$sig = $this->base64UrlDecode($parts[2]);
		if ($sig === null) {
			return null;
		}

		$expected = hash_hmac('sha256', $encoded, $this->getSecret(), true);
		if (!hash_equals($expected, $sig)) {
			return null;
		}

		$json = $this->base64UrlDecode($encoded);
		if ($json === null) {
			return null;
		}

		/** @var array<string, mixed>|null $data */
		$data = json_decode($json, true);
		if (!is_array($data)) {
			return null;
		}

		$expiresAt = (int)($data['e'] ?? 0);
		if ($expiresAt <= (int)(microtime(true) * 1000)) {
			return null;
		}

		$rawPayers = $data['p'] ?? [];
		if (!is_array($rawPayers)) {
			return null;
		}
		$payerIds = BuyerAccount::normalizeGrantHolderIds($rawPayers);
		if ($payerIds === []) {
			return null;
		}

		return [
			'payer_ids' => $payerIds,
			'expires_at' => $expiresAt,
		];
	}

	public function mergePayer(string $token, string $payerIdRaw): ?string {
		$payload = $this->validate($token);
		$payerId = BuyerAccount::normalizePayerId($payerIdRaw);
		if ($payload === null || $payerId === null) {
			return null;
		}

		if (!$this->payerHasPurchases($payerId)) {
			return null;
		}

		try {
			$newIds = $this->accessGrantMapper->findActiveGrantHolderIdsForPayerInput($payerId);
		} catch (Exception) {
			return null;
		}
		if ($newIds === []) {
			return null;
		}

		$payerIds = $payload['payer_ids'];
		foreach ($newIds as $id) {
			if (!in_array($id, $payerIds, true)) {
				$payerIds[] = $id;
			}
		}

		return $this->create($payerIds, $payload['expires_at']);
	}

	public function buildPurchasesPageUrl(string $token): string {
		$base = $this->urlGenerator->linkToRouteAbsolute('sharegate.buyer.index');
		return $base . (str_contains($base, '?') ? '&' : '?') . 'purchases_token=' . rawurlencode($token);
	}

	private function payerHasPurchases(string $payerId): bool {
		$payerId = BuyerAccount::normalizeGrantHolderId($payerId) ?? '';
		if ($payerId === '') {
			return false;
		}
		try {
			return $this->accessGrantMapper->countActiveForPayer($payerId) > 0;
		} catch (DoesNotExistException|Exception) {
			return false;
		}
	}

	private function getSecret(): string {
		$secret = $this->config->getAppValue(Application::APP_ID, self::CONFIG_SECRET, '');
		if ($secret !== '') {
			return $secret;
		}
		$secret = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->config->setAppValue(Application::APP_ID, self::CONFIG_SECRET, $secret);
		return $secret;
	}

	private function base64UrlEncode(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	private function base64UrlDecode(string $encoded): ?string {
		$pad = strlen($encoded) % 4;
		if ($pad > 0) {
			$encoded .= str_repeat('=', 4 - $pad);
		}
		$raw = base64_decode(strtr($encoded, '-_', '+/'), true);
		return $raw === false ? null : $raw;
	}
}
