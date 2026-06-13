<?php
/** @var array $_ */
$base = rtrim((string)($_['nav_base'] ?? ''), '/');
$entries = [
	[
		'id' => 'sg-nav-public',
		'hash' => 'public',
		'count' => 'all',
		'label' => 'Public links',
		'svg' => 'M3.9,12C3.9,10.29 5.29,8.9 7,8.9H11V7H7A5,5 0 0,0 2,12A5,5 0 0,0 7,17H11V15.1H7C5.29,15.1 3.9,13.71 3.9,12M8,13H16V11H8V13M17,7H13V8.9H17C18.71,8.9 20.1,10.29 20.1,12C20.1,13.71 18.71,15.1 17,15.1H13V17H17A5,5 0 0,0 22,12A5,5 0 0,0 17,7Z',
	],
	[
		'id' => 'sg-nav-paid',
		'hash' => 'paid',
		'count' => 'active',
		'label' => 'Paid shares',
		'svg' => 'M12,5A3.5,3.5 0 0,0 8.5,8.5A3.5,3.5 0 0,0 12,12A3.5,3.5 0 0,0 15.5,8.5A3.5,3.5 0 0,0 12,5M12,7A1.5,1.5 0 0,1 13.5,8.5A1.5,1.5 0 0,1 12,10A1.5,1.5 0 0,1 10.5,8.5A1.5,1.5 0 0,1 12,7M5.5,8A2.5,2.5 0 0,0 3,10.5C3,11.44 3.53,12.25 4.29,12.68C4.65,12.88 5.06,13 5.5,13C5.94,13 6.35,12.88 6.71,12.68C7.08,12.47 7.39,12.17 7.62,11.81C6.89,10.86 6.5,9.7 6.5,8.5C6.5,8.41 6.5,8.31 6.5,8.22C6.2,8.08 5.86,8 5.5,8M18.5,8C18.14,8 17.8,8.08 17.5,8.22C17.5,8.31 17.5,8.41 17.5,8.5C17.5,9.7 17.11,10.86 16.38,11.81C16.5,12 16.63,12.15 16.78,12.3C16.94,12.45 17.1,12.58 17.29,12.68C17.65,12.88 18.06,13 18.5,13C18.94,13 19.35,12.88 19.71,12.68C20.47,12.25 21,11.44 21,10.5A2.5,2.5 0 0,0 18.5,8M12,14C9.66,14 5,15.17 5,17.5V19H19V17.5C19,15.17 14.34,14 12,14M4.71,14.55C2.78,14.78 0,15.76 0,17.5V19H3V17.07C3,16.06 3.69,15.22 4.71,14.55M19.29,14.55C20.31,15.22 21,16.06 21,17.07V19H24V17.5C24,15.76 21.22,14.78 19.29,14.55M12,16C13.53,16 15.24,16.5 16.23,17H7.77C8.76,16.5 10.47,16 12,16Z',
	],
	[
		'id' => 'sg-nav-account',
		'hash' => 'account',
		'count' => '',
		'label' => 'Account binding',
		'svg' => 'M12,8A4,4 0 0,1 16,12A4,4 0 0,1 12,16A4,4 0 0,1 8,12A4,4 0 0,1 12,8M12,10A2,2 0 0,0 10,12A2,2 0 0,0 12,14A2,2 0 0,0 14,12A2,2 0 0,0 12,10M10,22C9.75,22 9.54,21.82 9.5,21.58L9.13,18.93C8.5,18.68 7.96,18.34 7.44,17.94L4.95,18.95C4.73,19.03 4.46,18.95 4.34,18.73L2.34,15.27C2.21,15.05 2.27,14.78 2.46,14.63L4.57,12.97L4.5,12L4.57,11L2.46,9.37C2.27,9.22 2.21,8.95 2.34,8.73L4.34,5.27C4.46,5.05 4.73,4.96 4.95,5.05L7.44,6.05C7.96,5.66 8.5,5.32 9.13,5.07L9.5,2.42C9.54,2.18 9.75,2 10,2H14C14.25,2 14.46,2.18 14.5,2.42L14.87,5.07C15.5,5.32 16.04,5.66 16.56,6.05L19.05,5.05C19.27,4.96 19.54,5.05 19.66,5.27L21.66,8.73C21.79,8.95 21.73,9.22 21.54,9.37L19.43,11L19.5,12L19.43,13L21.54,14.63C21.73,14.78 21.79,15.05 21.66,15.27L19.66,18.73C19.54,18.95 19.27,19.04 19.05,18.95L16.56,17.95C16.04,18.34 15.5,18.68 14.87,18.93L14.5,21.58C14.46,21.82 14.25,22 14,22H10M11.25,4L10.88,6.61C9.68,6.86 8.62,7.5 7.85,8.39L5.44,7.35L4.69,8.65L6.8,10.2C6.4,11.37 6.4,12.64 6.8,13.8L4.68,15.36L5.43,16.66L7.86,15.62C8.63,16.5 9.68,17.14 10.87,17.38L11.24,20H12.76L13.13,17.39C14.32,17.14 15.37,16.5 16.14,15.62L18.57,16.66L19.32,15.36L17.2,13.81C17.6,12.64 17.6,11.37 17.2,10.2L19.31,8.65L18.56,7.35L16.15,8.39C15.38,7.5 14.32,6.86 13.12,6.62L12.75,4H11.25Z',
	],
	[
		'id' => 'sg-nav-stats',
		'hash' => 'stats',
		'count' => 'stats',
		'label' => 'Statistics',
		'svg' => 'M13 11H19.95Q19.58 8.25 17.66 6.34 15.75 4.43 13 4.05M11 19.95V4.05Q8 4.43 6 6.69 4 8.95 4 12T6 17.31Q8 19.58 11 19.95M13 19.95Q15.75 19.6 17.68 17.68 19.6 15.75 19.95 13H13M12 12M12 22Q9.93 22 8.1 21.21 6.28 20.43 4.93 19.08 3.58 17.73 2.79 15.9 2 14.08 2 12T2.79 8.1Q3.58 6.28 4.93 4.93 6.28 3.58 8.1 2.79 9.93 2 12 2T15.89 2.79Q17.7 3.58 19.06 4.94 20.43 6.3 21.21 8.11 22 9.93 22 12 22 14.05 21.21 15.88 20.43 17.7 19.08 19.06 17.73 20.43 15.9 21.21 14.08 22 12 22Z',
	],
];
$menuOpenPath = 'M21,15.61L19.59,17L14.58,12L19.59,7L21,8.39L17.44,12L21,15.61M3,6H16V8H3V6M3,13V11H13V13H3M3,18V16H16V18H3Z';
$menuClosedPath = 'M3,6H21V8H3V6M3,11H21V13H3V11M3,16H21V18H3V16Z';
?>
<div id="app-navigation" class="app-navigation sharegate-navigation" role="navigation">
	<nav id="app-navigation-vue" class="app-navigation__content" aria-label="<?php p($l->t('Paid sharing')); ?>">
		<div class="app-navigation__search" id="sg-nav-search">
			<div class="app-navigation-search">
				<div class="input-field app-navigation-search__input input-field--label-outside input-field--trailing-icon">
					<div class="input-field__main-wrapper">
						<input type="search" id="sg-search" class="input-field__input"
							   placeholder="<?php p($l->t('Search shares')); ?>"
							   aria-label="<?php p($l->t('Search shares')); ?>"
							   autocomplete="off">
						<button type="button" id="sg-search-clear"
								class="input-field__trailing-button button-vue button-vue--size-normal button-vue--icon-only button-vue--vue-tertiary-no-background button-vue--tertiary"
								aria-label="<?php p($l->t('Clear search')); ?>" hidden>
							<span class="button-vue__wrapper">
								<span class="button-vue__icon">
									<span class="material-design-icon close-icon" aria-hidden="true" role="img">
										<svg fill="currentColor" width="20" height="20" viewBox="0 0 24 24">
											<path d="M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z"/>
										</svg>
									</span>
								</span>
							</span>
						</button>
					</div>
				</div>
			</div>
		</div>
		<div class="app-navigation__body app-navigation__body--no-list">
			<ul class="app-navigation-list sharegate-navigation-list" aria-label="<?php p($l->t('Paid sharing')); ?>">
				<?php foreach ($entries as $entry) : ?>
					<?php $label = $l->t($entry['label']); ?>
					<li class="app-navigation-entry-wrapper sharegate-navigation__item"
						data-id="<?php p($entry['id']); ?>"
						data-nav="<?php p($entry['hash']); ?>">
						<div class="app-navigation-entry">
							<a href="<?php p($base . '#' . $entry['hash']); ?>"
							   class="app-navigation-entry-link"
							   title="<?php p($label); ?>">
								<div class="app-navigation-entry-icon">
									<span class="icon-vue" aria-hidden="true" role="img" style="--dad67fa8: 20px;">
										<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
											<path d="<?php p($entry['svg']); ?>"/>
										</svg>
									</span>
								</div>
								<span class="app-navigation-entry__name"><?php p($label); ?></span>
								<?php if ($entry['count'] !== '') : ?>
									<span class="app-navigation-entry-utils-counter" data-count="<?php p($entry['count']); ?>"></span>
								<?php endif; ?>
							</a>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</nav>
	<div class="app-navigation-toggle-wrapper">
		<button type="button" id="sg-nav-toggle"
				class="app-navigation-toggle button-vue button-vue--size-normal button-vue--icon-only button-vue--vue-tertiary button-vue--tertiary"
				aria-controls="app-navigation-vue"
				aria-expanded="true"
				title="<?php p($l->t('Close navigation')); ?>"
				aria-label="<?php p($l->t('Close navigation')); ?>">
			<span class="button-vue__wrapper">
				<span class="button-vue__icon">
					<span class="material-design-icon menu-open-icon" aria-hidden="true" role="img" id="sg-nav-toggle-icon">
						<svg fill="currentColor" width="20" height="20" viewBox="0 0 24 24">
							<path d="<?php p($menuOpenPath); ?>"/>
						</svg>
					</span>
				</span>
			</span>
		</button>
	</div>
</div>
