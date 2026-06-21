import { alignAppUrlWithPage, getDashboardConfig, getPurchasesConfig, shareApiUrl } from './config.js'
import { getBuyerAccountId, getPurchasesToken, capturePurchasesTokenFromUrl, applyPurchasesSessionFromResponse, canBootstrapPurchasesToken, getLoggedInPaymentAccounts, isValidBuyerAccountId } from './buyerAccount.js'

function getApiConfig() {
	return {
		...getDashboardConfig(),
		...getPurchasesConfig(),
		...(typeof window !== 'undefined' && window.__SHAREGATE_DOWNLOAD_CONFIG) || {},
	}
}

function headers(json = false, { publicGuest = false } = {}) {
	const config = getApiConfig()
	const h = { Accept: 'application/json' }
	if (json) {
		h['Content-Type'] = 'application/json'
	}
	if (publicGuest) {
		h['OCS-APIRequest'] = 'true'
	}
	if (config.requestToken) {
		h.requesttoken = config.requestToken
	}
	return h
}

export function parseApiJson(text, res, url = '') {
	if (!text || !text.trim()) {
		throw new Error(`Empty response (HTTP ${res?.status ?? '?'})${url ? ` for ${url}` : ''}`)
	}
	try {
		return JSON.parse(text)
	} catch {
		const preview = text.trim().slice(0, 160).replace(/\s+/g, ' ')
		const hint = preview.startsWith('<!DOCTYPE') || preview.startsWith('<html')
			? ' (server returned HTML — check API URL or guest access)'
			: ''
		throw new Error(`Invalid JSON (HTTP ${res?.status ?? '?'})${url ? ` for ${url}` : ''}: ${preview}${hint}`)
	}
}

export async function fetchJson(url, options = {}) {
	const res = await fetch(url, {
		credentials: 'same-origin',
		headers: headers(),
		...options,
	})
	const text = await res.text()
	if (!text || !text.trim()) {
		throw new Error(`Empty response (HTTP ${res.status}) for ${url}`)
	}
	const data = parseApiJson(text, res, url)
	if (!res.ok && data?.success !== true) {
		const err = data?.error || data?.message || `HTTP ${res.status}`
		throw new Error(`${err} (${url})`)
	}
	return data
}

export function unwrapApiPayload(data) {
	if (!data || typeof data !== 'object') {
		return data
	}
	if (data.ocs?.data && typeof data.ocs.data === 'object') {
		return data.ocs.data
	}
	return data
}

export async function loadSummary() {
	const config = getDashboardConfig()
	if (!config.summaryUrl) {
		return null
	}
	return unwrapApiPayload(await fetchJson(config.summaryUrl))
}

export async function loadAccountSettings() {
	const config = getDashboardConfig()
	const urls = []
	if (config.accountUrl) {
		urls.push(config.accountUrl)
	}
	if (config.summaryUrl) {
		urls.push(config.summaryUrl)
	}
	if (!urls.length) {
		return null
	}

	let lastError = null
	for (const url of urls) {
		try {
			const data = unwrapApiPayload(await fetchJson(url))
			if (data?.account || data?.payment_config || data?.success === false) {
				return data
			}
			if (data?.success === true) {
				return data
			}
		} catch (e) {
			lastError = e
		}
	}
	if (lastError) {
		throw lastError
	}
	return null
}

export async function loadPaymentConfig() {
	const config = getDashboardConfig()
	if (!config.paymentConfigUrl) {
		throw new Error('API URL missing (paymentConfigUrl)')
	}
	return unwrapApiPayload(await fetchJson(config.paymentConfigUrl))
}

export async function savePaymentConfig(body) {
	const config = getDashboardConfig()
	if (!config.paymentSaveUrl) {
		throw new Error('API URL missing (paymentSaveUrl)')
	}
	const res = await fetch(config.paymentSaveUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: headers(true),
		body: JSON.stringify(body),
	})
	const text = await res.text()
	if (!text || !text.trim()) {
		throw new Error(`Empty response (HTTP ${res.status})`)
	}
	return parseApiJson(text, res, config.paymentSaveUrl)
}

export async function loadStats() {
	const config = getDashboardConfig()
	if (!config.statsUrl) {
		return null
	}
	return fetchJson(config.statsUrl)
}

export async function loadPurchases() {
	const config = getPurchasesConfig()
	if (!config.purchasesUrl) {
		throw new Error('API URL missing (purchasesUrl)')
	}
	capturePurchasesTokenFromUrl()
	const token = getPurchasesToken()
	if (!token) {
		return {
			success: false,
			code: 'PURCHASES_TOKEN_REQUIRED',
			error: 'Purchases session required',
			items: [],
			total: 0,
		}
	}
	const params = new URLSearchParams({
		limit: '100',
		purchases_token: token,
	})
	const url = alignAppUrlWithPage(config.purchasesUrl) + '?' + params.toString()
	const res = await fetch(url, {
		credentials: 'same-origin',
		headers: headers(false, { publicGuest: true }),
	})
	const text = await res.text()
	return unwrapApiPayload(parseApiJson(text, res, url))
}

export async function verifyPayerAccount(payerId, existingToken = '') {
	const config = getPurchasesConfig()
	if (!config.verifyPayerUrl) {
		throw new Error('API URL missing (verifyPayerUrl)')
	}
	const normalized = String(payerId || '').trim()
	if (!normalized || isValidBuyerAccountId(normalized)) {
		return { success: true, found: false, payer_user_id: normalized }
	}
	const body = { payer_id: normalized }
	const token = String(existingToken || getPurchasesToken()).trim()
	if (token) {
		body.purchases_token = token
	}
	const url = alignAppUrlWithPage(config.verifyPayerUrl)
	const res = await fetch(url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: headers(true, { publicGuest: true }),
		body: JSON.stringify(body),
	})
	const text = await res.text()
	return parseApiJson(text, res, url)
}

/**
 * Factor 2 — issue purchases_token using remembered payment accounts (factor 1 + 3).
 * Never uses buyer_xxx. Only runs when canBootstrapPurchasesToken().
 */
export async function bootstrapPurchasesToken() {
	if (!canBootstrapPurchasesToken()) {
		return { bootstrapped: false }
	}
	const payerIds = getLoggedInPaymentAccounts()
	for (const payerId of payerIds) {
		try {
			const data = await verifyPayerAccount(payerId)
			if (data?.success && data.found) {
				applyPurchasesSessionFromResponse(data)
				return { bootstrapped: true, payerId, data }
			}
		} catch {
			// try next remembered account
		}
	}
	return { bootstrapped: false }
}

export async function loadPaymentLedger({ status = 'all', query = '', limit = 100, offset = 0 } = {}) {
	const config = getDashboardConfig()
	if (!config.paymentLedgerUrl) {
		throw new Error('API URL missing (paymentLedgerUrl)')
	}
	const params = new URLSearchParams({
		limit: String(limit),
		offset: String(offset),
		status: String(status || 'all'),
	})
	if (query?.trim()) {
		params.set('q', query.trim())
	}
	return unwrapApiPayload(await fetchJson(config.paymentLedgerUrl + '?' + params.toString()))
}

export async function linkAnonymousPurchases(anonymousBuyerId) {
	const config = getPurchasesConfig()
	if (!config.linkPurchasesUrl) {
		throw new Error('API URL missing (linkPurchasesUrl)')
	}
	const res = await fetch(config.linkPurchasesUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: headers(true),
		body: JSON.stringify({ anonymous_buyer_id: anonymousBuyerId }),
	})
	const text = await res.text()
	return parseApiJson(text, res, config.linkPurchasesUrl)
}

export async function loadShares({ filter, query, offset, limit }) {
	const config = getDashboardConfig()
	const isPaid = filter === 'active'
	const listApi = isPaid ? config.listUrl : config.publicLinksUrl
	if (!listApi) {
		throw new Error('API URL missing (publicLinksUrl / listUrl)')
	}
	const params = new URLSearchParams({
		limit: String(limit),
		offset: String(offset),
	})
	if (isPaid) {
		params.set('filter', filter)
	}
	if (query?.trim()) {
		params.set('q', query.trim())
	}
	return fetchJson(listApi + '?' + params.toString())
}

export async function getShareSettings(shareId) {
	const config = getDashboardConfig()
	return fetchJson(shareApiUrl(config.shareGetUrlTemplate, shareId))
}

export async function updateShareSettings(shareId, body) {
	const config = getDashboardConfig()
	const res = await fetch(shareApiUrl(config.shareUpdateUrlTemplate, shareId), {
		method: 'PUT',
		credentials: 'same-origin',
		headers: headers(true),
		body: JSON.stringify(body),
	})
	return res.json()
}

export async function createShare(body) {
	const config = getDashboardConfig()
	if (!config.createShareUrl) {
		throw new Error('API URL missing (createShareUrl)')
	}
	const res = await fetch(config.createShareUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: headers(true),
		body: JSON.stringify(body),
	})
	const text = await res.text()
	if (!text || !text.trim()) {
		throw new Error(`Empty response (HTTP ${res.status})`)
	}
	return parseApiJson(text, res, config.createShareUrl)
}

export async function disableShare(shareId) {
	const config = getDashboardConfig()
	const res = await fetch(shareApiUrl(config.disableUrlTemplate, shareId), {
		method: 'PATCH',
		credentials: 'same-origin',
		headers: headers(),
	})
	return res.json()
}
