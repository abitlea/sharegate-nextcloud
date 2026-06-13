const APP_ID = 'sharegate'

/** @type {Record<string, string>} */
const DEFAULT_PATHS = {
	dashboardUrl: `/apps/${APP_ID}/`,
	publicLinksUrl: `/apps/${APP_ID}/api/files/public-links`,
	listUrl: `/apps/${APP_ID}/api/dashboard/shares`,
	summaryUrl: `/apps/${APP_ID}/api/dashboard/summary`,
	accountUrl: `/apps/${APP_ID}/api/dashboard/account`,
	statsUrl: `/apps/${APP_ID}/api/dashboard/stats`,
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

function resolveAppUrl(configured, defaultPath) {
	if (configured && typeof configured === 'string' && configured.trim()) {
		return configured
	}
	if (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function') {
		return OC.generateUrl(defaultPath)
	}
	const base = appBaseFromLocation()
	if (defaultPath.startsWith(`/apps/${APP_ID}`)) {
		return base + defaultPath.slice(`/apps/${APP_ID}`.length)
	}
	return base + defaultPath
}

export function getDashboardConfig() {
	const raw = window.__sharegateDashboard || {}
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
