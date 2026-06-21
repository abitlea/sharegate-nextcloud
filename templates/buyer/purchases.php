<?php

/** @var array $_ */

?>

<div class="container sg-buyer-purchases-layout">
	<div id="sharegate-buyer-purchases" class="sg-buyer-purchases-root"></div>
	<div class="footer"><?php p($l->t('Powered by ShareGate')); ?></div>
</div>

<script nonce="<?php p($_['csp_nonce'] ?? ''); ?>">
	window.__sharegateBuyerPurchases = <?php print_unescaped($_['buyer_purchases_config'] ?? '{}'); ?>;
</script>
