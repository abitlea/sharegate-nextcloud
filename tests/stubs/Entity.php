<?php

declare(strict_types=1);

namespace OCP\AppFramework\Db;

/**
 * Minimal Entity stub for unit tests without a full Nextcloud checkout.
 */
class Entity {
	protected function addType(string $name, string $type): void {
		// no-op
	}

	public function __call(string $name, array $args): mixed {
		if (str_starts_with($name, 'get') && count($args) === 0) {
			$prop = lcfirst(substr($name, 3));
			if (property_exists($this, $prop)) {
				return $this->$prop;
			}
		}
		if (str_starts_with($name, 'set') && count($args) === 1) {
			$prop = lcfirst(substr($name, 3));
			if (property_exists($this, $prop)) {
				$this->$prop = $args[0];
				return $this;
			}
		}
		throw new \BadMethodCallException($name);
	}
}
