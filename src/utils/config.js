const APP_ID = 'sharegate'

import { getBuyerAccountId } from './buyerAccount.js'

/** @type {Record<string, string>} */
const DEFAULT_PATHS = {
	dashboardUrl: `/apps/${APP_ID}/`,
	publicLinksUrl: `/apps/${APP_ID}/api/files/public-links`,
	listUrl: `/apps/${APP_ID}/api/dashboard/shares`,
	summaryUrl: `/apps/${APP_ID}/api/dashboard/summary`,
	accountUrl: `/apps/${APP_ID}/api/dashboard/account`,
	statsUrl: `/apps/${APP_ID}/api/dashboard/stats`,
	paymentLedgerUrl: `/apps/${APP_ID}/api/dashboard/payment-ledger`,
	linkPurchasesUrl: `/apps/${APP_ID}/api/buyer/link-purchases`,
	purchasesUrl: `/apps/${APP_ID}/api/buyer/purchases`,
	verifyPayerUrl: `/apps/${APP_ID}/api/buyer/verify-payer`,
	paymentVerifyUrl: `/apps/${APP_ID}/payment/verify`,
	createUrl: `/apps/${APP_ID}/embed/create`,
	createShareUrl: `/apps/${APP_ID}/share/create`,
	shareGetUrlTemplate: `/apps/${APP_ID}/api/share/__SHARE_ID__`,
	shareUpdateUrlTemplate: `/apps/${APP_ID}/share/__SHARE_ID__`,
	disableUrlTemplate: `/apps/${APP_ID}/share/__SHARE_ID__/disable`,
	paymentConfigUrl: `/apps/${APP_ID}/admin/payment-config`,
	paymentSaveUrl: `/apps/${APP_ID}/admin/payment-config`,
}

function appBaseFromLocation() {
	const m = window.location.pathname.match(/^(.*\/apps\/sharegate)\/?/)
	return m ? m[1] : `/apps/${APP_ID}`
}

/** Keep API URLs consistent with the current page (e.g. insert index.php when needed). */
export function alignAppUrlWithPage(url) {
	if (!url || typeof url !== 'string') {
		return url
	}
	const trimmed = url.trim()
	if (!trimmed || trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
		return trimmed
	}
	const pagePath = window.location.pathname
	if (!pagePath.includes('/index.php/') || trimmed.includes('/index.php/')) {
		return trimmed
	}
	const marker = `/apps/${APP_ID}`
	const idx = trimmed.indexOf(marker)
	if (idx === -1) {
		return trimmed
	}
	return trimmed.slice(0, idx + 1) + 'index.php' + trimmed.slice(idx)
}

function resolveAppUrl(configured, defaultPath) {
	let resolved = ''
	if (configured && typeof configured === 'string' && configured.trim()) {
		resolved = configured.trim()
	} else if (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function') {
		resolved = OC.generateUrl(defaultPath)
	} else {
		const base = appBaseFromLocation()
		if (defaultPath.startsWith(`/apps/${APP_ID}`)) {
			resolved = base + defaultPath.slice(`/apps/${APP_ID}`.length)
		} else {
			resolved = base + defaultPath
		}
	}
	return alignAppUrlWithPage(resolved)
}

export function getDashboardConfig() {
	return mergeAppConfig(window.__sharegateDashboard || {})
}

export function getPurchasesConfig() {
	const merged = mergeAppConfig(
		window.__sharegateBuyerPurchases
		|| window.__sharegateDashboard
		|| (typeof window !== 'undefined' ? window.__SHAREGATE_DOWNLOAD_CONFIG : null)
		|| {},
	)
	if (!merged.buyerAccountId) {
		merged.buyerAccountId = getBuyerAccountId()
	}
	return merged
}

function mergeAppConfig(raw) {
	/** @type {Record<string, unknown>} */
	const merged = { ...raw }

	for (const [key, defaultPath] of Object.entries(DEFAULT_PATHS)) {
		merged[key] = resolveAppUrl(merged[key], defaultPath)
	}

	if (!merged.requestToken && typeof OC !== 'undefined' && OC.requestToken) {
		merged.requestToken = OC.requestToken
	}

	return merged
}

export function shareApiUrl(template, shareId) {
	const tpl = (template && String(template).trim())
		? template
		: getDashboardConfig().shareGetUrlTemplate
	return String(tpl).replace('__SHARE_ID__', encodeURIComponent(shareId))
}
