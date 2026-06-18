<template>
	<td class="filename files-list__row-name" @click.stop>
		<button
			v-if="canActivate"
			type="button"
			class="files-list__row-name-link"
			:aria-label="displayName"
			:title="openTitle"
			@click="onActivate">
			<span class="files-list__row-icon-preview-container">
				<img
					class="files-list__row-icon-preview sg-file-icon"
					:class="{ 'files-list__row-icon-preview--loaded': iconLoaded }"
					:src="iconUrl"
					alt=""
					aria-hidden="true"
					loading="lazy"
					@load="iconLoaded = true"
					@error="onIconError" />
			</span>
			<span class="files-list__row-name-text">{{ displayName }}</span>
		</button>
		<div v-else class="sg-file-name-readonly">
			<span class="files-list__row-icon-preview-container">
				<img
					class="files-list__row-icon-preview sg-file-icon"
					:class="{ 'files-list__row-icon-preview--loaded': iconLoaded }"
					:src="iconUrl"
					alt=""
					aria-hidden="true"
					loading="lazy"
					@load="iconLoaded = true"
					@error="onIconError" />
			</span>
			<span class="files-list__row-name-text">{{ displayName }}</span>
		</div>
	</td>
</template>

<script>
import { fileIconUrlFromRow, fileMimeIconUrl } from '../utils/files.js'
import { guessMimeFromFileName } from '../utils/mime.js'

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
		activateMode: {
			type: String,
			default: 'file',
			validator: (value) => value === 'file' || value === 'settings',
		},
	},
	emits: ['activate'],
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
		canActivate() {
			if (this.activateMode === 'settings') {
				return Boolean(this.row.share_id)
			}
			return Boolean(this.row.file_id || this.row.file_path)
		},
		openTitle() {
			return this.displayName
		},
	},
	watch: {
		iconUrl() {
			this.iconLoaded = false
			this.useMimeFallback = false
		},
	},
	methods: {
		onActivate() {
			if (!this.canActivate) {
				return
			}
			this.$emit('activate', this.row)
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
