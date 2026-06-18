<?php

declare(strict_types=1);

namespace OCA\ShareGate\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'sharegate';

	public function __construct() {
		parent::__construct(self::APP_ID);
		$autoload = __DIR__ . '/../../vendor/autoload.php';
		if (is_file($autoload)) {
			require_once $autoload;
		}
	}

	public function register(IRegistrationContext $context): void {
		// App menu: appinfo/info.xml only. In-app nav is Vue NcAppNavigation.
	}

	public function boot(IBootContext $context): void {
		// 不在 Files 注册右键：卖家在管理台「对外开放链接」行内「付费分享」维护即可
	}
}
