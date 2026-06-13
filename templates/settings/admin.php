<?php
/** @var array $_ */
$form = [
	'payment_mode' => $_['payment_mode'],
	'effective_provider' => $_['effective_provider'],
	'alipay' => $_['alipay'],
	'save_url' => $_['save_url'],
];
?>
<div id="sharegate-admin-settings" class="section">
	<h2><?php p($l->t('Paid sharing payment settings')); ?></h2>

	<p class="settings-hint">
		<?php p($l->t('Configure payment provider. Mock mode is for development; Alipay Face-to-Face uses sandbox or production.')); ?>
	</p>

	<?php include __DIR__ . '/admin-form.php'; ?>
</div>

<script nonce="<?php p($_['csp_nonce'] ?? ''); ?>">
window.__SHAREGATE_ADMIN_CONFIG = {
  saveUrl: <?php print_unescaped(json_encode($_['save_url'])); ?>
};
</script>
