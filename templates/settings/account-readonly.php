<?php
/**
 * 非管理员只读账户绑定摘要
 *
 * @var array $account 来自 getAccountBindingSummary
 * @var \OCP\IL10N $l
 */
$mode = (string)($account['payment_mode'] ?? '');
$modeLabels = [
	'alipay_f2f' => $l->t('Alipay Face-to-Face'),
	'stripe' => $l->t('Stripe'),
	'paypal' => $l->t('PayPal'),
	'mock' => $l->t('Mock (development)'),
];
$modeLabel = $modeLabels[$mode] ?? $mode;
$bound = !empty($account['alipay_configured'])
	? $l->t('Bound')
	: $l->t('Not bound');
?>
<div id="sg-account-readonly" class="sg-account-readonly">
	<p><strong><?php p($l->t('Payment mode')); ?>:</strong> <?php p($modeLabel); ?></p>
	<p><strong><?php p($l->t('Alipay Face-to-Face')); ?>:</strong> <?php p($bound); ?></p>
	<?php if (!empty($account['alipay_sandbox'])) : ?>
		<p class="settings-hint"><?php p($l->t('Sandbox mode')); ?></p>
	<?php endif; ?>
	<?php if (!empty($account['notify_url'])) : ?>
		<p><strong><?php p($l->t('Async notify URL (register in Alipay console)')); ?>:</strong><br>
			<code class="sg-code"><?php p($account['notify_url']); ?></code></p>
	<?php endif; ?>
	<p class="settings-hint"><?php p($l->t('Contact your Nextcloud admin to configure payment.')); ?></p>
</div>
