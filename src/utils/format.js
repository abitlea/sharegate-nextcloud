import { translate } from '@nextcloud/l10n'

function t(key, fallback) {
	const v = translate('sharegate', key)
	return v && v !== key ? v : fallback
}

export function formatSize(bytes) {
	if (!bytes && bytes !== 0) {
		return '—'
	}
	if (bytes < 1024) {
		return bytes + ' B'
	}
	if (bytes < 1024 * 1024) {
		return (bytes / 1024).toFixed(1) + ' KB'
	}
	if (bytes < 1024 * 1024 * 1024) {
		return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
	}
	return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB'
}

export function formatRelativeDate(ms) {
	if (!ms) {
		return '—'
	}
	try {
		const diff = Date.now() - ms
		const days = Math.floor(diff / 86400000)
		if (days < 1) {
			return t('Today', '今天')
		}
		return days + t(' days ago', '天前')
	} catch {
		return '—'
	}
}

export function formatShareDate(ms) {
	if (!ms) {
		return '—'
	}
	try {
		const d = new Date(ms)
		const pad = (n) => String(n).padStart(2, '0')
		return d.getFullYear() + '/'
			+ pad(d.getMonth() + 1) + '/'
			+ pad(d.getDate()) + ' '
			+ pad(d.getHours()) + ':'
			+ pad(d.getMinutes())
	} catch {
		return '—'
	}
}

/** 将 API 返回的相对路径转为可分享的完整 URL */
export function buildPublicUrl(path) {
	if (!path) {
		return ''
	}
	if (/^https?:\/\//i.test(path)) {
		return path
	}
	const origin = typeof window !== 'undefined' && window.location?.origin
		? window.location.origin
		: ''
	if (!origin) {
		return path
	}
	return origin + (path.startsWith('/') ? path : '/' + path)
}

export function formatPriceYuan(cents) {
	const yuan = cents / 100
	const suffix = t('yuan', '元')
	if (Number.isInteger(yuan)) {
		return yuan + suffix
	}
	return yuan.toFixed(2) + suffix
}

export function formatNavCounter(value) {
	const n = Number(value)
	if (!n || n <= 0) {
		return 0
	}
	return n > 999 ? 999 : n
}
