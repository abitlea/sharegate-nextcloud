<?php

declare(strict_types=1);

namespace OCA\ShareGate\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;

class SvgImageResponse extends Response {
	public function __construct(private string $svg) {
		parent::__construct(Http::STATUS_OK, [
			'Content-Type' => 'image/svg+xml; charset=utf-8',
			'Cache-Control' => 'private, max-age=300',
		]);
	}

	public function render(): string {
		return $this->svg;
	}
}
