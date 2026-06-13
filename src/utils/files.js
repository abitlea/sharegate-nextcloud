/**
 * Nextcloud 文件图标与打开（对齐 apps/files usePreviewImage）
 */
import { generateUrl } from '@nextcloud/router'
import { guessMimeFromFileName } from './mime.js'

function resolveMimeType(mimeType, fileName) {
	let mime = mimeType
	if ((!mime || mime === 'application/octet-stream') && fileName) {
		if (typeof OC !== 'undefined' && OC.MimeType?.getMimeType) {
			const guessed = OC.MimeType.getMimeType(fileName)
			if (guessed) {
				mime = guessed
			}
		} else {
			mime = guessMimeFromFileName(fileName)
		}
	}
	return mime || 'application/octet-stream'
}

function absoluteUrl(path) {
	return new URL(path, window.location.origin).href
}

/** /core/mimeicon?mime=… — 与 NC Files 列表一致 */
export function fileMimeIconUrl(mimeType, fileName) {
	const mime = resolveMimeType(mimeType, fileName)
	return absoluteUrl(generateUrl('/core/mimeicon?mime={mime}', { mime }))
}

/** /core/preview?fileId=… — 与 NC Files FileEntryPreview 一致 */
export function filePreviewIconUrl(fileId, options = {}) {
	const id = Number(fileId)
	if (!id) {
		return ''
	}
	const size = options.size ?? 32
	const crop = options.crop ?? false
	const url = new URL(absoluteUrl(generateUrl('/core/preview?fileId={fileid}', {
		fileid: String(id),
	})))
	url.searchParams.set('x', String(size))
	url.searchParams.set('y', String(size))
	url.searchParams.set('mimeFallback', 'true')
	const etag = String(options.etag || options.mtime || '').slice(0, 6)
	if (etag) {
		url.searchParams.set('v', etag)
	}
	url.searchParams.set('a', crop ? '0' : '1')
	return url.href
}

/**
 * 列表行图标：有 file_id 用 preview（mimeFallback），否则 mimeicon
 */
export function fileIconUrlFromRow(row, size = 32) {
	const fileName = row?.file_name || row?.title || ''
	const mime = resolveMimeType(row?.mime_type, fileName)
	if (row?.file_id) {
		return filePreviewIconUrl(row.file_id, {
			size,
			mimeType: mime,
			fileName,
			mtime: row.file_mtime,
			etag: row.etag,
		})
	}
	return fileMimeIconUrl(mime, fileName)
}

export function fileOpenUrl(fileId) {
	if (!fileId) {
		return ''
	}
	if (typeof OC !== 'undefined' && OC.generateUrl) {
		return OC.generateUrl('/f/' + fileId)
	}
	return '/f/' + fileId
}

function userRelativePath(filePath) {
	const path = String(filePath || '').replace(/^\/+/, '')
	const parts = path.split('/')
	const filesIdx = parts.indexOf('files')
	if (filesIdx !== -1 && parts.length > filesIdx + 2) {
		return parts.slice(filesIdx + 2).join('/')
	}
	return path
}

export function fileOpenUrlFromRow(row) {
	if (!row) {
		return ''
	}
	const byId = fileOpenUrl(row.file_id)
	if (byId) {
		return byId
	}
	const relative = userRelativePath(row.file_path)
	if (!relative) {
		return ''
	}
	const parts = relative.split('/')
	const fileName = parts.pop() || ''
	const dir = parts.length ? '/' + parts.join('/') : '/'
	const query = '?dir=' + encodeURIComponent(dir) + '&openfile=' + encodeURIComponent(fileName)
	if (typeof OC !== 'undefined' && OC.generateUrl) {
		return OC.generateUrl('/apps/files/') + query
	}
	return '/apps/files/' + query
}

export function openUserFile(row) {
	const url = fileOpenUrlFromRow(row)
	if (!url) {
		return false
	}
	window.location.href = url
	return true
}
