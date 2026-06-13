<?php
/** @var array $_ */
?>
<div class="mock-pay-card">
	<div class="mock-pay-icon">💰</div>
	<h1><?php p($l->t('Mock payment')); ?></h1>
	<div class="mock-pay-info">
		<div><?php p($l->t('Order ID')); ?> <span><?php p($_['order_id']); ?></span></div>
		<div><?php p($l->t('Buyer ID')); ?> <span><?php p($_['provider_user_id']); ?></span></div>
	</div>
	<div class="mock-pay-notice">
		⚠️ <?php p($l->t('Mock payment for development only — no real charge')); ?>
	</div>
	<button class="mock-pay-btn" id="pay-btn" onclick="confirmMockPay()">✅ <?php p($l->t('Confirm payment')); ?></button>
	<div id="status-box" class="mock-pay-status" style="display:none;"></div>
</div>

<script nonce="<?php p($_['csp_nonce']); ?>">
window.__SHAREGATE_MOCK_PAY = {
  orderId: <?php print_unescaped(json_encode($_['order_id'])); ?>,
  providerUserId: <?php print_unescaped(json_encode($_['provider_user_id'])); ?>,
  webhookUrl: <?php print_unescaped(json_encode($_['webhook_url'])); ?>
};
async function confirmMockPay() {
  var btn = document.getElementById('pay-btn');
  var statusBox = document.getElementById('status-box');
  var cfg = window.__SHAREGATE_MOCK_PAY;
  btn.disabled = true;
  statusBox.style.display = 'none';
  try {
    var res = await fetch(cfg.webhookUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: cfg.orderId, provider_user_id: cfg.providerUserId })
    });
    var data = await res.json();
    if (data.success) {
      statusBox.className = 'mock-pay-status success';
      statusBox.textContent = '✅ <?php p($l->t('Payment successful! Redirecting...')); ?>';
      statusBox.style.display = 'block';
      setTimeout(function() { history.back(); }, 1500);
    } else {
      statusBox.className = 'mock-pay-status error';
      statusBox.textContent = '❌ ' + (data.error || '<?php p($l->t('Payment failed')); ?>');
      statusBox.style.display = 'block';
      btn.disabled = false;
    }
  } catch (err) {
    statusBox.className = 'mock-pay-status error';
    statusBox.textContent = '❌ ' + err.message;
    statusBox.style.display = 'block';
    btn.disabled = false;
  }
}
</script>
