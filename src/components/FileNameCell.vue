<template>
	<td class="filename" @dblclick.stop="openFile">
		<div class="sg-file-name">
			<img
				class="files-list__row-icon-preview sg-file-icon"
				:class="{ 'files-list__row-icon-preview--loaded': iconLoaded }"
				:src="iconUrl"
				alt=""
				aria-hidden="true"
				loading="lazy"
				@load="iconLoaded = true"
				@error="onIconError" />
			<span class="sg-file-name__text" :title="displayName">{{ displayName }}</span>
		</div>
	</td>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import { fileIconUrlFromRow, fileMimeIconUrl, openUserFile } from '../utils/files.js'
import { guessMimeFromFileName } from '../utils/mime.js'
import { showTemporary } from '../utils/notify.js'

export default {
	name: 'FileNameCell',
	props: {
		row: {
			type: Object,
			required: true,
		},
		name: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			iconLoaded: false,
			useMimeFallback: false,
		}
	},
	computed: {
		displayName() {
			return this.name || this.row.file_name || this.row.title || '—'
		},
		rowMime() {
			return this.row.mime_type || guessMimeFromFileName(this.displayName)
		},
		iconUrl() {
			if (this.useMimeFallback) {
				return fileMimeIconUrl(this.rowMime, this.displayName)
			}
			return fileIconUrlFromRow(this.row, 32)
		},
	},
	watch: {
		iconUrl() {
			this.iconLoaded = false
			this.useMimeFallback = false
		},
	},
	methods: {
		openFile() {
			if (!openUserFile(this.row)) {
				const msg = translate('sharegate', 'Cannot open file')
				showTemporary(msg && msg !== 'Cannot open file' ? msg : '无法打开该文件')
			}
		},
		onIconError() {
			if (!this.useMimeFallback && this.row.file_id) {
				this.useMimeFallback = true
				this.iconLoaded = false
			}
		},
	},
}
</script>
