import { translate } from '@nextcloud/l10n'

const APP_ID = 'sharegate'

/** Resolve ShareGate string; use English key when translation equals key or is missing. */
export function t(key) {
	const v = translate(APP_ID, key)
	return v && v !== key ? v : key
}

/** Translate with %s placeholders (in order). */
export function tf(key, ...replacements) {
	let text = t(key)
	for (const value of replacements) {
		text = text.replace('%s', String(value))
	}
	return text
}
