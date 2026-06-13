<?php
/**
 * 支付配置表单片段（NC 设置页与管理台账户绑定页共用）
 *
 * @var array $form 需含 payment_mode, effective_provider, alipay, save_url
 * @var \OCP\IL10N $l
 */
$alipay = $form['alipay'];
?>
<div id="sharegate-admin-settings" class="sg-account-form">
	<p>
		<strong><?php p($l->t('Effective provider')); ?>:</strong>
		<span id="sg-effective-provider"><?php p($form['effective_provider']); ?></span>
	</p>

	<p>
		<label for="sg-payment-mode"><?php p($l->t('Payment mode')); ?></label>
		<select id="sg-payment-mode">
			<option value="mock" <?php if ($form['payment_mode'] === 'mock') { p('selected'); } ?>>
				<?php p($l->t('Mock (development)')); ?>
			</option>
			<option value="alipay_f2f" <?php if ($form['payment_mode'] === 'alipay_f2f') { p('selected'); } ?>>
				<?php p($l->t('Alipay Face-to-Face')); ?>
			</option>
		</select>
	</p>

	<div id="sg-alipay-panel" class="sg-alipay-panel">
		<h3><?php p($l->t('Alipay Face-to-Face')); ?></h3>
		<p>
			<label for="sg-app-id">App ID</label>
			<input type="text" id="sg-app-id" class="sg-input-wide"
				   value="<?php p($alipay['app_id']); ?>" autocomplete="off">
		</p>
		<p>
			<label for="sg-private-key"><?php p($l->t('Application private key')); ?></label>
			<textarea id="sg-private-key" rows="4" class="sg-input-wide" autocomplete="off"><?php p($alipay['private_key']); ?></textarea>
		</p>
		<p>
			<label for="sg-public-key"><?php p($l->t('Alipay public key')); ?></label>
			<textarea id="sg-public-key" rows="4" class="sg-input-wide" autocomplete="off"><?php p($alipay['alipay_public_key']); ?></textarea>
		</p>
		<p>
			<label for="sg-sandbox"><?php p($l->t('Sandbox mode')); ?></label>
			<select id="sg-sandbox">
				<option value="true" <?php if ($alipay['sandbox']) { p('selected'); } ?>><?php p($l->t('Yes (sandbox)')); ?></option>
				<option value="false" <?php if (!$alipay['sandbox']) { p('selected'); } ?>><?php p($l->t('No (production)')); ?></option>
			</select>
		</p>
		<p>
			<label for="sg-notify-base"><?php p($l->t('Notify URL base (optional)')); ?></label>
			<input type="text" id="sg-notify-base" class="sg-input-wide"
				   placeholder="<?php p($l->t('Leave empty to use Nextcloud site URL')); ?>"
				   value="<?php p($alipay['notify_url_base']); ?>">
		</p>
		<p class="settings-hint">
			<?php p($l->t('Async notify URL (register in Alipay console)')); ?>:
			<code id="sg-notify-url"><?php p($alipay['notify_url']); ?></code>
		</p>
	</div>

	<p>
		<button type="button" id="sg-save-btn" class="button primary"><?php p($l->t('Save settings')); ?></button>
		<span id="sg-save-status" class="sg-save-status"></span>
	</p>
</div>

<input type="hidden" id="sg-save-url" value="<?php p($form['save_url']); ?>">
