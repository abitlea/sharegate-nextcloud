<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/Entity.php';

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
	require_once $autoload;
} else {
	spl_autoload_register(static function (string $class): void {
		$prefix = 'OCA\\ShareGate\\';
		if (!str_starts_with($class, $prefix)) {
			return;
		}
		$relative = substr($class, strlen($prefix));
		$path = dirname(__DIR__) . '/lib/' . str_replace('\\', '/', $relative) . '.php';
		if (is_file($path)) {
			require_once $path;
		}
	});
}
