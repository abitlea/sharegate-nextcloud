<?php

declare(strict_types=1);

namespace OCA\ShareGate\Payment;

/**
 * Known payment providers and their translatable metadata keys.
 */
class PaymentProviderCatalog {
	/**
	 * @return list<array{id: string, label: string, description: string, region: string}>
	 */
	public static function all(): array {
		return [
			[
				'id' => MockPaymentProvider::NAME,
				'label' => 'Mock (development)',
				'description' => 'Mock payment for development only — no real charge',
				'region' => 'global',
			],
			[
				'id' => StripeProvider::NAME,
				'label' => 'Stripe',
				'description' => 'Card and wallet payments via Stripe Checkout (international)',
				'region' => 'international',
			],
			[
				'id' => PayPalProvider::NAME,
				'label' => 'PayPal',
				'description' => 'PayPal Checkout for international buyers',
				'region' => 'international',
			],
			[
				'id' => AlipayF2fProvider::NAME,
				'label' => 'Alipay Face-to-Face',
				'description' => 'Alipay QR code payment for buyers in China',
				'region' => 'china',
			],
		];
	}

	public static function isKnown(string $providerId): bool {
		foreach (self::all() as $provider) {
			if ($provider['id'] === $providerId) {
				return true;
			}
		}
		return false;
	}
}
