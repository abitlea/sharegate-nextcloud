<template>
	<div class="files-list sg-stats-panel">
		<div class="files-list__header">
			<div class="files-list__header-spacer" aria-hidden="true" />
			<FilesListBreadcrumbs
				:title="breadcrumbTitle"
				:view-icon="breadcrumbIcon"
				@reload="reload" />
			<div class="files-list__toolbar" data-test-id="files-list-filters">
				<NcActions
					type="tertiary"
					:menu-name="typeFilterLabel"
					:aria-label="typeFilterLabel"
					force-menu>
					<template #icon>
						<FileDocumentOutline :size="20" />
					</template>
					<NcActionButton
						:close-after-click="true"
						@click="typeFilter = 'all'">
						{{ t('All types') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="typeFilter = 'document'">
						{{ t('Documents') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="typeFilter = 'image'">
						{{ t('Images') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="typeFilter = 'video'">
						{{ t('Videos') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="typeFilter = 'audio'">
						{{ t('Audio') }}
					</NcActionButton>
				</NcActions>
				<NcButton
					type="tertiary"
					:pressed="shareTimeSort === 'asc'"
					@click="toggleShareTimeSort">
					<template #icon>
						<CalendarMonth :size="20" />
					</template>
					{{ shareTimeSortLabel }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" class="sg-dashboard__loading" :size="32" />
		<p v-else-if="loadError" class="warning">{{ loadError }}</p>
		<div v-else-if="!displayItems.length" class="emptycontent">
			{{ emptyMessage }}
		</div>
		<div v-else class="files-filestable">
			<table class="files-list__table sg-table">
				<thead class="files-list__thead">
					<tr class="files-list__row-head">
						<th v-for="col in columns" :key="col.key">
							<span class="columntitle">{{ col.label }}</span>
						</th>
					</tr>
				</thead>
				<tbody class="files-list__tbody files-fileList">
					<tr
						v-for="row in displayItems"
						:key="row.share_id || row.file_name"
						class="files-list__row">
						<FileNameCell :row="row" @activate="openFile" />
						<td>{{ shareStatusLabel(row.share_status_label) }}</td>
						<td>{{ formatShareDate(row.created_at) }}</td>
						<td>{{ formatMoney(row.price) }}</td>
						<td>{{ formatMoney(row.revenue || 0) }}</td>
						<td>{{ row.preview_count || 0 }}</td>
						<td>{{ row.save_count || 0 }}</td>
						<td>{{ row.download_count || 0 }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { t } from '../utils/l10n.js'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CalendarMonth from 'vue-material-design-icons/CalendarMonth.vue'
import ChartDonut from 'vue-material-design-icons/ChartDonut.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileNameCell from './FileNameCell.vue'
import FilesListBreadcrumbs from './FilesListBreadcrumbs.vue'
import { loadStats } from '../utils/api.js'
import { openUserFile } from '../utils/files.js'
import { showTemporary } from '../utils/notify.js'
import { formatShareDate } from '../utils/format.js'
import { formatMoney, priceColumnLabel, revenueColumnLabel } from '../utils/currency.js'
import { guessMimeFromFileName, mimeCategory } from '../utils/mime.js'

export default {
	name: 'StatsPanel',
	components: {
		FileNameCell,
		FilesListBreadcrumbs,
		NcActionButton,
		NcActions,
		NcButton,
		NcLoadingIcon,
		CalendarMonth,
		ChartDonut,
		FileDocumentOutline,
	},
	props: {
		searchQuery: {
			type: String,
			default: '',
		},
		displayCurrency: {
			type: String,
			default: 'CNY',
		},
	},
	data() {
		return {
			loading: false,
			loadError: '',
			items: [],
			typeFilter: 'all',
			shareTimeSort: 'desc',
		}
	},
	computed: {
		breadcrumbTitle() {
			return this.t('Statistics')
		},
		breadcrumbIcon() {
			return ChartDonut
		},
		typeFilterLabel() {
			const labels = {
				all: this.t('Type'),
				document: this.t('Documents'),
				image: this.t('Images'),
				video: this.t('Videos'),
				audio: this.t('Audio'),
				other: this.t('Other'),
			}
			return labels[this.typeFilter] || labels.all
		},
		shareTimeSortLabel() {
			return this.shareTimeSort === 'asc'
				? this.t('Share time (oldest first)')
				: this.t('Share time')
		},
		displayItems() {
			let list = [...this.items]
			const q = String(this.searchQuery || '').trim().toLowerCase()
			if (q) {
				list = list.filter((row) => this.matchesSearch(row, q))
			}
			if (this.typeFilter !== 'all') {
				list = list.filter((row) => this.matchesTypeFilter(row))
			}
			list.sort((a, b) => this.compareCreatedAt(a, b))
			return list
		},
		emptyMessage() {
			if (this.items.length && !this.displayItems.length) {
				return this.t('No statistics match the filter')
			}
			return this.t('No statistics yet')
		},
		columns() {
			const currency = this.displayCurrency
			return [
				{ key: 'name', label: this.t('File') },
				{ key: 'status', label: this.t('Share status') },
				{ key: 'time', label: this.t('Share time') },
				{ key: 'price', label: priceColumnLabel(currency) },
				{ key: 'revenue', label: revenueColumnLabel(currency) },
				{ key: 'preview', label: this.t('Preview count') },
				{ key: 'save', label: this.t('Save to cloud count') },
				{ key: 'download', label: this.t('Download count') },
			]
		},
	},
	mounted() {
		this.reload()
	},
	methods: {
		formatShareDate,
		formatMoney(cents) {
			return formatMoney(cents, this.displayCurrency)
		},
		t,
		rowMime(row) {
			return row.mime_type || guessMimeFromFileName(row.file_name)
		},
		matchesTypeFilter(row) {
			const cat = mimeCategory(this.rowMime(row))
			return this.typeFilter === 'other' ? cat === 'other' : cat === this.typeFilter
		},
		matchesSearch(row, query) {
			const parts = [
				row.file_name,
				row.share_status_label,
				this.shareStatusLabel(row.share_status_label),
				row.share_id,
			]
			const haystack = parts.filter(Boolean).join(' ').toLowerCase()
			return haystack.includes(query)
		},
		compareCreatedAt(a, b) {
			const av = a.created_at || 0
			const bv = b.created_at || 0
			return this.shareTimeSort === 'asc' ? av - bv : bv - av
		},
		toggleShareTimeSort() {
			this.shareTimeSort = this.shareTimeSort === 'desc' ? 'asc' : 'desc'
		},
		shareStatusLabel(key) {
			const map = {
				permanent: this.t('Permanent share'),
				limited: this.t('Limited-time share'),
				expired: this.t('Expired'),
				disabled: this.t('Disabled'),
			}
			return map[key] || key || '—'
		},
		openFile(row) {
			if (!openUserFile(row)) {
				showTemporary(this.t('Cannot open file'))
			}
		},
		async reload() {
			this.loading = true
			this.loadError = ''
			try {
				const data = await loadStats()
				if (!data?.success || !data.items) {
					this.loadError = data?.error || this.t('Loading failed')
					this.items = []
					return
				}
				this.items = data.items
			} catch (e) {
				this.loadError = this.t('Network error') + ': ' + e.message
				this.items = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
