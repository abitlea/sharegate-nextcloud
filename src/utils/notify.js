export function showTemporary(message) {
	if (typeof OC !== 'undefined' && OC.Notification) {
		OC.Notification.showTemporary(message)
	}
}

export function showError(message) {
	if (typeof OC !== 'undefined' && OC.Notification) {
		OC.Notification.showTemporary(message, { type: 'error' })
	} else {
		alert(message)
	}
}
