/**
 * ShareGate 支付配置表单（NC 设置页 + 管理台账户绑定共用）
 */
(function () {
	'use strict';

	const cfg = window.__SHAREGATE_ADMIN_CONFIG || {};

	function saveUrl() {
		return cfg.saveUrl || (document.getElementById('sg-save-url') || {}).value || '';
	}

	function headers() {
		const h = {
			Accept: 'application/json',
			'Content-Type': 'application/json',
		};
		if (typeof OC !== 'undefined' && OC.requestToken) {
			h.requesttoken = OC.requestToken;
		}
		return h;
	}

	function syncAlipayPanel() {
		const modeEl = document.getElementById('sg-payment-mode');
		const panel = document.getElementById('sg-alipay-panel');
		if (!modeEl || !panel) {
			return;
		}
		panel.hidden = modeEl.value !== 'alipay_f2f';
	}

	function gatherBody() {
		return {
			payment_mode: document.getElementById('sg-payment-mode').value,
			alipay_f2f: {
				app_id: document.getElementById('sg-app-id').value,
				private_key: document.getElementById('sg-private-key').value,
				alipay_public_key: document.getElementById('sg-public-key').value,
				sandbox: document.getElementById('sg-sandbox').value === 'true',
				notify_url_base: document.getElementById('sg-notify-base').value,
			},
		};
	}

	function applySummary(summary) {
		if (!summary) {
			return;
		}
		const ep = document.getElementById('sg-effective-provider');
		if (ep) {
			ep.textContent = summary.effective_provider || '—';
		}
		if (summary.alipay_f2f) {
			const nu = document.getElementById('sg-notify-url');
			if (nu && summary.alipay_f2f.notify_url) {
				nu.textContent = summary.alipay_f2f.notify_url;
			}
		}
	}

	async function save() {
		const url = saveUrl();
		const status = document.getElementById('sg-save-status');
		const btn = document.getElementById('sg-save-btn');
		if (!url) {
			return;
		}
		if (btn) {
			btn.disabled = true;
		}
		if (status) {
			status.textContent = '';
		}
		try {
			const res = await fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers(),
				body: JSON.stringify(gatherBody()),
			});
			const data = await res.json();
			if (data.success) {
				if (status) {
					status.textContent = data.message || '';
				}
				applySummary(data.summary);
				window.dispatchEvent(new Event('sharegate:payment-saved'));
				if (typeof OC !== 'undefined' && OC.Notification) {
					OC.Notification.showTemporary(data.message || 'Saved');
				}
			} else if (status) {
				status.textContent = data.error || data.message || 'Save failed';
			}
		} catch (e) {
			if (status) {
				status.textContent = e.message;
			}
		} finally {
			if (btn) {
				btn.disabled = false;
			}
		}
	}

	function init() {
		const mode = document.getElementById('sg-payment-mode');
		if (mode) {
			mode.addEventListener('change', syncAlipayPanel);
			syncAlipayPanel();
		}
		const btn = document.getElementById('sg-save-btn');
		if (btn) {
			btn.addEventListener('click', save);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
