<?php

declare(strict_types=1);

namespace OCA\ShareGate\Listener;

use OCA\ShareGate\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;

/** @implements IEventListener<Event> */
class RegisterNavigationListener implements IEventListener {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
		private INavigationManager $navigationManager,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalEntriesEvent) {
			return;
		}

		$l10n = $this->l10nFactory->get(Application::APP_ID);
		$baseHref = $this->urlGenerator->linkToRoute('sharegate.dashboard.index');
		$icon = $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');

		foreach ($this->entries($l10n, $baseHref, $icon) as $entry) {
			$this->navigationManager->add($entry);
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function entries(\OCP\IL10N $l10n, string $baseHref, string $icon): array {
		return [
			[
				'id' => Application::APP_ID . '-public',
				'order' => 10,
				'href' => $baseHref . '#public',
				'icon' => $icon,
				'name' => $l10n->t('Public links'),
			],
			[
				'id' => Application::APP_ID . '-paid',
				'order' => 20,
				'href' => $baseHref . '#paid',
				'icon' => $icon,
				'name' => $l10n->t('Paid shares'),
			],
			[
				'id' => Application::APP_ID . '-account',
				'order' => 30,
				'href' => $baseHref . '#account',
				'icon' => $icon,
				'name' => $l10n->t('Account binding'),
			],
			[
				'id' => Application::APP_ID . '-stats',
				'order' => 40,
				'href' => $baseHref . '#stats',
				'icon' => $icon,
				'name' => $l10n->t('Statistics'),
			],
		];
	}
}
