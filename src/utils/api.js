import { getDashboardConfig, shareApiUrl } from './config.js'

function headers(json = false) {
	const config = getDashboardConfig()
	const h = { Accept: 'application/json' }
	if (json) {
		h['Content-Type'] = 'application/json'
	}
	if (config.requestToken) {
		h.requesttoken = config.requestToken
	}
	return h
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
	let data
	try {
		data = JSON.parse(text)
	} catch (e) {
		const preview = text.trim().slice(0, 160).replace(/\s+/g, ' ')
		throw new Error(`Invalid JSON (HTTP ${res.status}) for ${url}: ${preview}`)
	}
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
	try {
		return JSON.parse(text)
	} catch (e) {
		throw new Error(`Invalid JSON (HTTP ${res.status})`)
	}
}

export async function loadStats() {
	const config = getDashboardConfig()
	if (!config.statsUrl) {
		return null
	}
	return fetchJson(config.statsUrl)
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
	try {
		return JSON.parse(text)
	} catch (e) {
		throw new Error(`Invalid JSON (HTTP ${res.status})`)
	}
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
