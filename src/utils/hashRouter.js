const NAV_HASH_ALIASES = {
	'sg-nav-public': 'public',
	'sg-nav-paid': 'paid',
	'sg-nav-account': 'account',
	'sg-nav-stats': 'stats',
}

export function normalizeNavHash(raw) {
	const h = String(raw || '').replace(/^#/, '').toLowerCase()
	return NAV_HASH_ALIASES[h] || h
}

export function parseHash(hash = window.location.hash) {
	const h = normalizeNavHash(hash)
	switch (h) {
	case 'public':
		return { view: 'list', filter: 'all', hash: 'public' }
	case 'paid':
		return { view: 'list', filter: 'active', hash: 'paid' }
	case 'stats':
		return { view: 'stats', filter: null, hash: 'stats' }
	case 'account':
		return { view: 'account', filter: null, hash: 'account' }
	default:
		return { view: 'list', filter: 'all', hash: 'public' }
	}
}

export function navHashForView(view, filter) {
	if (view === 'list') {
		return filter === 'active' ? 'paid' : 'public'
	}
	return view
}

export function setHash(hash) {
	const normalized = normalizeNavHash(hash)
	if (normalizeNavHash(window.location.hash) !== normalized) {
		window.location.hash = normalized
	}
}

export function consumeEditParam() {
	const params = new URLSearchParams(window.location.search)
	const editId = params.get('edit')
	if (!editId) {
		return null
	}
	const clean = window.location.pathname + (window.location.hash || '#paid')
	window.history.replaceState(null, '', clean)
	return editId
}
