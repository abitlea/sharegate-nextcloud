<?php

declare(strict_types=1);

namespace OCA\ShareGate\Util;

final class QrCodeRenderer {
	public function toDataUri(string $payload): ?string {
		if ($payload === '' || !class_exists(\chillerlan\QRCode\QRCode::class)) {
			return null;
		}

		try {
			$options = new \chillerlan\QRCode\QROptions([
				'scale' => 8,
				'outputBase64' => true,
			]);
			$dataUri = (new \chillerlan\QRCode\QRCode($options))->render($payload);
			return is_string($dataUri) && $dataUri !== '' ? $dataUri : null;
		} catch (\Throwable) {
			return null;
		}
	}

	public function toRawSvg(string $payload): ?string {
		if ($payload === '' || !class_exists(\chillerlan\QRCode\QRCode::class)) {
			return null;
		}

		try {
			$options = new \chillerlan\QRCode\QROptions([
				'scale' => 8,
				'outputBase64' => false,
			]);
			$svg = (new \chillerlan\QRCode\QRCode($options))->render($payload);
			return is_string($svg) && $svg !== '' ? $svg : null;
		} catch (\Throwable) {
			return null;
		}
	}
}
