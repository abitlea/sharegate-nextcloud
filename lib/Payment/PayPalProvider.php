<?php

declare(strict_types=1);

namespace OCA\ShareGate\Payment;

use OCA\ShareGate\Service\PaymentConfigService;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * PayPal Checkout (Orders v2) — redirect flow, REST API without SDK.
 */
class PayPalProvider {
	public const NAME = 'paypal';

	public function __construct(
		private PaymentConfigService $configService,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	public function isAvailable(): bool {
		return $this->configService->isPaypalConfigured();
	}

	/**
	 * @return array{success: true, payment_url: string, order_id: string}|array{success: false, error: string}
	 */
	public function createCheckoutOrder(
		string $orderId,
		string $shareId,
		string $title,
		int $amountCents,
	): array {
		if (!$this->isAvailable()) {
			return [
				'success' => false,
				'error' => $this->l->t('PayPal is not configured. Set Client ID and Client Secret, then select PayPal.'),
			];
		}

		$cfg = $this->configService->getPaypalConfig();
		$viewUrl = $this->urlGenerator->linkToRouteAbsolute(
			'sharegate.share.view',
			['shareId' => $shareId],
		);
		$returnUrl = $viewUrl . (str_contains($viewUrl, '?') ? '&' : '?')
			. 'order_id=' . rawurlencode($orderId);
		$cancelUrl = $viewUrl . (str_contains($viewUrl, '?') ? '&' : '?')
			. 'order_id=' . rawurlencode($orderId)
			. '&cancelled=1';

		$productName = $title !== '' ? $title : $this->l->t('ShareGate file download');
		$body = [
			'intent' => 'CAPTURE',
			'purchase_units' => [[
				'reference_id' => $orderId,
				'custom_id' => $orderId,
				'description' => $productName,
				'amount' => [
					'currency_code' => strtoupper($cfg['currency']),
					'value' => $this->formatAmountValue($amountCents, $cfg['currency']),
				],
			]],
			'application_context' => [
				'return_url' => $returnUrl,
				'cancel_url' => $cancelUrl,
				'brand_name' => 'ShareGate',
				'user_action' => 'PAY_NOW',
			],
		];

		$response = $this->apiRequest('POST', '/v2/checkout/orders', $body);
		if (!($response['success'] ?? false)) {
			return $response;
		}

		$data = $response['data'];
		$paypalOrderId = (string)($data['id'] ?? '');
		$approveUrl = $this->extractLink($data, 'approve');
		if ($paypalOrderId === '' || $approveUrl === '') {
			return ['success' => false, 'error' => $this->l->t('PayPal checkout order creation failed')];
		}

		return [
			'success' => true,
			'payment_url' => $approveUrl,
			'order_id' => $paypalOrderId,
		];
	}

	/**
	 * @return array{success: true, status: string, order_id?: string, provider_order_id?: string, payer_user_id?: string}|array{success: false, error: string}
	 */
	public function queryAndCaptureOrder(string $paypalOrderId): array {
		if (!$this->isAvailable()) {
			return ['success' => false, 'error' => $this->l->t('PayPal not configured')];
		}

		$order = $this->apiRequest('GET', '/v2/checkout/orders/' . rawurlencode($paypalOrderId));
		if (!($order['success'] ?? false)) {
			return $order;
		}

		$data = $order['data'];
		$status = (string)($data['status'] ?? '');
		$sharegateOrderId = $this->extractSharegateOrderId($data);

		if ($status === 'APPROVED') {
			$capture = $this->apiRequest(
				'POST',
				'/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture',
			);
			if (!($capture['success'] ?? false)) {
				$refetch = $this->apiRequest('GET', '/v2/checkout/orders/' . rawurlencode($paypalOrderId));
				if (($refetch['success'] ?? false) && (string)($refetch['data']['status'] ?? '') === 'COMPLETED') {
					$data = $refetch['data'];
					$status = 'COMPLETED';
					$sharegateOrderId = $this->extractSharegateOrderId($data) ?: $sharegateOrderId;
				} else {
					return $capture;
				}
			} else {
				$data = $capture['data'];
				$status = (string)($data['status'] ?? '');
				$sharegateOrderId = $this->extractSharegateOrderId($data) ?: $sharegateOrderId;
			}
		}

		if ($status === 'COMPLETED') {
			return [
				'success' => true,
				'status' => 'paid',
				'order_id' => $sharegateOrderId,
				'provider_order_id' => $paypalOrderId,
				'payer_user_id' => $this->extractPayerEmail($data),
			];
		}

		return [
			'success' => true,
			'status' => 'pending',
			'order_id' => $sharegateOrderId,
			'provider_order_id' => $paypalOrderId,
		];
	}

	/**
	 * @return array{success: true, order_id: string, provider_order_id: string, payer_user_id: string}|array{success: false, error: string}
	 */
	public function handleWebhookEvent(array $event): array {
		$type = (string)($event['event_type'] ?? '');
		/** @var array<string, mixed> $resource */
		$resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];

		if ($type === 'CHECKOUT.ORDER.APPROVED') {
			$paypalOrderId = (string)($resource['id'] ?? '');
			if ($paypalOrderId === '') {
				return ['success' => false, 'error' => $this->l->t('Missing PayPal order id')];
			}
			$result = $this->queryAndCaptureOrder($paypalOrderId);
			if (!($result['success'] ?? false) || ($result['status'] ?? '') !== 'paid') {
				return ['success' => false, 'error' => $result['error'] ?? $this->l->t('PayPal capture failed')];
			}
			return [
				'success' => true,
				'order_id' => (string)($result['order_id'] ?? ''),
				'provider_order_id' => $paypalOrderId,
				'payer_user_id' => (string)($result['payer_user_id'] ?? 'paypal_user'),
			];
		}

		if ($type === 'PAYMENT.Capture.COMPLETED') {
			$sharegateOrderId = (string)($resource['custom_id'] ?? '');
			if ($sharegateOrderId === '') {
				/** @var array<string, mixed> $related */
				$related = is_array($resource['supplementary_data']['related_ids'] ?? null)
					? $resource['supplementary_data']['related_ids']
					: [];
				$paypalOrderId = (string)($related['order_id'] ?? '');
				if ($paypalOrderId !== '') {
					$query = $this->queryAndCaptureOrder($paypalOrderId);
					if (($query['success'] ?? false) && ($query['order_id'] ?? '') !== '') {
						$sharegateOrderId = (string)$query['order_id'];
					}
				}
			}
			if ($sharegateOrderId === '') {
				return ['success' => false, 'error' => $this->l->t('Missing order_id in PayPal capture')];
			}
			return [
				'success' => true,
				'order_id' => $sharegateOrderId,
				'provider_order_id' => (string)($resource['id'] ?? ''),
				'payer_user_id' => (string)($resource['payer']['email_address'] ?? 'paypal_user'),
			];
		}

		return ['success' => false, 'error' => 'ignored'];
	}

	/**
	 * @param array<string, mixed> $headers
	 */
	public function verifyWebhook(string $payload, array $headers): bool {
		$cfg = $this->configService->getPaypalConfig();
		if ($cfg['webhook_id'] === '') {
			return false;
		}

		$transmissionId = $this->headerValue($headers, 'paypal-transmission-id');
		$transmissionTime = $this->headerValue($headers, 'paypal-transmission-time');
		$certUrl = $this->headerValue($headers, 'paypal-cert-url');
		$authAlgo = $this->headerValue($headers, 'paypal-auth-algo');
		$transmissionSig = $this->headerValue($headers, 'paypal-transmission-sig');
		if ($transmissionId === '' || $transmissionSig === '') {
			return false;
		}

		$body = [
			'auth_algo' => $authAlgo,
			'cert_url' => $certUrl,
			'transmission_id' => $transmissionId,
			'transmission_sig' => $transmissionSig,
			'transmission_time' => $transmissionTime,
			'webhook_id' => $cfg['webhook_id'],
			'webhook_event' => json_decode($payload, true),
		];

		$response = $this->apiRequest('POST', '/v1/notifications/verify-webhook-signature', $body);
		if (!($response['success'] ?? false)) {
			return false;
		}

		return ($response['data']['verification_status'] ?? '') === 'SUCCESS';
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function extractLink(array $data, string $rel): string {
		$links = $data['links'] ?? [];
		if (!is_array($links)) {
			return '';
		}
		foreach ($links as $link) {
			if (!is_array($link)) {
				continue;
			}
			if (($link['rel'] ?? '') === $rel && !empty($link['href'])) {
				return (string)$link['href'];
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function extractSharegateOrderId(array $data): string {
		$units = $data['purchase_units'] ?? [];
		if (!is_array($units) || $units === []) {
			return '';
		}
		$unit = $units[0];
		if (!is_array($unit)) {
			return '';
		}
		return (string)($unit['custom_id'] ?? $unit['reference_id'] ?? '');
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function extractPayerEmail(array $data): string {
		$payer = $data['payer'] ?? [];
		if (is_array($payer) && !empty($payer['email_address'])) {
			return (string)$payer['email_address'];
		}
		$units = $data['purchase_units'] ?? [];
		if (is_array($units) && isset($units[0]['payments']['captures'][0]['id'])) {
			return 'paypal_user';
		}
		return 'paypal_user';
	}

	private function formatAmountValue(int $amountCents, string $currency): string {
		$currency = strtolower($currency);
		if ($currency === 'jpy') {
			return (string)$amountCents;
		}
		return number_format($amountCents / 100, 2, '.', '');
	}

	/**
	 * @param array<string, mixed> $headers
	 */
	private function headerValue(array $headers, string $name): string {
		$lower = strtolower($name);
		foreach ($headers as $key => $value) {
			if (strtolower((string)$key) !== $lower) {
				continue;
			}
			if (is_array($value)) {
				return (string)($value[0] ?? '');
			}
			return (string)$value;
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{success: true, data: array<string, mixed>}|array{success: false, error: string}
	 */
	private function apiRequest(string $method, string $path, array $body = []): array {
		$token = $this->getAccessToken();
		if ($token === '') {
			return ['success' => false, 'error' => $this->l->t('PayPal authentication failed')];
		}

		$cfg = $this->configService->getPaypalConfig();
		$base = $cfg['sandbox']
			? 'https://api-m.sandbox.paypal.com'
			: 'https://api-m.paypal.com';
		$url = $base . $path;

		$ch = curl_init($url);
		if ($ch === false) {
			return ['success' => false, 'error' => $this->l->t('PayPal request failed')];
		}

		$headers = [
			'Authorization: Bearer ' . $token,
			'Content-Type: application/json',
		];
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 30,
		]);

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			// PayPal rejects a JSON array body; empty POSTs must be "{}".
			$payload = $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_UNICODE);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		}

		$raw = curl_exec($ch);
		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($raw === false) {
			return ['success' => false, 'error' => $this->l->t('PayPal request error: %s', [$curlError])];
		}

		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode($raw, true);
		if ($httpCode >= 400 || !is_array($decoded)) {
			$message = is_array($decoded)
				? (string)($decoded['message'] ?? $decoded['name'] ?? $raw)
				: $raw;
			if (isset($decoded['details']) && is_array($decoded['details'])) {
				$detail = $decoded['details'][0] ?? [];
				if (is_array($detail) && !empty($detail['description'])) {
					$message = (string)$detail['description'];
				}
			}
			return ['success' => false, 'error' => $this->l->t('PayPal request error: %s', [$message])];
		}

		return ['success' => true, 'data' => $decoded];
	}

	private function getAccessToken(): string {
		$cfg = $this->configService->getPaypalConfig();
		if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
			return '';
		}

		$base = $cfg['sandbox']
			? 'https://api-m.sandbox.paypal.com'
			: 'https://api-m.paypal.com';
		$ch = curl_init($base . '/v1/oauth2/token');
		if ($ch === false) {
			return '';
		}

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_USERPWD => $cfg['client_id'] . ':' . $cfg['client_secret'],
			CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
			CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: en_US'],
			CURLOPT_TIMEOUT => 30,
		]);

		$raw = curl_exec($ch);
		curl_close($ch);
		if ($raw === false) {
			return '';
		}

		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? (string)($decoded['access_token'] ?? '') : '';
	}
}
