<?php
/** @var array $_ */
?>
<div class="container">
	<div class="card">
		<div class="loading" id="loading" style="display:block;">
			<div class="spinner"></div>
			<p style="color:#888;font-size:14px;"><?php p($l->t('Loading share info...')); ?></p>
		</div>

		<div id="pay-section" style="display:none;">
			<div class="file-preview" id="file-preview"<?php if (empty($_['file_icon_url'])) { print(' hidden'); } ?>>
				<img id="file-icon" class="file-preview__icon" alt="<?php p($_['file_name'] ?? ''); ?>" width="96" height="96" loading="lazy"<?php if (!empty($_['file_icon_url'])) { ?> src="<?php p($_['file_icon_url']); ?>"<?php } ?> />
			</div>
			<h1 id="share-title">-</h1>
			<div class="desc" id="share-desc"></div>
			<div class="file-info">
				<div><span class="label"><?php p($l->t('File name')); ?></span><span class="value" id="file-name">-</span></div>
				<div><span class="label"><?php p($l->t('File size')); ?></span><span class="value" id="file-size">-</span></div>
				<div><span class="label"><?php p($l->t('Access period')); ?></span><span class="value" id="access-info">-</span></div>
			</div>
			<div class="price">
				<span id="share-price">—</span>
			</div>
			<button class="btn btn-pay" id="pay-btn" type="button"><?php p($l->t('Pay now')); ?></button>
			<div class="qrcode" id="qrcode">
				<div id="qrcode-container"></div>
				<p class="hint" id="pay-hint"><?php p($l->t('Scan with Alipay to pay')); ?></p>
				<p class="hint" style="font-size:11px;"><?php p($l->t('Page refreshes automatically after payment')); ?></p>
			</div>
			<div class="error" id="pay-error"></div>
			<div class="already-paid" id="already-paid">
				<p>✅ <?php p($l->t('You have download access')); ?></p>
				<button class="btn btn-download" id="download-btn-paid" type="button">⬇️ <?php p($l->t('Download now')); ?></button>
				<button class="btn btn-save-cloud" id="save-cloud-btn" style="display:none;" type="button">☁️ <?php p($l->t('Save to my Nextcloud')); ?></button>
				<p class="hint save-cloud-login-hint" id="save-cloud-login-hint-paid" style="display:none;">
					<?php p($l->t('Log in to this Nextcloud account to save the file to your cloud drive.')); ?>
					<a href="<?php p($_['login_url'] ?? ''); ?>"><?php p($l->t('Log in')); ?></a>
				</p>
			</div>
		</div>

		<div class="success" id="success-section" style="display:none;">
			<div class="icon">🎉</div>
			<h2><?php p($l->t('Download access confirmed')); ?></h2>
			<p><?php p($l->t('You can download anytime within the access period')); ?></p>
			<button class="btn btn-download" id="download-btn-success" type="button">⬇️ <?php p($l->t('Start download')); ?></button>
				<button class="btn btn-save-cloud" id="save-cloud-btn-success" style="display:none;" type="button">☁️ <?php p($l->t('Save to my Nextcloud')); ?></button>
				<p class="hint save-cloud-login-hint" id="save-cloud-login-hint-success" style="display:none;">
					<?php p($l->t('Log in to this Nextcloud account to save the file to your cloud drive.')); ?>
					<a href="<?php p($_['login_url'] ?? ''); ?>"><?php p($l->t('Log in')); ?></a>
				</p>
			</div>

		<div class="expired" id="expired-section" style="display:none;">
			<div class="icon">😅</div>
			<h2><?php p($l->t('Share not found or expired')); ?></h2>
		</div>
	</div>
	<div class="footer">Powered by ShareGate</div>
</div>

<script nonce="<?php p($cspNonce); ?>">
window.__SHAREGATE_DOWNLOAD_CONFIG = <?php print_unescaped($_['download_config']); ?>;
</script>
