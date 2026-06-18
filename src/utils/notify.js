export function showTemporary(message) {
	if (typeof OC !== 'undefined' && OC.Notification?.showTemporary) {
		OC.Notification.showTemporary(message)
		return
	}
	if (typeof window !== 'undefined' && window.OC?.Notification?.showTemporary) {
		window.OC.Notification.showTemporary(message)
	}
}

export function showError(message) {
	if (typeof OC !== 'undefined' && OC.Notification) {
		OC.Notification.showTemporary(message, { type: 'error' })
	} else {
		alert(message)
	}
}
