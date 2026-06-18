<?php
/** @var array $_ */
$form = [
	'payment_mode' => $_['payment_mode'],
	'effective_provider' => $_['effective_provider'],
	'alipay' => $_['alipay'],
	'save_url' => $_['save_url'],
	'mock_selectable' => $_['mock_selectable'] ?? false,
	'mock_production_warning' => $_['mock_production_warning'] ?? '',
];
?>
<div id="sharegate-admin-settings" class="section">
	<h2><?php p($l->t('Paid sharing payment settings')); ?></h2>

	<p class="settings-hint">
		<?php p($l->t('Configure Stripe, PayPal, or Alipay Face-to-Face. Use sandbox mode while testing Alipay or PayPal.')); ?>
	</p>

	<?php include __DIR__ . '/admin-form.php'; ?>
</div>

<script nonce="<?php p($_['csp_nonce'] ?? ''); ?>">
window.__SHAREGATE_ADMIN_CONFIG = {
  saveUrl: <?php print_unescaped(json_encode($_['save_url'])); ?>
};
</script>
