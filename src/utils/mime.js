/**
 * 从文件名推断 MIME（付费列表无 file_id/mime_type 时用）
 */
const EXT_MIME = {
	pdf: 'application/pdf',
	doc: 'application/msword',
	docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	xls: 'application/vnd.ms-excel',
	xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	ppt: 'application/vnd.ms-powerpoint',
	pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	txt: 'text/plain',
	md: 'text/markdown',
	jpg: 'image/jpeg',
	jpeg: 'image/jpeg',
	png: 'image/png',
	gif: 'image/gif',
	webp: 'image/webp',
	svg: 'image/svg+xml',
	mp4: 'video/mp4',
	webm: 'video/webm',
	mp3: 'audio/mpeg',
	wav: 'audio/wav',
	zip: 'application/zip',
}

export function guessMimeFromFileName(fileName) {
	const name = String(fileName || '')
	if (typeof OC !== 'undefined' && OC.MimeType?.getMimeType) {
		const mime = OC.MimeType.getMimeType(name)
		if (mime) {
			return mime
		}
	}
	const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : ''
	return EXT_MIME[ext] || 'application/octet-stream'
}

export function mimeCategory(mimeType) {
	const mime = String(mimeType || '').toLowerCase()
	if (mime.startsWith('image/')) {
		return 'image'
	}
	if (mime.startsWith('video/')) {
		return 'video'
	}
	if (mime.startsWith('audio/')) {
		return 'audio'
	}
	if (mime.startsWith('text/') || mime.includes('document') || mime === 'application/pdf') {
		return 'document'
	}
	return 'other'
}
