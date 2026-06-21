/**
 * ShareGate 买家付费下载页
 */
import QRCode from 'qrcode'
import { getBuyerAccountId, payerIdsForAccessCheck, rememberPayerAccount, applyPurchasesSessionFromResponse, buildPurchasesPageUrl, getPurchasesToken, capturePurchasesTokenFromUrl, hasBrowserPurchaseTraces, hasPurchasesToken, isValidPayerAccountId, requiresPaymentAccountLogin, canBootstrapPurchasesToken, shouldShowPurchasesEntry } from '../utils/buyerAccount.js'
import { bootstrapPurchasesToken, verifyPayerAccount } from '../utils/api.js'
import { alignAppUrlWithPage } from '../utils/config.js'

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
		viewMyPurchases: 'View my purchases',
		recoverAccessTitle: 'Already paid? Recover download access',
		recoverAccessHint: 'Enter the full account you used to pay (Alipay phone or email, PayPal or Stripe email). Do not enter the masked account sellers see (e.g. abc***@example.com). Alipay 2088 buyer IDs are also accepted.',
		recoverAccessPlaceholder: 'Alipay / PayPal / Stripe account used to pay',
		recoverAccessButton: 'Recover access',
		recoverAccessFailed: 'Recovery failed',
		crossDeviceLinkTitle: 'Open on another device',
		crossDeviceLinkHint: 'Copy this link to download on another browser or phone',
		copyCrossDeviceLink: 'Copy cross-device link',
		linkCopied: 'Link copied',
		purchasesLoginTitle: 'Sign in to view purchases',
		purchasesLoginHint: 'Enter the full account you used to pay (Alipay phone or email, PayPal or Stripe email). Do not enter the masked account sellers see (e.g. abc***@example.com). Alipay 2088 buyer IDs are also accepted.',
		purchasesLoginButton: 'View my purchases',
		purchasesLoginFailed: 'No purchases found for this payment account',
		purchasesLoginCancel: 'Cancel',
	}

	function appBaseFromLocation() {
		const path = global.location.pathname.replace(/\/s\/[^/]+\/?$/, '')
		return global.location.origin + path
	}

	function serverDefaults() {
		const pathParts = global.location.pathname.split('/')
		const shareId = pathParts[pathParts.length - 1].split('?')[0]
		const appBase = appBaseFromLocation()
		return {
			shareId,
			shareInfoUrl: appBase + '/s/' + encodeURIComponent(shareId) + '/info',
			paymentCreateUrl: appBase + '/payment/create',
			paymentCheckUrl: appBase + '/payment/check/' + encodeURIComponent(shareId),
			paymentVerifyUrl: appBase + '/payment/verify',
			downloadUrl: appBase + '/s/' + encodeURIComponent(shareId) + '/download',
			recoverAccessUrl: appBase + '/api/buyer/recover-access/' + encodeURIComponent(shareId),
			verifyPayerUrl: appBase + '/api/buyer/verify-payer',
			l10n: DEFAULT_L10N,
		}
	}

	/** Inject recover / cross-device blocks when PHP template on server is outdated. */
	function ensureDownloadPageUi(l10n) {
		const paySection = global.document.getElementById('pay-section')
		if (!paySection) {
			return
		}

		if (!global.document.getElementById('recover-access')) {
			const recover = global.document.createElement('div')
			recover.id = 'recover-access'
			recover.className = 'recover-access'
			recover.innerHTML = [
				'<p class="recover-access__title"></p>',
				'<p class="hint recover-access__hint"></p>',
				'<div class="recover-access__row">',
				'  <input type="text" id="recover-payer-input" class="recover-access__input" autocomplete="off" />',
				'  <button class="btn btn-recover" id="recover-access-btn" type="button"></button>',
				'</div>',
				'<p class="error recover-access__error" id="recover-access-error"></p>',
			].join('')
			recover.querySelector('.recover-access__title').textContent = l10n.recoverAccessTitle
			recover.querySelector('.recover-access__hint').textContent = l10n.recoverAccessHint
			recover.querySelector('#recover-payer-input').placeholder = l10n.recoverAccessPlaceholder
			recover.querySelector('#recover-access-btn').textContent = l10n.recoverAccessButton

			const alreadyPaid = global.document.getElementById('already-paid')
			if (alreadyPaid) {
				paySection.insertBefore(recover, alreadyPaid)
			} else {
				paySection.appendChild(recover)
			}
		}

		const alreadyPaid = global.document.getElementById('already-paid')
		if (alreadyPaid && !global.document.getElementById('cross-device-link')) {
			const wrap = global.document.createElement('div')
			wrap.id = 'cross-device-link'
			wrap.className = 'cross-device-link'
			wrap.hidden = true
			wrap.innerHTML = [
				'<p class="cross-device-link__title"></p>',
				'<p class="hint cross-device-link__hint"></p>',
				'<div class="cross-device-link__row">',
				'  <input type="text" id="cross-device-url" class="cross-device-link__input" readonly />',
				'  <button class="btn btn-copy-link" id="copy-cross-device-btn" type="button"></button>',
				'</div>',
			].join('')
			wrap.querySelector('.cross-device-link__title').textContent = l10n.crossDeviceLinkTitle
			wrap.querySelector('.cross-device-link__hint').textContent = l10n.crossDeviceLinkHint
			wrap.querySelector('#copy-cross-device-btn').textContent = l10n.copyCrossDeviceLink

			const saveBtn = global.document.getElementById('save-cloud-btn')
			if (saveBtn && saveBtn.parentNode === alreadyPaid) {
				saveBtn.insertAdjacentElement('afterend', wrap)
			} else {
				alreadyPaid.appendChild(wrap)
			}
		}

		if (!global.document.getElementById('buyer-purchases-corner')) {
			const legacyFooter = global.document.getElementById('buyer-purchases-footer')
			if (legacyFooter) {
				legacyFooter.remove()
			}
			const corner = global.document.createElement('p')
			corner.id = 'buyer-purchases-corner'
			corner.className = 'buyer-purchases-corner'
			corner.innerHTML = '<a href="#" class="buyer-purchases-corner__link" id="buyer-purchases-link"></a>'
			corner.querySelector('#buyer-purchases-link').textContent = l10n.viewMyPurchases
			const card = global.document.querySelector('.card')
			if (card) {
				card.appendChild(corner)
			}
		}

		if (!global.document.getElementById('purchases-login-modal')) {
			const modal = global.document.createElement('div')
			modal.id = 'purchases-login-modal'
			modal.className = 'sg-purchases-login-modal'
			modal.hidden = true
			modal.innerHTML = [
				'<div class="sg-purchases-login-modal__backdrop" id="purchases-login-backdrop"></div>',
				'<div class="sg-purchases-login-modal__card" role="dialog" aria-modal="true">',
				'  <h2 class="sg-purchases-login-modal__title" id="purchases-login-title"></h2>',
				'  <p class="hint sg-purchases-login-modal__hint" id="purchases-login-hint"></p>',
				'  <input type="text" id="purchases-login-input" class="sg-purchases-login-modal__input" autocomplete="off" />',
				'  <p class="error sg-purchases-login-modal__error" id="purchases-login-error"></p>',
				'  <div class="sg-purchases-login-modal__actions">',
				'    <button type="button" class="btn btn-recover" id="purchases-login-submit"></button>',
				'    <button type="button" class="btn btn-copy-link" id="purchases-login-cancel"></button>',
				'  </div>',
				'</div>',
			].join('')
			modal.querySelector('#purchases-login-title').textContent = l10n.purchasesLoginTitle
			modal.querySelector('#purchases-login-hint').textContent = l10n.purchasesLoginHint
			modal.querySelector('#purchases-login-input').placeholder = l10n.recoverAccessPlaceholder
			modal.querySelector('#purchases-login-submit').textContent = l10n.purchasesLoginButton
			modal.querySelector('#purchases-login-cancel').textContent = l10n.purchasesLoginCancel
			global.document.body.appendChild(modal)
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
		return getBuyerAccountId()
	}

	function linkAnonymousPurchasesForSaveToCloud(config) {
		if (!config.linkPurchasesUrl || !config.ncLoggedIn || !config.ncUserId) {
			return Promise.resolve()
		}
		const anonymousId = getBuyerAccountId()
		if (!anonymousId.startsWith('buyer_')) {
			return Promise.resolve()
		}
		const headers = { 'Content-Type': 'application/json', Accept: 'application/json' }
		if (config.requestToken) {
			headers.requesttoken = config.requestToken
		}
		return global.fetch(config.linkPurchasesUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers,
			body: JSON.stringify({ anonymous_buyer_id: anonymousId }),
		}).catch(() => { /* ignore */ })
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
		if (config.verifyPayerUrl) {
			config.verifyPayerUrl = alignAppUrlWithPage(config.verifyPayerUrl)
		}
		if (config.recoverAccessUrl) {
			config.recoverAccessUrl = alignAppUrlWithPage(config.recoverAccessUrl)
		}
		if (config.purchasesPageUrl) {
			config.purchasesPageUrl = alignAppUrlWithPage(config.purchasesPageUrl)
		}
		const l10n = Object.assign({}, DEFAULT_L10N, config.l10n || {})
		if (!config.recoverAccessUrl && config.shareId) {
			config.recoverAccessUrl = appBaseFromLocation()
				+ '/api/buyer/recover-access/'
				+ encodeURIComponent(config.shareId)
		}
		ensureDownloadPageUi(l10n)
		capturePurchasesTokenFromUrl()
		const shareId = config.shareId
		let buyerId = getBuyerId()
		let activeAccessToken = ''
		const urlParams = new URLSearchParams(global.location.search)
		const urlAccessToken = (urlParams.get('access_token') || '').trim()
		/** 通过跨设备链接打开：仅展示当前文件下载，不展示已购/转存/再复制链接等 */
		const openedViaCrossDeviceLink = urlAccessToken !== ''
		let crossDeviceAccessGranted = false
		if (urlAccessToken) {
			activeAccessToken = urlAccessToken
		}

		function applyCrossDeviceVisitorUi() {
			const crossDevice = el('cross-device-link')
			if (crossDevice) {
				crossDevice.hidden = true
			}
			const cornerPurchases = el('buyer-purchases-corner')
			if (cornerPurchases) {
				cornerPurchases.hidden = true
			}
			const recover = el('recover-access')
			if (recover) {
				recover.style.display = 'none'
			}
			const priceWrap = el('share-price')?.parentElement
			if (priceWrap) {
				priceWrap.style.display = 'none'
			}
			const payBtn = el('pay-btn')
			if (payBtn) {
				payBtn.style.display = 'none'
			}
			const qrcode = el('qrcode')
			if (qrcode) {
				qrcode.style.display = 'none'
			}
			;['save-cloud-btn', 'save-cloud-btn-success'].forEach((id) => {
				const btn = el(id)
				if (btn) {
					btn.style.display = 'none'
				}
			})
			;['save-cloud-login-hint-paid', 'save-cloud-login-hint-success'].forEach((id) => {
				const hint = el(id)
				if (hint) {
					hint.style.display = 'none'
				}
			})
		}

		async function bootstrapBuyer() {
			bindActions()
			applyBuyerEntryUiState()
			loadShare()
		}
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

		function handleCancelledReturn() {
			const params = new URLSearchParams(global.location.search)
			const orderId = params.get('order_id')
			if (!orderId || params.get('cancelled') !== '1') {
				return
			}
			const url = paymentStatusUrl(orderId)
			if (url) {
				const notifyUrl = url + (url.includes('?') ? '&' : '?') + 'cancelled=1'
				global.fetch(notifyUrl).catch(function () { /* ignore */ })
			}
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

		function applyPaidPayer(statusData) {
			if (!statusData || typeof statusData !== 'object') {
				return
			}
			const payerId = String(statusData.payer_user_id || '').trim()
			if (statusData.access_token) {
				activeAccessToken = statusData.access_token
			}
			// 已购 token 仅在确认真实支付账号后才写入（③）；buyer_xxx 不算登录账号
			if (!isValidPayerAccountId(payerId)) {
				return
			}
			rememberPayerAccount(payerId)
			buyerId = payerId
			applyPurchasesSessionFromResponse(statusData)
		}

		function showCrossDeviceLink(statusData) {
			const wrap = el('cross-device-link')
			const input = el('cross-device-url')
			if (!wrap || !input) {
				return
			}
			const link = (statusData && statusData.cross_device_url) || ''
			if (!link) {
				wrap.hidden = true
				return
			}
			input.value = link
			wrap.hidden = false
		}

		function showPaidSuccess(statusData) {
			if (openedViaCrossDeviceLink) {
				if (statusData?.access_token) {
					activeAccessToken = String(statusData.access_token).trim()
				}
				crossDeviceAccessGranted = true
			} else {
				applyPaidPayer(statusData)
			}
			stopPolling()
			const errorEl = el('pay-error')
			if (errorEl) {
				errorEl.textContent = ''
			}
			const recover = el('recover-access')
			if (recover) {
				recover.style.display = 'none'
			}
			el('qrcode').style.display = 'none'
			el('already-paid').style.display = 'block'
			el('pay-btn').style.display = 'none'
			if (openedViaCrossDeviceLink) {
				applyCrossDeviceVisitorUi()
			} else {
				showCrossDeviceLink(statusData)
				updateSaveCloudUi(true)
				applyBuyerEntryUiState()
			}
		}

		function isPaidUiVisible() {
			const paid = el('already-paid')
			return !!(paid && paid.style.display === 'block')
		}

		/** 已付款→跨设备链接为主；已购入口固定在卡片右下角 */
		function applyBuyerEntryUiState() {
			if (openedViaCrossDeviceLink && crossDeviceAccessGranted) {
				applyCrossDeviceVisitorUi()
				return
			}
			const showPurchases = shouldShowPurchasesEntry()
			const paid = isPaidUiVisible()
			const recover = el('recover-access')
			const cornerPurchases = el('buyer-purchases-corner')
			if (recover) {
				recover.style.display = (showPurchases || paid) ? 'none' : ''
			}
			if (cornerPurchases) {
				cornerPurchases.hidden = false
			}
		}

		async function ensurePurchasesToken() {
			if (hasPurchasesToken() || !canBootstrapPurchasesToken()) {
				return
			}
			await bootstrapPurchasesToken()
		}

		function hidePurchasesLoginModal() {
			const modal = el('purchases-login-modal')
			if (modal) {
				modal.hidden = true
			}
		}

		function showPurchasesLoginModal() {
			const modal = el('purchases-login-modal')
			const input = el('purchases-login-input')
			const errorEl = el('purchases-login-error')
			if (!modal) {
				return
			}
			if (errorEl) {
				errorEl.textContent = ''
			}
			if (input) {
				input.value = ''
			}
			modal.hidden = false
			if (input) {
				input.focus()
			}
		}

		function navigateToPurchasesPage(url) {
			const target = url || buildPurchasesPageUrl(config.purchasesPageUrl || '')
			if (target) {
				global.location.assign(target)
			}
		}

		global.openPurchasesPage = async function () {
			const base = config.purchasesPageUrl || ''
			if (!base) {
				return
			}
			// 无痕迹且无 token：必须输入支付账号
			if (requiresPaymentAccountLogin()) {
				showPurchasesLoginModal()
				return
			}
			// 有痕迹但无 token：用记住的支付账号静默签发
			if (canBootstrapPurchasesToken()) {
				await ensurePurchasesToken()
			}
			if (hasPurchasesToken()) {
				navigateToPurchasesPage(buildPurchasesPageUrl(base))
				return
			}
			showPurchasesLoginModal()
		}

		global.submitPurchasesLogin = async function () {
			const input = el('purchases-login-input')
			const errorEl = el('purchases-login-error')
			const submitBtn = el('purchases-login-submit')
			const payerRaw = input ? String(input.value || '').trim() : ''
			if (!payerRaw || !isValidPayerAccountId(payerRaw)) {
				if (errorEl) {
					errorEl.textContent = l10n.purchasesLoginHint
				}
				return
			}
			if (!config.verifyPayerUrl) {
				if (errorEl) {
					errorEl.textContent = l10n.purchasesLoginFailed
				}
				return
			}
			if (submitBtn) {
				submitBtn.disabled = true
			}
			if (errorEl) {
				errorEl.textContent = ''
			}
			try {
				const data = await verifyPayerAccount(payerRaw)
				if (!data.success) {
					if (errorEl) {
						errorEl.textContent = data.error || l10n.purchasesLoginFailed
					}
					return
				}
				if (!data.found) {
					if (errorEl) {
						errorEl.textContent = l10n.purchasesLoginFailed
					}
					return
				}
				applyPurchasesSessionFromResponse(data)
				applyBuyerEntryUiState()
				hidePurchasesLoginModal()
				navigateToPurchasesPage(data.purchases_url)
			} catch (err) {
				if (errorEl) {
					errorEl.textContent = l10n.purchasesLoginFailed + ': ' + err.message
				}
			} finally {
				if (submitBtn) {
					submitBtn.disabled = false
				}
			}
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
			const recoverBtn = el('recover-access-btn')
			if (recoverBtn) {
				recoverBtn.addEventListener('click', () => global.recoverAccess())
			}
			const copyBtn = el('copy-cross-device-btn')
			if (copyBtn) {
				copyBtn.addEventListener('click', () => global.copyCrossDeviceLink())
			}
			const purchasesLink = el('buyer-purchases-link')
			if (purchasesLink) {
				purchasesLink.addEventListener('click', (e) => {
					e.preventDefault()
					global.openPurchasesPage()
				})
			}
			const purchasesBackdrop = el('purchases-login-backdrop')
			if (purchasesBackdrop) {
				purchasesBackdrop.addEventListener('click', () => hidePurchasesLoginModal())
			}
			const purchasesCancel = el('purchases-login-cancel')
			if (purchasesCancel) {
				purchasesCancel.addEventListener('click', () => hidePurchasesLoginModal())
			}
			const purchasesSubmit = el('purchases-login-submit')
			if (purchasesSubmit) {
				purchasesSubmit.addEventListener('click', () => global.submitPurchasesLogin())
			}
			const purchasesInput = el('purchases-login-input')
			if (purchasesInput) {
				purchasesInput.addEventListener('keydown', (e) => {
					if (e.key === 'Enter') {
						e.preventDefault()
						global.submitPurchasesLogin()
					}
				})
			}
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
				handleCancelledReturn()
				handleReturnFromPayment()
				if (!openedViaCrossDeviceLink && hasBrowserPurchaseTraces()) {
					await ensurePurchasesToken()
				}
				applyBuyerEntryUiState()
				showLoading(false)
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
			if (activeAccessToken) {
				try {
					const url = config.paymentCheckUrl
						+ '?access_token=' + encodeURIComponent(activeAccessToken)
					const res = await global.fetch(url)
					const data = await res.json()
					if (data.has_access) {
						let statusData = { access_token: activeAccessToken }
						try {
							const verifyRes = await global.fetch(config.paymentVerifyUrl, {
								method: 'POST',
								headers: { 'Content-Type': 'application/json' },
								body: JSON.stringify({
									share_id: shareId,
									access_token: activeAccessToken,
								}),
							})
							const verifyData = await verifyRes.json()
							if (verifyData.success) {
								statusData = verifyData
							}
						} catch (e) { /* ignore */ }
						showPaidSuccess(statusData)
						return
					}
				} catch (e) { /* ignore */ }
			}

			const payerIds = payerIdsForAccessCheck()
			for (const payerId of payerIds) {
				try {
					const url = config.paymentCheckUrl + '?provider_user_id=' + encodeURIComponent(payerId)
					const res = await global.fetch(url)
					const data = await res.json()
					if (data.has_access) {
						buyerId = payerId
						let statusData = { payer_user_id: payerId }
						try {
							const verifyRes = await global.fetch(config.paymentVerifyUrl, {
								method: 'POST',
								headers: { 'Content-Type': 'application/json' },
								body: JSON.stringify({
									share_id: shareId,
									provider_user_id: payerId,
								}),
							})
							const verifyData = await verifyRes.json()
							if (verifyData.success) {
								statusData = verifyData
							}
						} catch (e) { /* ignore */ }
						showPaidSuccess(statusData)
						return
					}
				} catch (e) { /* ignore */ }
			}
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
						applyBuyerEntryUiState()
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
					showPaidSuccess(statusData)
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

			const payerIds = payerIdsForAccessCheck()
			for (const payerId of payerIds) {
				const checkRes = await global.fetch(
					config.paymentCheckUrl + '?provider_user_id=' + encodeURIComponent(payerId),
				)
				const checkData = await checkRes.json()
				if (checkData.has_access) {
					buyerId = payerId
					showPaidSuccess()
					return true
				}
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

		global.recoverAccess = async function () {
			const input = el('recover-payer-input')
			const errorEl = el('recover-access-error')
			const btn = el('recover-access-btn')
			const payerRaw = input ? String(input.value || '').trim() : ''
			if (!payerRaw) {
				if (errorEl) {
					errorEl.textContent = l10n.recoverAccessHint
				}
				return
			}
			if (!config.recoverAccessUrl) {
				if (errorEl) {
					errorEl.textContent = l10n.recoverAccessFailed
				}
				return
			}
			if (btn) {
				btn.disabled = true
			}
			if (errorEl) {
				errorEl.textContent = ''
			}
			try {
				const res = await global.fetch(config.recoverAccessUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					body: JSON.stringify({ payer_id: payerRaw }),
				})
				const data = await res.json()
				if (data.success) {
					showPaidSuccess(data)
					if (data.cross_device_url && global.history && global.history.replaceState) {
						try {
							const next = new URL(global.location.href)
							if (data.access_token) {
								next.searchParams.set('access_token', data.access_token)
							}
							global.history.replaceState({}, '', next.toString())
						} catch (e) { /* ignore */ }
					}
					return
				}
				if (errorEl) {
					errorEl.textContent = data.error || l10n.recoverAccessFailed
				}
			} catch (err) {
				if (errorEl) {
					errorEl.textContent = l10n.recoverAccessFailed + ': ' + err.message
				}
			} finally {
				if (btn) {
					btn.disabled = false
				}
			}
		}

		global.copyCrossDeviceLink = async function () {
			const input = el('cross-device-url')
			const btn = el('copy-cross-device-btn')
			const link = input ? String(input.value || '').trim() : ''
			if (!link) {
				return
			}
			try {
				if (global.navigator && global.navigator.clipboard && global.navigator.clipboard.writeText) {
					await global.navigator.clipboard.writeText(link)
				} else if (input) {
					input.select()
					global.document.execCommand('copy')
				}
				if (btn) {
					const label = btn.textContent
					btn.textContent = l10n.linkCopied
					setTimeout(() => { btn.textContent = label }, 2000)
				}
			} catch (e) {
				global.alert(link)
			}
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
				await linkAnonymousPurchasesForSaveToCloud(config)
				const headers = { 'Content-Type': 'application/json' }
				if (config.requestToken) {
					headers.requesttoken = config.requestToken
				}
				const body = { provider_user_id: buyerId }
				if (activeAccessToken) {
					body.access_token = activeAccessToken
				}
				const res = await global.fetch(config.saveToCloudUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers,
					body: JSON.stringify(body),
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
				const body = {
					share_id: shareId,
					provider_user_id: buyerId,
				}
				if (activeAccessToken) {
					body.access_token = activeAccessToken
				}
				const res = await global.fetch(config.paymentVerifyUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(body),
				})
				const data = await res.json()
				if (!data.success || data.code !== 'ACCESS_GRANTED') {
					global.alert(l10n.downloadPermissionDenied + ': ' + (data.message || data.error || ''))
					return
				}

				if (data.access_token) {
					activeAccessToken = data.access_token
				}

				const downloadUrl = data.download_url
					|| (activeAccessToken
						? (config.downloadUrl + '?access_token=' + encodeURIComponent(activeAccessToken))
						: (config.downloadUrl + '?uid=' + encodeURIComponent(buyerId)))
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

		bootstrapBuyer()
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
