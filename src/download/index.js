/**
 * ShareGate 买家付费下载页
 */
import QRCode from 'qrcode'

(function (global) {
	'use strict'

	const DEFAULT_L10N = {
		unknown: 'Unknown',
		paidContentDefault: 'Paid content. Scan to pay, then download.',
		days: ' days',
		requestFailed: 'Request failed',
		loadingFailed: 'Loading failed',
		generatingQr: 'Generating payment QR code...',
		payNow: 'Pay now',
		scanToPay: 'Scan to pay',
		payWithCardHint: 'You will be redirected to Stripe to pay by card or wallet.',
		payWithPayPalHint: 'You will be redirected to PayPal to complete payment.',
		scanWithAlipay: 'Scan with Alipay to pay',
		redirectingToPayment: 'Redirecting to payment...',
		confirmingPayment: 'Confirming payment...',
		paymentQrCode: 'Payment QR code',
		waitingForPayment: 'Waiting for scan payment...',
		noQrReturned: 'Payment created but no QR code returned',
		qrGenerationFailed: 'QR code generation failed, please refresh',
		createPaymentFailed: 'Failed to create payment',
		paymentTimedOut: 'Payment timed out. Please scan again.',
		downloading: 'Downloading...',
		downloadPermissionDenied: 'Download permission denied',
		downloadFailed: 'Download failed',
		savingToCloud: 'Saving to your Nextcloud...',
		savedToCloud: 'File saved to your Nextcloud',
		saveToCloudFailed: 'Save to cloud failed',
		saveToCloudLoginHint: 'Log in to this Nextcloud account to save the file to your cloud drive.',
	}

	function serverDefaults() {
		const pathParts = global.location.pathname.split('/')
		const shareId = pathParts[pathParts.length - 1]
		const origin = global.location.origin
		return {
			shareId,
			shareInfoUrl: origin + '/share/' + shareId,
			paymentCreateUrl: origin + '/payment/create',
			paymentCheckUrl: origin + '/payment/check/' + shareId,
			paymentVerifyUrl: origin + '/payment/verify',
			downloadUrl: origin + '/api/download/' + shareId,
			l10n: DEFAULT_L10N,
		}
	}

	function formatFileSize(bytes, l10n) {
		if (!bytes || bytes === 0) return l10n.unknown
		const units = ['B', 'KB', 'MB', 'GB', 'TB']
		let i = 0
		let size = bytes
		while (size >= 1024 && i < units.length - 1) {
			size /= 1024
			i++
		}
		return size.toFixed(1) + ' ' + units[i]
	}

	function getBuyerId() {
		let buyerId = global.localStorage && global.localStorage.getItem('sharegate_buyer_id')
		if (!buyerId) {
			buyerId = 'buyer_' + (global.crypto && global.crypto.randomUUID
				? global.crypto.randomUUID().replace(/-/g, '')
				: String(Date.now()) + Math.random().toString(16).slice(2))
			if (global.localStorage) global.localStorage.setItem('sharegate_buyer_id', buyerId)
		}
		return buyerId
	}

	async function renderQrCode(data, qrContainer, l10n) {
		const payload = data.payment_url || data.qr_code
		if (payload) {
			try {
				const canvas = global.document.createElement('canvas')
				await QRCode.toCanvas(canvas, payload, { width: 220, margin: 1 })
				qrContainer.appendChild(canvas)
				return true
			} catch (e) { /* try fallbacks below */ }
		}
		if (data.qr_svg) {
			qrContainer.innerHTML = data.qr_svg
			const svg = qrContainer.querySelector('svg')
			if (svg) {
				svg.setAttribute('width', '220')
				svg.setAttribute('height', '220')
			}
			return true
		}
		if (data.qr_url) {
			return await new Promise((resolve) => {
				const img = global.document.createElement('img')
				img.alt = l10n.paymentQrCode
				img.width = 220
				img.height = 220
				img.onload = () => resolve(true)
				img.onerror = () => resolve(false)
				img.src = data.qr_url
				qrContainer.appendChild(img)
			})
		}
		if (data.qr_image) {
			return await new Promise((resolve) => {
				const img = global.document.createElement('img')
				img.alt = l10n.paymentQrCode
				img.width = 220
				img.height = 220
				img.onload = () => resolve(true)
				img.onerror = () => resolve(false)
				img.src = data.qr_image
				qrContainer.appendChild(img)
			})
		}
		return false
	}

	function init(userConfig) {
		const config = Object.assign(serverDefaults(), userConfig || {})
		const l10n = Object.assign({}, DEFAULT_L10N, config.l10n || {})
		const shareId = config.shareId
		let buyerId = getBuyerId()
		let pollTimer = null
		let currentOrderId = null
		let paymentFlow = 'qrcode'
		let paymentProvider = 'alipay_f2f'
		const scanToPayLabel = () => '📱 ' + l10n.scanToPay
		const payNowLabel = () => l10n.payNow

		function applyPaymentUi() {
			const btn = el('pay-btn')
			const hint = el('pay-hint')
			if (paymentFlow === 'redirect') {
				if (btn) btn.textContent = payNowLabel()
				if (hint) {
					hint.textContent = paymentProvider === 'paypal'
						? l10n.payWithPayPalHint
						: l10n.payWithCardHint
				}
			} else {
				if (btn) btn.textContent = scanToPayLabel()
				if (hint) hint.textContent = l10n.scanWithAlipay
			}
		}

		function rememberOrderBuyer(orderId) {
			if (!orderId || !global.sessionStorage) {
				return
			}
			try {
				global.sessionStorage.setItem('sharegate_order_buyer_' + orderId, buyerId)
			} catch (e) { /* ignore */ }
		}

		function buyerIdForOrder(orderId) {
			if (orderId && global.sessionStorage) {
				try {
					const stored = global.sessionStorage.getItem('sharegate_order_buyer_' + orderId)
					if (stored) {
						return stored
					}
				} catch (e) { /* ignore */ }
			}
			return buyerId
		}

		function returnOrderIdFromUrl() {
			const params = new URLSearchParams(global.location.search)
			const orderId = params.get('order_id')
			if (!orderId || params.get('cancelled') === '1') {
				return ''
			}
			if (params.get('session_id') || params.get('token') || params.get('PayerID')) {
				return orderId
			}
			return ''
		}

		function handleReturnFromPayment() {
			const orderId = returnOrderIdFromUrl()
			if (!orderId) {
				return
			}
			buyerId = buyerIdForOrder(orderId)
			const btn = el('pay-btn')
			if (btn) {
				btn.disabled = true
				btn.textContent = l10n.confirmingPayment
			}
			const hint = el('pay-hint')
			if (hint) {
				hint.textContent = l10n.confirmingPayment
			}
			const errorEl = el('pay-error')
			if (errorEl) {
				errorEl.textContent = ''
			}
			pollPaymentStatus(orderId)
		}

		function paymentStatusUrl(orderId) {
			if (!orderId) {
				return ''
			}
			let url = ''
			if (config.paymentStatusUrlTemplate) {
				url = config.paymentStatusUrlTemplate
					.replace('__OID__', encodeURIComponent(orderId))
					.replace('%5F%5FOID%5F%5F', encodeURIComponent(orderId))
			} else if (config.paymentCreateUrl) {
				url = config.paymentCreateUrl.replace(/\/payment\/create\/?$/, '')
					+ '/payment/status/' + encodeURIComponent(orderId)
			}
			if (!url) {
				return ''
			}
			const params = new URLSearchParams(global.location.search)
			const paypalToken = params.get('token')
			if (paypalToken) {
				url += (url.includes('?') ? '&' : '?')
					+ 'paypal_token=' + encodeURIComponent(paypalToken)
			}
			return url
		}

		function stopPolling() {
			if (pollTimer) {
				clearInterval(pollTimer)
				pollTimer = null
			}
		}

		function showPaidSuccess() {
			stopPolling()
			const errorEl = el('pay-error')
			if (errorEl) {
				errorEl.textContent = ''
			}
			el('qrcode').style.display = 'none'
			el('already-paid').style.display = 'block'
			el('pay-btn').style.display = 'none'
			updateSaveCloudUi(true)
		}

		function updateSaveCloudUi(hasAccess) {
			const showSave = !!(hasAccess && config.ncLoggedIn && config.saveToCloudUrl)
			const showLoginHint = !!(hasAccess && !config.ncLoggedIn)
			;['save-cloud-btn', 'save-cloud-btn-success'].forEach((id) => {
				const btn = el(id)
				if (btn) {
					btn.style.display = showSave ? '' : 'none'
				}
			})
			;['save-cloud-login-hint-paid', 'save-cloud-login-hint-success'].forEach((id) => {
				const hint = el(id)
				if (hint) {
					hint.style.display = showLoginHint ? '' : 'none'
				}
			})
		}

		if (global.localStorage) {
			global.localStorage.setItem('sharegate_last_share', shareId)
		}

		function el(id) { return global.document.getElementById(id) }

		function bindActions() {
			const payBtn = el('pay-btn')
			if (payBtn) {
				payBtn.addEventListener('click', () => global.startPay())
			}
			;['download-btn-paid', 'download-btn-success'].forEach((id) => {
				const btn = el(id)
				if (btn) {
					btn.addEventListener('click', () => global.startDownload())
				}
			})
			;['save-cloud-btn', 'save-cloud-btn-success'].forEach((id) => {
				const btn = el(id)
				if (btn) {
					btn.addEventListener('click', () => global.startSaveToCloud())
				}
			})
		}

		function showLoading(show) {
			const loading = el('loading')
			if (loading) loading.style.display = show ? 'block' : 'none'
		}

		function applyFilePreview(shareData) {
			const wrap = el('file-preview')
			const img = el('file-icon')
			if (!wrap || !img) {
				return
			}
			const previewUrl = shareData.icon_url || config.previewIconUrl || ''
			const mimeIconUrl = shareData.mime_icon_url || config.mimeIconUrl || ''
			if (!previewUrl && !mimeIconUrl) {
				wrap.hidden = true
				return
			}
			img.alt = shareData.file_name || shareData.title || ''
			img.onerror = function () {
				if (mimeIconUrl && img.src !== mimeIconUrl) {
					img.src = mimeIconUrl
					return
				}
				wrap.hidden = true
			}
			// Always show MIME icon first; only swap when /icon returns a real image
			if (mimeIconUrl) {
				img.src = mimeIconUrl
			}
			if (previewUrl && previewUrl !== mimeIconUrl) {
				const probe = new Image()
				probe.onload = function () {
					if (probe.naturalWidth > 0 && probe.naturalHeight > 0) {
						img.src = previewUrl
					}
				}
				probe.src = previewUrl
			} else if (!mimeIconUrl && previewUrl) {
				img.src = previewUrl
			}
			wrap.hidden = false
		}

		async function loadShare() {
			showLoading(true)
			try {
				const res = await global.fetch(config.shareInfoUrl)
				if (!res.ok) {
					if (res.status === 404) throw new Error('NOT_FOUND')
					throw new Error(l10n.requestFailed)
				}
				const shareData = await res.json()
				showLoading(false)
				el('pay-section').style.display = 'block'
				el('share-title').textContent = shareData.title
				el('share-desc').textContent = shareData.description || l10n.paidContentDefault
				applyFilePreview(shareData)
				el('file-name').textContent = shareData.file_name
				el('file-size').textContent = formatFileSize(shareData.file_size, l10n)
				el('access-info').textContent = shareData.access_days + l10n.days
				el('share-price').textContent = shareData.price_display || shareData.price_yuan
				paymentFlow = shareData.payment_flow || 'qrcode'
				paymentProvider = shareData.payment_provider || paymentProvider
				await checkPaidStatus()
				if (!returnOrderIdFromUrl()) {
					applyPaymentUi()
				}
				handleReturnFromPayment()
			} catch (err) {
				showLoading(false)
				if (err.message === 'NOT_FOUND') {
					el('expired-section').style.display = 'block'
				} else {
					el('pay-section').style.display = 'block'
					el('pay-error').textContent = l10n.loadingFailed + ': ' + err.message
				}
			}
		}

		async function checkPaidStatus() {
			try {
				const url = config.paymentCheckUrl + '?provider_user_id=' + encodeURIComponent(buyerId)
				const res = await global.fetch(url)
				const data = await res.json()
				if (data.has_access) {
					el('already-paid').style.display = 'block'
					el('pay-btn').style.display = 'none'
					updateSaveCloudUi(true)
				}
			} catch (e) { /* ignore */ }
		}

		global.startPay = async function () {
			const btn = el('pay-btn')
			const errorEl = el('pay-error')
			const qrContainer = el('qrcode-container')
			btn.disabled = true
			btn.textContent = paymentFlow === 'redirect'
				? l10n.redirectingToPayment
				: l10n.generatingQr
			errorEl.textContent = ''
			qrContainer.innerHTML = ''

			try {
				const res = await global.fetch(config.paymentCreateUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						share_id: shareId,
						provider: '',
						provider_user_id: buyerId,
					}),
				})
				const data = await res.json()

				if (data.success) {
					if (data.already_paid) {
						el('already-paid').style.display = 'block'
						btn.style.display = 'none'
						updateSaveCloudUi(true)
						return
					}
					if (data.payment_flow === 'redirect' && data.payment_url) {
						rememberOrderBuyer(data.order_id)
						global.location.href = data.payment_url
						return
					}
					if (!data.qr_code && !data.payment_url && !data.qr_url && !data.qr_image && !data.qr_svg) {
						errorEl.textContent = data.error || l10n.noQrReturned
						btn.disabled = false
						btn.textContent = paymentFlow === 'redirect' ? payNowLabel() : scanToPayLabel()
						return
					}
					el('qrcode').style.display = 'block'
					const rendered = await renderQrCode(data, qrContainer, l10n)
					if (!rendered) {
						el('qrcode').style.display = 'none'
						errorEl.textContent = l10n.qrGenerationFailed
						btn.disabled = false
						btn.textContent = scanToPayLabel()
						return
					}
					pollPaymentStatus(data.order_id)
					btn.textContent = l10n.waitingForPayment
				} else {
					errorEl.textContent = data.error || l10n.createPaymentFailed
					btn.disabled = false
					btn.textContent = paymentFlow === 'redirect' ? payNowLabel() : scanToPayLabel()
				}
			} catch (err) {
				errorEl.textContent = l10n.requestFailed + ': ' + err.message
				btn.disabled = false
				btn.textContent = paymentFlow === 'redirect' ? payNowLabel() : scanToPayLabel()
			}
		}

		async function pollPaymentStatusOnce(attempts) {
			const activeBuyerId = buyerIdForOrder(currentOrderId)
			const errorEl = el('pay-error')
			const statusUrl = paymentStatusUrl(currentOrderId)

			if (statusUrl) {
				const statusRes = await global.fetch(statusUrl)
				let statusData = {}
				try {
					statusData = await statusRes.json()
				} catch (e) { /* ignore */ }
				if (statusData.success && statusData.status === 'paid') {
					showPaidSuccess()
					return true
				}
				if (statusData.error && errorEl) {
					errorEl.textContent = statusData.error
				}
				if (!statusData.success && statusData.error) {
					const fatal = /not configured|authentication failed|Order not found/i.test(statusData.error)
					if (fatal) {
						stopPolling()
						const btn = el('pay-btn')
						if (btn) {
							btn.disabled = false
							btn.textContent = paymentFlow === 'redirect' ? payNowLabel() : scanToPayLabel()
						}
						return true
					}
				}
			} else if (errorEl && attempts === 1) {
				errorEl.textContent = l10n.requestFailed
			}

			const checkRes = await global.fetch(
				config.paymentCheckUrl + '?provider_user_id=' + encodeURIComponent(activeBuyerId),
			)
			const checkData = await checkRes.json()
			if (checkData.has_access) {
				showPaidSuccess()
				return true
			}

			if (attempts >= 60) {
				stopPolling()
				const btn = el('pay-btn')
				if (btn) {
					btn.disabled = false
					btn.textContent = paymentFlow === 'redirect' ? payNowLabel() : scanToPayLabel()
				}
				if (errorEl) {
					errorEl.textContent = l10n.paymentTimedOut
				}
			}
			return false
		}

		function pollPaymentStatus(orderId) {
			if (orderId) {
				currentOrderId = orderId
				rememberOrderBuyer(orderId)
			}
			let attempts = 0
			if (pollTimer) clearInterval(pollTimer)
			const tick = async function () {
				attempts++
				try {
					await pollPaymentStatusOnce(attempts)
				} catch (e) { /* ignore */ }
			}
			tick()
			pollTimer = setInterval(tick, 3000)
		}

		function triggerFileDownload(url) {
			// Hidden iframe often fails silently for attachment responses; navigate directly instead.
			global.location.assign(url)
		}

		global.startSaveToCloud = async function () {
			if (!config.saveToCloudUrl) {
				global.alert(l10n.saveToCloudFailed)
				return
			}
			if (!config.ncLoggedIn) {
				global.alert(l10n.saveToCloudLoginHint)
				return
			}

			const buttons = ['save-cloud-btn', 'save-cloud-btn-success'].map((id) => el(id)).filter(Boolean)
			buttons.forEach((btn) => {
				btn.disabled = true
				btn.dataset.sgLabel = btn.textContent
				btn.textContent = l10n.savingToCloud
			})

			try {
				const headers = { 'Content-Type': 'application/json' }
				if (config.requestToken) {
					headers.requesttoken = config.requestToken
				}
				const res = await global.fetch(config.saveToCloudUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers,
					body: JSON.stringify({
						provider_user_id: buyerId,
					}),
				})
				let data = {}
				try {
					data = await res.json()
				} catch {
					// ignore non-JSON bodies
				}
				if (data.success) {
					const target = data.path || data.file_name || 'ShareGate'
					global.alert(l10n.savedToCloud + '\n' + target)
				} else {
					const detail = data.error || data.message || (data.data && data.data.message) || ''
					const suffix = detail || (res.status ? 'HTTP ' + res.status : '')
					global.alert(suffix ? (l10n.saveToCloudFailed + ': ' + suffix) : l10n.saveToCloudFailed)
				}
			} catch (err) {
				global.alert(l10n.saveToCloudFailed + ': ' + err.message)
			} finally {
				buttons.forEach((btn) => {
					btn.disabled = false
					if (btn.dataset.sgLabel) {
						btn.textContent = btn.dataset.sgLabel
					}
				})
			}
		}

		global.startDownload = async function () {
			const buttons = ['download-btn-paid', 'download-btn-success'].map((id) => el(id)).filter(Boolean)
			buttons.forEach((btn) => {
				btn.disabled = true
				btn.dataset.sgLabel = btn.textContent
				btn.textContent = l10n.downloading
			})

			try {
				const res = await global.fetch(config.paymentVerifyUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						share_id: shareId,
						provider_user_id: buyerId,
					}),
				})
				const data = await res.json()
				if (!data.success || data.code !== 'ACCESS_GRANTED') {
					global.alert(l10n.downloadPermissionDenied + ': ' + (data.message || data.error || ''))
					return
				}

				const downloadUrl = data.download_url || (config.downloadUrl + '?uid=' + encodeURIComponent(buyerId))
				if (!downloadUrl) {
					global.alert(l10n.downloadFailed)
					return
				}
				triggerFileDownload(downloadUrl)
			} catch (err) {
				global.alert(l10n.downloadFailed + ': ' + err.message)
			} finally {
				buttons.forEach((btn) => {
					btn.disabled = false
					if (btn.dataset.sgLabel) {
						btn.textContent = btn.dataset.sgLabel
					}
				})
			}
		}

		bindActions()
		loadShare()
	}

	global.ShareGateDownload = { init, serverDefaults }
	global.QRCode = QRCode

	if ('__SHAREGATE_DOWNLOAD_CONFIG' in global) {
		const boot = function () { init(global.__SHAREGATE_DOWNLOAD_CONFIG) }
		if (global.document.readyState === 'loading') {
			global.document.addEventListener('DOMContentLoaded', boot)
		} else {
			boot()
		}
	}
})(typeof window !== 'undefined' ? window : this)
