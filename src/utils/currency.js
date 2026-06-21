import { tf } from './l10n.js'
import { getDashboardConfig } from './config.js'

let cachedDisplayCurrency = ''

const CURRENCY_SYMBOLS = {
	usd: '$',
	eur: '€',
	gbp: '£',
	cad: 'CA$',
	aud: 'A$',
	chf: 'CHF ',
	sgd: 'S$',
	hkd: 'HK$',
	nzd: 'NZ$',
	jpy: '¥',
}

export function setDisplayCurrency(currency) {
	cachedDisplayCurrency = String(currency || '').trim().toUpperCase()
}

export function getDisplayCurrency() {
	if (cachedDisplayCurrency) {
		return cachedDisplayCurrency
	}
	const config = getDashboardConfig()
	const fromConfig = config.displayCurrency
		|| config.display_currency
		|| config.account?.display_currency
		|| config.payment_config?.display_currency
	return String(fromConfig || 'CNY').trim().toUpperCase() || 'CNY'
}

/** Label unit for column headers: CNY → 元 / CNY; others → ISO code */
export function currencyUnitLabel(currency) {
	const code = String(currency || getDisplayCurrency()).toUpperCase()
	if (code === 'CNY') {
		return tf('currency_unit_CNY')
	}
	return code
}

export function priceColumnLabel(currency) {
	return tf('Price (%s)', currencyUnitLabel(currency))
}

export function revenueColumnLabel(currency) {
	return tf('Revenue (%s)', currencyUnitLabel(currency))
}

const ZERO_DECIMAL_CURRENCIES = new Set(['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'])

export function usesMinorUnits(currency) {
	const code = String(currency || getDisplayCurrency()).toUpperCase()
	return !ZERO_DECIMAL_CURRENCIES.has(code)
}

export function majorAmountToMinor(amount, currency) {
	const value = Number(amount)
	if (!Number.isFinite(value) || value <= 0) {
		return 0
	}
	if (usesMinorUnits(currency)) {
		return Math.round(value * 100)
	}
	return Math.round(value)
}

export function minorAmountToMajor(minor, currency) {
	const value = Number(minor)
	if (!Number.isFinite(value)) {
		return ''
	}
	if (usesMinorUnits(currency)) {
		return (value / 100).toFixed(2)
	}
	return String(Math.round(value))
}

export function priceInputConfig(currency) {
	if (usesMinorUnits(currency)) {
		return { min: 0.01, step: 0.01 }
	}
	return { min: 1, step: 1 }
}

export function minimumPriceHint(currency) {
	const code = String(currency || getDisplayCurrency()).toUpperCase()
	if (usesMinorUnits(code)) {
		return tf('Minimum 0.01 %s', currencyUnitLabel(currency))
	}
	return tf('Minimum 1 %s', currencyUnitLabel(currency))
}

export function priceChargedInHint(currency) {
	return tf('Charged in %s per your payment settings', currencyUnitLabel(currency))
}

export function applyDisplayCurrencyFromPayload(data) {
	const currency = data?.account?.display_currency
		|| data?.payment_config?.display_currency
		|| data?.display_currency
	if (currency) {
		setDisplayCurrency(currency)
		return String(currency).trim().toUpperCase()
	}
	return getDisplayCurrency()
}

export function formatMoney(cents, currency) {
	const amount = Number(cents)
	if (!Number.isFinite(amount)) {
		return '—'
	}
	const code = String(currency || getDisplayCurrency()).toLowerCase()
	if (code === 'cny') {
		const yuan = amount / 100
		const suffix = tf('currency_unit_CNY')
		if (Number.isInteger(yuan)) {
			return yuan + suffix
		}
		return yuan.toFixed(2) + suffix
	}
	const sym = CURRENCY_SYMBOLS[code] || code.toUpperCase() + ' '
	if (code === 'jpy') {
		return sym + Math.round(amount).toLocaleString()
	}
	return sym + (amount / 100).toFixed(2)
}
