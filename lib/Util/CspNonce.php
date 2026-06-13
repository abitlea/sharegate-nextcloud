<?php

declare(strict_types=1);

namespace OCA\ShareGate\Util;

use OCP\IServerContainer;
use OCP\Server;

/**
 * NC 33 removed OC\Server::getContentSecurityPolicyNonce(); use the nonce manager service.
 */
final class CspNonce {
	public static function get(): string {
		$server = Server::get(IServerContainer::class);
		foreach (['cspNonceManager', 'ContentSecurityPolicyNonceManager'] as $serviceId) {
			try {
				if (!$server->has($serviceId)) {
					continue;
				}
				$manager = $server->get($serviceId);
				if (is_object($manager) && method_exists($manager, 'getNonce')) {
					return (string)$manager->getNonce();
				}
			} catch (\Throwable) {
				continue;
			}
		}
		return '';
	}
}
