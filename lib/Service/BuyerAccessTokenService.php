<?php

declare(strict_types=1);

namespace OCA\ShareGate\Service;

use OCA\ShareGate\AppInfo\Application;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * Signed cross-device download token (share + payer + expiry).
 */
class BuyerAccessTokenService {
	private const CONFIG_SECRET = 'access_token_secret';
	private const VERSION = 'v1';

	public function __construct(
		private IConfig $config,
		private ISecureRandom $secureRandom,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function create(string $shareId, string $payerId, int $expiresAtMs): string {
		$shareId = trim($shareId);
		$payerId = trim($payerId);
		if ($shareId === '' || $payerId === '' || $expiresAtMs <= 0) {
			return '';
		}

		$payload = json_encode([
			'v' => 1,
			's' => $shareId,
			'p' => $payerId,
			'e' => $expiresAtMs,
		], JSON_UNESCAPED_UNICODE);
		if ($payload === false) {
			return '';
		}

		$encoded = $this->base64UrlEncode($payload);
		$sig = hash_hmac('sha256', $encoded, $this->getSecret(), true);

		return self::VERSION . '.' . $encoded . '.' . $this->base64UrlEncode($sig);
	}

	/**
	 * @return array{share_id: string, payer_id: string, expires_at: int}|null
	 */
	public function validate(string $token, ?string $expectedShareId = null): ?array {
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

		$shareId = trim((string)($data['s'] ?? ''));
		$payerId = trim((string)($data['p'] ?? ''));
		$expiresAt = (int)($data['e'] ?? 0);
		if ($shareId === '' || $payerId === '' || $expiresAt <= 0) {
			return null;
		}
		if ($expectedShareId !== null && $expectedShareId !== '' && $shareId !== $expectedShareId) {
			return null;
		}
		if ($expiresAt <= (int)(microtime(true) * 1000)) {
			return null;
		}

		return [
			'share_id' => $shareId,
			'payer_id' => $payerId,
			'expires_at' => $expiresAt,
		];
	}

	public function buildCrossDeviceViewUrl(string $shareId, string $token): string {
		$base = $this->urlGenerator->linkToRouteAbsolute('sharegate.share.view', ['shareId' => $shareId]);
		return $base . (str_contains($base, '?') ? '&' : '?') . 'access_token=' . rawurlencode($token);
	}

	public function appendTokenToDownloadUrl(string $downloadRouteUrl, string $token): string {
		return $downloadRouteUrl . (str_contains($downloadRouteUrl, '?') ? '&' : '?')
			. 'access_token=' . rawurlencode($token);
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
