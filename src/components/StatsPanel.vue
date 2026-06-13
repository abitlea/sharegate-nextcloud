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
						{{ t('All types', '全部类型') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="typeFilter = 'document'">
						{{ t('Documents', '文档') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="typeFilter = 'image'">
						{{ t('Images', '图片') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="typeFilter = 'video'">
						{{ t('Videos', '视频') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="typeFilter = 'audio'">
						{{ t('Audio', '音频') }}
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
						<FileNameCell :row="row" />
						<td>{{ shareStatusLabel(row.share_status_label) }}</td>
						<td>{{ formatShareDate(row.created_at) }}</td>
						<td>{{ formatPriceYuan(row.price) }}</td>
						<td>{{ formatPriceYuan(row.revenue || 0) }}</td>
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
import { translate } from '@nextcloud/l10n'
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
import { formatShareDate, formatPriceYuan } from '../utils/format.js'
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
			return this.t('Statistics', '收益查看')
		},
		breadcrumbIcon() {
			return ChartDonut
		},
		typeFilterLabel() {
			const labels = {
				all: this.t('Type', '类型'),
				document: this.t('Documents', '文档'),
				image: this.t('Images', '图片'),
				video: this.t('Videos', '视频'),
				audio: this.t('Audio', '音频'),
				other: this.t('Other', '其他'),
			}
			return labels[this.typeFilter] || labels.all
		},
		shareTimeSortLabel() {
			return this.shareTimeSort === 'asc'
				? this.t('Share time (oldest first)', '分享时间（从旧到新）')
				: this.t('Share time', '分享时间')
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
				return this.t('No statistics match the filter', '没有符合筛选条件的收益记录')
			}
			return this.t('No statistics yet', '暂无收益数据')
		},
		columns() {
			return [
				{ key: 'name', label: this.t('File', '文件') },
				{ key: 'status', label: this.t('Share status', '分享状态') },
				{ key: 'time', label: this.t('Share time', '分享时间') },
				{ key: 'price', label: this.t('Price (yuan)', '定价（元）') },
				{ key: 'revenue', label: this.t('Revenue (yuan)', '收益（元）') },
				{ key: 'preview', label: this.t('Preview count', '预览次数') },
				{ key: 'save', label: this.t('Save to cloud count', '转存次数') },
				{ key: 'download', label: this.t('Download count', '下载次数') },
			]
		},
	},
	mounted() {
		this.reload()
	},
	methods: {
		formatShareDate,
		formatPriceYuan,
		t(key, fallback) {
			const v = translate('sharegate', key)
			return v && v !== key ? v : fallback
		},
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
				permanent: this.t('Permanent share', '永久分享'),
				limited: this.t('Limited-time share', '限期分享'),
				expired: this.t('Expired', '已过期'),
				disabled: this.t('Disabled', '已停用'),
			}
			return map[key] || key || '—'
		},
		async reload() {
			this.loading = true
			this.loadError = ''
			try {
				const data = await loadStats()
				if (!data?.success || !data.items) {
					this.loadError = data?.error || this.t('Loading failed', '加载失败')
					this.items = []
					return
				}
				this.items = data.items
			} catch (e) {
				this.loadError = this.t('Network error', '网络错误') + ': ' + e.message
				this.items = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
