<?php

/** @var array $_ */

?>

<div id="sharegate-dashboard" class="app sg-app"></div>

<script nonce="<?php p($_['csp_nonce'] ?? ''); ?>">

	window.__sharegateDashboard = <?php print_unescaped($_['dashboard_config'] ?? '{}'); ?>;

</script>
