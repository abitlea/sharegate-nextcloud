import { translate } from '@nextcloud/l10n'

const APP_ID = 'sharegate'

/** Resolve ShareGate string; use English key when translation equals key or is missing. */
export function t(key) {
	const v = translate(APP_ID, key)
	return v && v !== key ? v : key
}
