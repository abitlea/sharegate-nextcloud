<?php
/** @var array $_ */
?>
<div class="card" id="app">
	<h1>💰 <?php p($l->t('Create paid share')); ?></h1>
	<div class="subtitle"><?php p($l->t('Powered by ShareGate · buyers pay before download')); ?></div>

	<div class="info-bar" id="info-bar">
		<strong><?php p($l->t('Unlike normal sharing:')); ?></strong>
		<?php p($l->t('buyers must pay before download; payment goes to your configured account.')); ?>
	</div>

	<div class="api-key-bar" id="api-key-bar">
		<label>🔑 Key</label>
		<input type="password" id="api-key-input" placeholder="API Key"
			   oninput="saveApiKey(this.value)">
	</div>

	<div id="form-area">
		<div class="form-group">
			<label><?php p($l->t('File path')); ?></label>
			<input type="text" id="file-path" placeholder="<?php p($l->t('e.g. Documents/report.pdf')); ?>">
		</div>
		<div class="form-group">
			<label><?php p($l->t('File name')); ?></label>
			<input type="text" id="file-name">
		</div>
		<div class="form-group">
			<label><?php p($l->t('Share title')); ?> <span class="required">*</span></label>
			<input type="text" id="share-title" placeholder="<?php p($l->t('e.g. Paid document')); ?>">
		</div>
		<div class="form-row">
			<div class="form-group">
				<label><?php p($_['price_label']); ?> <span class="required">*</span></label>
				<input type="number" id="price-yuan" value="1.00" min="0.01" step="0.01">
				<div class="field-hint"><?php p($_['min_price_hint']); ?></div>
			</div>
			<div class="form-group">
				<label><?php p($l->t('Access days after payment')); ?> <span class="required">*</span></label>
				<input type="number" id="access-days" value="30" min="1" max="365">
			</div>
		</div>
		<div class="form-group">
			<label><?php p($l->t('Link expiry (days)')); ?></label>
			<input type="number" id="expire-days" placeholder="<?php p($l->t('Leave empty for no expiry')); ?>" min="1" max="3650">
		</div>
		<button class="btn btn-primary" id="submit-btn" onclick="handleSubmit()">
			<span class="loading" id="submit-loading"><span class="spinner"></span></span>
			✨ <?php p($l->t('Create share')); ?>
		</button>
		<div class="error-msg" id="error-msg"></div>
	</div>

	<div id="success-area" style="display:none;"></div>

	<div class="footer-link">
		<a id="admin-link" href="#" target="_blank">⚙️ <?php p($l->t('Admin settings (payment account)')); ?></a>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
<script nonce="<?php p($_['csp_nonce']); ?>">
window.__SHAREGATE_EMBED_CONFIG = <?php print_unescaped($_['embed_config']); ?>;
</script>
