/**
 * ShareGate 买家付费下载页
 */
import QRCode from 'qrcode'

(function (global) {
	'use strict'

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
		}
	}

	function formatFileSize(bytes) {
		if (!bytes || bytes === 0) return '未知'
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

	async function renderQrCode(data, qrContainer) {
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
				img.alt = '支付二维码'
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
				img.alt = '支付二维码'
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
		const shareId = config.shareId
		const buyerId = getBuyerId()
		let pollTimer = null
		let currentOrderId = null

		function paymentStatusUrl(orderId) {
			if (!config.paymentStatusUrlTemplate || !orderId) {
				return ''
			}
			return config.paymentStatusUrlTemplate.replace('__OID__', encodeURIComponent(orderId))
		}

		function showPaidSuccess() {
			if (pollTimer) {
				clearInterval(pollTimer)
				pollTimer = null
			}
			el('qrcode').style.display = 'none'
			el('already-paid').style.display = 'block'
			el('pay-btn').style.display = 'none'
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
		}

		function showLoading(show) {
			const loading = el('loading')
			if (loading) loading.style.display = show ? 'block' : 'none'
		}

		async function loadShare() {
			showLoading(true)
			try {
				const res = await global.fetch(config.shareInfoUrl)
				if (!res.ok) {
					if (res.status === 404) throw new Error('NOT_FOUND')
					throw new Error('请求失败')
				}
				const shareData = await res.json()
				showLoading(false)
				el('pay-section').style.display = 'block'
				el('share-title').textContent = shareData.title
				el('share-desc').textContent = shareData.description || '付费内容，扫码支付后即可下载'
				el('file-name').textContent = shareData.file_name
				el('file-size').textContent = formatFileSize(shareData.file_size)
				el('access-info').textContent = shareData.access_days + ' 天'
				el('share-price').textContent = shareData.price_yuan
				await checkPaidStatus()
			} catch (err) {
				showLoading(false)
				if (err.message === 'NOT_FOUND') {
					el('expired-section').style.display = 'block'
				} else {
					el('pay-section').style.display = 'block'
					el('pay-error').textContent = '加载失败: ' + err.message
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
				}
			} catch (e) { /* ignore */ }
		}

		global.startPay = async function () {
			const btn = el('pay-btn')
			const errorEl = el('pay-error')
			const qrContainer = el('qrcode-container')
			btn.disabled = true
			btn.textContent = '生成支付二维码...'
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
						return
					}
					if (!data.qr_code && !data.payment_url && !data.qr_url && !data.qr_image && !data.qr_svg) {
						errorEl.textContent = data.error || '创建支付成功但未返回二维码数据'
						btn.disabled = false
						btn.textContent = '📱 扫码支付'
						return
					}
					el('qrcode').style.display = 'block'
					const rendered = await renderQrCode(data, qrContainer)
					if (!rendered) {
						el('qrcode').style.display = 'none'
						errorEl.textContent = '二维码生成失败，请刷新后重试'
						btn.disabled = false
						btn.textContent = '📱 扫码支付'
						return
					}
					pollPaymentStatus(data.order_id)
					btn.textContent = '等待扫码支付...'
				} else {
					errorEl.textContent = data.error || '创建支付失败'
					btn.disabled = false
					btn.textContent = '📱 扫码支付'
				}
			} catch (err) {
				errorEl.textContent = '请求失败: ' + err.message
				btn.disabled = false
				btn.textContent = '📱 扫码支付'
			}
		}

		function pollPaymentStatus(orderId) {
			if (orderId) {
				currentOrderId = orderId
			}
			let attempts = 0
			if (pollTimer) clearInterval(pollTimer)
			pollTimer = setInterval(async function () {
				attempts++
				try {
					if (currentOrderId && config.paymentStatusUrlTemplate) {
						const statusUrl = paymentStatusUrl(currentOrderId)
						const statusRes = await global.fetch(statusUrl)
						if (statusRes.ok) {
							const statusData = await statusRes.json()
							if (statusData.success && statusData.status === 'paid') {
								const checkRes = await global.fetch(
									config.paymentCheckUrl + '?provider_user_id=' + encodeURIComponent(buyerId),
								)
								const checkData = await checkRes.json()
								if (checkData.has_access) {
									showPaidSuccess()
								}
								return
							}
						}
					}
					const url = config.paymentCheckUrl + '?provider_user_id=' + encodeURIComponent(buyerId)
					const res = await global.fetch(url)
					const data = await res.json()
					if (data.has_access) {
						showPaidSuccess()
					}
				} catch (e) { /* ignore */ }
				if (attempts >= 60) {
					clearInterval(pollTimer)
					pollTimer = null
					el('pay-btn').disabled = false
					el('pay-btn').textContent = '📱 扫码支付'
					el('pay-error').textContent = '支付超时，请重新扫码'
				}
			}, 3000)
		}

		function triggerFileDownload(url) {
			const iframe = global.document.createElement('iframe')
			iframe.style.display = 'none'
			iframe.src = url
			global.document.body.appendChild(iframe)
			global.setTimeout(() => iframe.remove(), 60000)
		}

		global.startDownload = async function () {
			const buttons = ['download-btn-paid', 'download-btn-success'].map((id) => el(id)).filter(Boolean)
			buttons.forEach((btn) => {
				btn.disabled = true
				btn.dataset.sgLabel = btn.textContent
				btn.textContent = '下载中...'
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
				if (data.success && data.code === 'ACCESS_GRANTED') {
					const downloadUrl = data.download_url || (config.downloadUrl + '?uid=' + encodeURIComponent(buyerId))
					triggerFileDownload(downloadUrl)
				} else {
					global.alert('下载权限验证失败: ' + (data.message || data.error || ''))
				}
			} catch (err) {
				global.alert('下载失败: ' + err.message)
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
