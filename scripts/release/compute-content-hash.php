<?php
/**
 * Recompute composer.lock content-hash from composer.json (Composer 2.x).
 * Run: php scripts/release/compute-content-hash.php
 */
$root = dirname(__DIR__, 2);
$composer = json_decode(file_get_contents($root . '/composer.json'), true);
$relevant = [
	'require' => $composer['require'] ?? [],
	'require-dev' => $composer['require-dev'] ?? [],
];
ksort($relevant['require']);
if (isset($relevant['require-dev'])) {
	ksort($relevant['require-dev']);
}
echo md5(json_encode($relevant)) . PHP_EOL;
