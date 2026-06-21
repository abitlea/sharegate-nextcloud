const STORAGE_KEY = 'sharegate_buyer_id'
const PAYER_ACCOUNTS_KEY = 'sharegate_payer_accounts'
const PURCHASES_TOKEN_KEY = 'sharegate_purchases_token'

export function isValidBuyerAccountId(id) {
	return typeof id === 'string' && /^buyer_[a-zA-Z0-9]{8,120}$/.test(id)
}

export function isValidPayerAccountId(id) {
	if (typeof id !== 'string') {
		return false
	}
	const trimmed = id.trim()
	if (trimmed.length < 3 || trimmed.length > 128) {
		return false
	}
	// Browser session id (buyer_xxx) is not a payment account.
	if (isValidBuyerAccountId(trimmed)) {
		return false
	}
	return /^[^\s<>"'`;]+$/.test(trimmed)
}

/** Session id for in-flight checkout; not used for purchase history after pay. */
export function getBuyerAccountId() {
	let buyerId = ''
	try {
		buyerId = globalThis.localStorage?.getItem(STORAGE_KEY) || ''
	} catch {
		// ignore
	}
	if (buyerId && isValidBuyerAccountId(buyerId)) {
		return buyerId
	}
	buyerId = 'buyer_' + (globalThis.crypto?.randomUUID
		? globalThis.crypto.randomUUID().replace(/-/g, '')
		: String(Date.now()) + Math.random().toString(16).slice(2))
	try {
		globalThis.localStorage?.setItem(STORAGE_KEY, buyerId)
	} catch {
		// ignore
	}
	return buyerId
}

/** Payment provider account ids remembered after successful pay on this device. */
export function getPayerAccountIds() {
	try {
		const raw = globalThis.localStorage?.getItem(PAYER_ACCOUNTS_KEY) || '[]'
		const parsed = JSON.parse(raw)
		if (!Array.isArray(parsed)) {
			return []
		}
		return parsed.filter((id) => isValidPayerAccountId(id))
	} catch {
		return []
	}
}

/** Factor 3 — logged-in payment account(s) (Alipay / PayPal email), not buyer_xxx. */
export function getLoggedInPaymentAccounts() {
	return getPayerAccountIds()
}

export function rememberPayerAccount(payerId) {
	const id = String(payerId || '').trim()
	if (!isValidPayerAccountId(id)) {
		return
	}
	const ids = getPayerAccountIds()
	if (ids.includes(id)) {
		return
	}
	ids.push(id)
	try {
		globalThis.localStorage?.setItem(PAYER_ACCOUNTS_KEY, JSON.stringify(ids))
	} catch {
		// ignore
	}
}

export function payerIdsForAccessCheck() {
	const ids = [...getPayerAccountIds()]
	const sessionId = getBuyerAccountId()
	if (sessionId && !ids.includes(sessionId)) {
		ids.push(sessionId)
	}
	return ids
}

/** Factor 2 — signed purchases_token for list API. */
export function getPurchasesToken() {
	try {
		const token = globalThis.localStorage?.getItem(PURCHASES_TOKEN_KEY) || ''
		return typeof token === 'string' ? token.trim() : ''
	} catch {
		return ''
	}
}

export function hasPurchasesToken() {
	return getPurchasesToken() !== ''
}

export function rememberPurchasesToken(token, { mergePayerId = '' } = {}) {
	const next = String(token || '').trim()
	if (!next) {
		return
	}
	try {
		globalThis.localStorage?.setItem(PURCHASES_TOKEN_KEY, next)
	} catch {
		// ignore
	}
	if (mergePayerId) {
		rememberPayerAccount(mergePayerId)
	}
}

export function clearPurchasesToken() {
	try {
		globalThis.localStorage?.removeItem(PURCHASES_TOKEN_KEY)
	} catch {
		// ignore
	}
}

export function capturePurchasesTokenFromUrl() {
	try {
		const params = new URLSearchParams(globalThis.location?.search || '')
		const token = (params.get('purchases_token') || '').trim()
		if (token) {
			rememberPurchasesToken(token)
		}
		return token
	} catch {
		return ''
	}
}

export function buildPurchasesPageUrl(baseUrl, token) {
	const base = String(baseUrl || '').trim()
	const t = String(token || getPurchasesToken()).trim()
	if (!base || !t) {
		return base
	}
	const join = base.includes('?') ? '&' : '?'
	return base + join + 'purchases_token=' + encodeURIComponent(t)
}

/**
 * Factor 1 — browser has purchase traces (remembered payment account(s)).
 * buyer_xxx alone does NOT count as a trace.
 */
export function hasBrowserPurchaseTraces() {
	return getPayerAccountIds().length > 0
}

/** No traces and no token (typical incognito / first visit). */
export function isUntracedBrowser() {
	return !hasBrowserPurchaseTraces() && !hasPurchasesToken()
}

/** Can open purchase list (valid token, or traces to bootstrap one). */
export function canAccessPurchasesList() {
	return hasPurchasesToken() || hasBrowserPurchaseTraces()
}

/**
 * Download-page toolbar: show when user has traces, or untraced (incognito → login modal).
 * Hide only when purchases_token exists without remembered payer (URL token, no factor 3).
 */
export function shouldShowPurchasesEntry() {
	if (hasPurchasesToken() && !hasBrowserPurchaseTraces()) {
		return false
	}
	return true
}

/** Must prompt for payment account before viewing purchases. */
export function requiresPaymentAccountLogin() {
	return !hasPurchasesToken() && !hasBrowserPurchaseTraces()
}

/** Traced browser missing token — may silently verify remembered payers. */
export function canBootstrapPurchasesToken() {
	return hasBrowserPurchaseTraces() && !hasPurchasesToken()
}

/** @deprecated Use canAccessPurchasesList() */
export function hasLocalBuyerSession() {
	return canAccessPurchasesList()
}

/**
 * Three-factor snapshot for UI / flow decisions.
 * 1. traced — remembered payment account(s)
 * 2. hasToken — purchases_token issued
 * 3. paymentAccounts — logged-in payer id(s)
 */
export function getBuyerAccessState() {
	return {
		traced: hasBrowserPurchaseTraces(),
		untraced: isUntracedBrowser(),
		hasToken: hasPurchasesToken(),
		paymentAccounts: getLoggedInPaymentAccounts(),
		requiresLogin: requiresPaymentAccountLogin(),
		canBootstrapToken: canBootstrapPurchasesToken(),
		canAccessPurchases: canAccessPurchasesList(),
		checkoutSessionId: getBuyerAccountId(),
	}
}

export function applyPurchasesSessionFromResponse(data) {
	if (!data || typeof data !== 'object') {
		return
	}
	const token = String(data.purchases_token || '').trim()
	const payerId = String(data.payer_user_id || '').trim()
	if (!token) {
		return
	}
	// Only merge payment account when server returns a real payer id.
	const mergePayerId = isValidPayerAccountId(payerId) ? payerId : ''
	rememberPurchasesToken(token, { mergePayerId })
}
