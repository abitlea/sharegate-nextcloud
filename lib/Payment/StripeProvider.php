<?php

declare(strict_types=1);

namespace OCA\ShareGate\Payment;

use OCA\ShareGate\Service\PaymentConfigService;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Stripe Checkout — card/wallet payments for international buyers (REST API, no SDK).
 */
class StripeProvider {
	public const NAME = 'stripe';

	public function __construct(
		private PaymentConfigService $configService,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	public function isAvailable(): bool {
		return $this->configService->isStripeConfigured();
	}

	/**
	 * @return array{success: true, payment_url: string, session_id: string}|array{success: false, error: string}
	 */
	public function createCheckoutSession(
		string $orderId,
		string $shareId,
		string $title,
		int $amountCents,
	): array {
		if (!$this->isAvailable()) {
			return [
				'success' => false,
				'error' => $this->l->t('Stripe is not configured. Set the secret key and webhook secret, then select Stripe.'),
			];
		}

		$cfg = $this->configService->getStripeConfig();
		$currency = strtolower($cfg['currency']);
		$viewUrl = $this->urlGenerator->linkToRouteAbsolute(
			'sharegate.share.view',
			['shareId' => $shareId],
		);
		$successUrl = $viewUrl . (str_contains($viewUrl, '?') ? '&' : '?')
			. 'order_id=' . rawurlencode($orderId)
			. '&session_id={CHECKOUT_SESSION_ID}';
		$cancelUrl = $viewUrl . (str_contains($viewUrl, '?') ? '&' : '?')
			. 'order_id=' . rawurlencode($orderId)
			. '&cancelled=1';

		$productName = $title !== '' ? $title : $this->l->t('ShareGate file download');
		$params = [
			'mode' => 'payment',
			'success_url' => $successUrl,
			'cancel_url' => $cancelUrl,
			'client_reference_id' => $orderId,
			'metadata[order_id]' => $orderId,
			'metadata[share_id]' => $shareId,
			'line_items[0][quantity]' => '1',
			'line_items[0][price_data][currency]' => $currency,
			'line_items[0][price_data][unit_amount]' => (string)$amountCents,
			'line_items[0][price_data][product_data][name]' => $productName,
		];

		$response = $this->apiRequest('POST', '/v1/checkout/sessions', $params, $cfg['secret_key']);
		if (!($response['success'] ?? false)) {
			return $response;
		}

		$body = $response['data'];
		$url = (string)($body['url'] ?? '');
		$sessionId = (string)($body['id'] ?? '');
		if ($url === '' || $sessionId === '') {
			return ['success' => false, 'error' => $this->l->t('Stripe checkout session creation failed')];
		}

		return [
			'success' => true,
			'payment_url' => $url,
			'session_id' => $sessionId,
		];
	}

	/**
	 * @return array{success: true, status: string, order_id?: string, provider_order_id?: string}|array{success: false, error: string}
	 */
	public function querySession(string $sessionId): array {
		if (!$this->isAvailable()) {
			return ['success' => false, 'error' => $this->l->t('Stripe not configured')];
		}

		$cfg = $this->configService->getStripeConfig();
		$response = $this->apiRequest(
			'GET',
			'/v1/checkout/sessions/' . rawurlencode($sessionId),
			[],
			$cfg['secret_key'],
		);
		if (!($response['success'] ?? false)) {
			return $response;
		}

		$body = $response['data'];
		$sessionStatus = (string)($body['status'] ?? '');
		$paymentStatus = (string)($body['payment_status'] ?? '');
		if ($sessionStatus === 'expired') {
			return [
				'success' => true,
				'status' => 'expired',
				'status_message' => $this->l->t('Checkout session expired'),
				'order_id' => (string)($body['client_reference_id'] ?? $body['metadata']['order_id'] ?? ''),
				'provider_order_id' => $sessionId,
			];
		}
		$status = $paymentStatus === 'paid' ? 'paid' : 'pending';
		$orderId = (string)($body['client_reference_id'] ?? $body['metadata']['order_id'] ?? '');

		return [
			'success' => true,
			'status' => $status,
			'order_id' => $orderId,
			'provider_order_id' => $sessionId,
			'payer_user_id' => (string)($body['customer_details']['email'] ?? 'stripe_customer'),
		];
	}

	/**
	 * @return array{success: true, order_id: string, provider_order_id: string, payer_user_id: string}|array{success: false, error: string}
	 */
	public function verifyWebhook(string $payload, string $signatureHeader): array {
		if (!$this->isAvailable()) {
			return ['success' => false, 'error' => $this->l->t('Stripe not configured')];
		}

		$cfg = $this->configService->getStripeConfig();
		if (!$this->verifySignature($payload, $signatureHeader, $cfg['webhook_secret'])) {
			return ['success' => false, 'error' => $this->l->t('Stripe webhook signature verification failed')];
		}

		/** @var array<string, mixed>|null $event */
		$event = json_decode($payload, true);
		if (!is_array($event)) {
			return ['success' => false, 'error' => $this->l->t('Invalid Stripe webhook payload')];
		}

		$type = (string)($event['type'] ?? '');

		if ($type === 'charge.refunded') {
			/** @var array<string, mixed> $charge */
			$charge = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
			/** @var array<string, mixed> $metadata */
			$metadata = is_array($charge['metadata'] ?? null) ? $charge['metadata'] : [];
			$orderId = (string)($metadata['order_id'] ?? '');
			if ($orderId === '') {
				return ['success' => false, 'error' => 'ignored'];
			}
			return [
				'success' => true,
				'event_type' => 'charge.refunded',
				'order_id' => $orderId,
				'status_message' => $this->l->t('Payment refunded'),
			];
		}

		if ($type !== 'checkout.session.completed') {
			return ['success' => false, 'error' => 'ignored'];
		}

		/** @var array<string, mixed> $session */
		$session = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
		if (($session['payment_status'] ?? '') !== 'paid') {
			return ['success' => false, 'error' => $this->l->t('Stripe payment not completed')];
		}

		$orderId = (string)($session['client_reference_id'] ?? $session['metadata']['order_id'] ?? '');
		if ($orderId === '') {
			return ['success' => false, 'error' => $this->l->t('Missing order_id in Stripe session')];
		}

		return [
			'success' => true,
			'order_id' => $orderId,
			'provider_order_id' => (string)($session['id'] ?? ''),
			'payer_user_id' => (string)($session['customer_details']['email'] ?? 'stripe_customer'),
		];
	}

	private function verifySignature(string $payload, string $signatureHeader, string $secret): bool {
		if ($secret === '' || $signatureHeader === '') {
			return false;
		}

		$timestamp = null;
		$signatures = [];
		foreach (explode(',', $signatureHeader) as $part) {
			[$key, $value] = array_map('trim', explode('=', $part, 2) + ['', '']);
			if ($key === 't') {
				$timestamp = $value;
			} elseif ($key === 'v1' && $value !== '') {
				$signatures[] = $value;
			}
		}

		if ($timestamp === null || $signatures === []) {
			return false;
		}

		if (abs(time() - (int)$timestamp) > 300) {
			return false;
		}

		$signedPayload = $timestamp . '.' . $payload;
		$expected = hash_hmac('sha256', $signedPayload, $secret);
		foreach ($signatures as $signature) {
			if (hash_equals($expected, $signature)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, string> $params
	 * @return array{success: true, data: array<string, mixed>}|array{success: false, error: string}
	 */
	private function apiRequest(string $method, string $path, array $params, string $secretKey): array {
		if ($secretKey === '') {
			return ['success' => false, 'error' => $this->l->t('Stripe secret key missing')];
		}

		$url = 'https://api.stripe.com' . $path;
		$ch = curl_init($url);
		if ($ch === false) {
			return ['success' => false, 'error' => $this->l->t('Stripe request failed')];
		}

		$headers = ['Content-Type: application/x-www-form-urlencoded'];
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERPWD => $secretKey . ':',
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 30,
		]);

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
		}

		$raw = curl_exec($ch);
		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($raw === false) {
			return ['success' => false, 'error' => $this->l->t('Stripe request error: %s', [$curlError])];
		}

		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode($raw, true);
		if ($httpCode >= 400 || !is_array($decoded)) {
			$message = is_array($decoded)
				? (string)($decoded['error']['message'] ?? $raw)
				: $raw;
			return ['success' => false, 'error' => $this->l->t('Stripe request error: %s', [$message])];
		}

		return ['success' => true, 'data' => $decoded];
	}
}
