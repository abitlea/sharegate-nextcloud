<template>
	<div class="files-list sg-shares-panel">
		<div class="files-list__header">
			<div class="files-list__header-spacer" aria-hidden="true"></div>
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
					:pressed="mtimeSort === 'asc'"
					@click="toggleMtimeSort">
					<template #icon>
						<CalendarMonth :size="20" />
					</template>
					{{ mtimeSortLabel }}
				</NcButton>
				<NcActions
					v-if="!isPaid"
					type="tertiary"
					:menu-name="shareFilterLabel"
					:aria-label="shareFilterLabel"
					force-menu>
					<template #icon>
						<AccountCash :size="20" />
					</template>
					<NcActionButton
						:close-after-click="true"
						@click="shareFilter = 'all'">
						{{ t('All files', '全部文件') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="shareFilter = 'shared'">
						{{ t('Already shared', '已分享') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="shareFilter = 'unshared'">
						{{ t('Not shared yet', '未分享') }}
					</NcActionButton>
				</NcActions>
			</div>
			<div class="files-list__header-grid-button">
				<NcButton
					type="tertiary"
					:aria-label="viewToggleLabel"
					:title="viewToggleLabel"
					@click="toggleViewMode">
					<template #icon>
						<ViewGrid v-if="viewMode === 'list'" :size="20" />
						<ViewList v-else :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" class="sg-dashboard__loading" :size="32" />
		<p v-else-if="loadError" class="warning">{{ loadError }}</p>
		<div v-else-if="!displayItems.length" class="emptycontent">
			{{ emptyMessage }}
		</div>
		<template v-else>
			<div v-if="viewMode === 'grid'" class="files-list__grid sg-files-grid">
				<button
					v-for="row in displayItems"
					:key="rowKey(row)"
					type="button"
					class="files-list__grid-item sg-grid-item"
					:class="rowClasses(row)"
					@click="onGridClick(row, $event)"
					@dblclick="onRowDblClick(row)">
					<img
						class="sg-grid-item__icon files-list__row-icon-preview sg-file-icon"
						:src="fileIconUrl(row)"
						alt=""
						aria-hidden="true"
						loading="lazy" />
					<span class="sg-grid-item__name" :title="gridItemName(row)">
						{{ gridItemName(row) }}
					</span>
					<span v-if="isPaid" class="sg-grid-item__meta">
						{{ formatPriceYuan(row.price) }}
					</span>
					<span v-else class="sg-grid-item__meta">
						{{ formatSize(row.file_size || 0) }}
					</span>
				</button>
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
							:key="rowKey(row)"
							tabindex="0"
							class="files-list__row"
							:class="rowClasses(row)"
							:data-share-id="row.share_id || undefined"
							@click="onRowClick(row, $event)"
							@dblclick="onRowDblClick(row)"
							@keydown.enter.prevent="onRowEnter(row)">
							<template v-if="isPaid">
								<FileNameCell :row="row" />
								<td @click.stop>
									<a href="#" class="action" @click.prevent="copyLink(row.share_url)">
										{{ t('Copy link', '复制链接') }}
									</a>
								</td>
								<td>{{ formatShareDate(row.created_at) }}</td>
								<td>{{ formatPriceYuan(row.price) }}</td>
								<td @click.stop>
									<NcButton type="tertiary" @click="$emit('open-settings', row.share_id)">
										{{ t('Edit', '编辑') }}
									</NcButton>
								</td>
								<td class="sg-actions" @click.stop>
									<NcButton
										v-if="row.display_status !== 'disabled'"
										type="tertiary"
										@click="$emit('disable-share', row.share_id)">
										{{ t('Cancel', '取消') }}
									</NcButton>
								</td>
							</template>
							<template v-else>
								<FileNameCell :row="row" />
								<td>{{ formatSize(row.file_size || 0) }}</td>
								<td>{{ formatRelativeDate(row.file_mtime) }}</td>
								<td class="sg-paid-share-col" @click.stop>
									<NcButton
										v-if="row.has_share && row.share_id"
										type="tertiary"
										@click="gotoPaid(row.share_id)">
										{{ t('Already shared', '已分享') }}
									</NcButton>
									<NcButton
										v-else
										type="tertiary"
										:aria-label="t('Add share', '添加分享')"
										:title="t('Add share', '添加分享')"
										@click="openCreateForFile(row)">
										<template #icon>
											<PlusCircleOutline :size="20" />
										</template>
										{{ t('Add share', '添加分享') }}
									</NcButton>
								</td>
							</template>
						</tr>
					</tbody>
				</table>
			</div>
			<div v-if="totalPages > 1" class="sg-pagination">
				<span class="sg-pagination__info">{{ currentPage }}/{{ totalPages }}</span>
				<NcButton v-if="offset > 0" @click="prevPage">
					&laquo; {{ t('Previous', '上一页') }}
				</NcButton>
				<NcButton v-if="offset + pageSize < total" @click="nextPage">
					{{ t('Next', '下一页') }} &raquo;
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import { showTemporary } from '../utils/notify.js'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import CalendarMonth from 'vue-material-design-icons/CalendarMonth.vue'
import AccountCash from 'vue-material-design-icons/AccountCash.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import ViewGrid from 'vue-material-design-icons/ViewGrid.vue'
import ViewList from 'vue-material-design-icons/ViewList.vue'
import PlusCircleOutline from 'vue-material-design-icons/PlusCircleOutline.vue'
import FileNameCell from './FileNameCell.vue'
import FilesListBreadcrumbs from './FilesListBreadcrumbs.vue'
import { loadShares } from '../utils/api.js'
import { fileIconUrlFromRow, openUserFile } from '../utils/files.js'
import { guessMimeFromFileName, mimeCategory } from '../utils/mime.js'
import { formatSize, formatRelativeDate, formatShareDate, formatPriceYuan, buildPublicUrl } from '../utils/format.js'
import { setHash } from '../utils/hashRouter.js'

const PAGE_SIZE = 50

export default {
	name: 'SharesListPanel',
	components: {
		FileNameCell,
		NcActionButton,
		NcActions,
		NcButton,
		NcLoadingIcon,
		FilesListBreadcrumbs,
		FileDocumentOutline,
		CalendarMonth,
		AccountCash,
		LinkVariant,
		ViewGrid,
		ViewList,
		PlusCircleOutline,
	},
	props: {
		filter: { type: String, default: 'all' },
		searchQuery: { type: String, default: '' },
	},
	emits: ['open-settings', 'disable-share', 'open-create', 'counts-changed'],
	data() {
		return {
			loading: false,
			loadError: '',
			items: [],
			total: 0,
			offset: 0,
			pageSize: PAGE_SIZE,
			highlightId: '',
			selectedKey: '',
			searchTimer: null,
			typeFilter: 'all',
			shareFilter: 'all',
			mtimeSort: 'desc',
			viewMode: 'list',
		}
	},
	computed: {
		isPaid() {
			return this.filter === 'active'
		},
		breadcrumbTitle() {
			return this.isPaid
				? this.t('Paid shares', '付费分享')
				: this.t('Your shares', '你的共享')
		},
		breadcrumbIcon() {
			return this.isPaid ? AccountCash : LinkVariant
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
		shareFilterLabel() {
			const labels = {
				all: this.t('Share status', '分享状态'),
				shared: this.t('Already shared', '已分享'),
				unshared: this.t('Not shared yet', '未分享'),
			}
			return labels[this.shareFilter] || labels.all
		},
		mtimeSortLabel() {
			return this.mtimeSort === 'asc'
				? this.t('Modified (oldest first)', '修改日期（从旧到新）')
				: this.t('Modified', '修改日期')
		},
		viewToggleLabel() {
			return this.viewMode === 'list'
				? this.t('Switch to grid view', '切换为网格视图')
				: this.t('Switch to list view', '切换为列表视图')
		},
		displayItems() {
			let list = [...this.items]
			if (this.typeFilter !== 'all') {
				list = list.filter((row) => this.matchesTypeFilter(row))
			}
			if (!this.isPaid) {
				if (this.shareFilter === 'shared') {
					list = list.filter((row) => row.has_share)
				} else if (this.shareFilter === 'unshared') {
					list = list.filter((row) => !row.has_share)
				}
				list.sort((a, b) => this.compareMtime(a, b))
			} else {
				list.sort((a, b) => this.compareCreatedAt(a, b))
			}
			return list
		},
		currentPage() {
			return Math.floor(this.offset / this.pageSize) + 1
		},
		totalPages() {
			return Math.max(1, Math.ceil(this.total / this.pageSize))
		},
		emptyMessage() {
			if (this.items.length && !this.displayItems.length) {
				return this.t('No files match the filter', '没有符合筛选条件的文件')
			}
			return this.isPaid
				? this.t('No paid shares yet', '暂无付费分享，请从「你的共享」添加')
				: this.t('No shared files yet', '暂无已生成公开链接的文件，请先在「文件」中创建公开链接')
		},
		columns() {
			if (this.isPaid) {
				return [
					{ key: 'name', label: this.t('File name', '名称') },
					{ key: 'copy', label: this.t('Copy link', '复制链接') },
					{ key: 'time', label: this.t('Share time', '分享时间') },
					{ key: 'price', label: this.t('Price (CNY)', '定价') },
					{ key: 'settings', label: this.t('Paid settings', '付费设置') },
					{ key: 'actions', label: this.t('Cancel share', '取消分享') },
				]
			}
			return [
				{ key: 'name', label: this.t('File name', '名称') },
				{ key: 'size', label: this.t('Size', '大小') },
				{ key: 'modified', label: this.t('Modified', '修改日期') },
				{ key: 'paid', label: this.t('Paid share', '付费分享') },
			]
		},
	},
	watch: {
		filter() {
			this.offset = 0
			this.selectedKey = ''
			this.typeFilter = 'all'
			this.shareFilter = 'all'
			this.mtimeSort = 'desc'
			this.reload()
		},
		searchQuery() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => {
				this.offset = 0
				this.reload()
			}, 300)
		},
	},
	mounted() {
		this.restoreViewMode()
		this.applyHighlight()
		this.reload()
	},
	methods: {
		formatSize,
		formatRelativeDate,
		formatShareDate,
		formatPriceYuan,
		t(key, fallback) {
			const v = translate('sharegate', key)
			return v && v !== key ? v : fallback
		},
		matchesTypeFilter(row) {
			const cat = mimeCategory(this.rowMime(row))
			return this.typeFilter === 'other' ? cat === 'other' : cat === this.typeFilter
		},
		gridItemName(row) {
			return row.file_name || row.title || '—'
		},
		rowMime(row) {
			return row.mime_type || guessMimeFromFileName(row.file_name || row.title)
		},
		onGridClick(row, event) {
			this.onRowClick(row, event)
		},
		toggleViewMode() {
			this.viewMode = this.viewMode === 'list' ? 'grid' : 'list'
			try {
				sessionStorage.setItem('sharegateViewMode', this.viewMode)
			} catch {
				// ignore
			}
		},
		restoreViewMode() {
			try {
				const saved = sessionStorage.getItem('sharegateViewMode')
				if (saved === 'grid' || saved === 'list') {
					this.viewMode = saved
				}
			} catch {
				// ignore
			}
		},
		fileIconUrl(row) {
			return fileIconUrlFromRow(row, 64)
		},
		rowClasses(row) {
			const key = this.rowKey(row)
			return {
				selected: this.selectedKey === key,
				'sg-row--highlight': this.highlightId && this.highlightId === row.share_id,
			}
		},
		onRowClick(row, event) {
			if (event.target.closest('button, a, .action')) {
				return
			}
			this.selectedKey = this.rowKey(row)
		},
		onRowDblClick(row) {
			if (this.isPaid) {
				if (row.share_id) {
					this.$emit('open-settings', row.share_id)
				}
				return
			}
			this.openFile(row)
		},
		onRowEnter(row) {
			if (this.isPaid) {
				if (row.share_id) {
					this.$emit('open-settings', row.share_id)
				}
				return
			}
			this.openFile(row)
		},
		openFile(row) {
			if (!openUserFile(row)) {
				showTemporary(this.t('Cannot open file', '无法打开该文件'))
			}
		},
		compareMtime(a, b) {
			const av = a.file_mtime || 0
			const bv = b.file_mtime || 0
			return this.mtimeSort === 'asc' ? av - bv : bv - av
		},
		compareCreatedAt(a, b) {
			const av = a.created_at || 0
			const bv = b.created_at || 0
			return this.mtimeSort === 'asc' ? av - bv : bv - av
		},
		toggleMtimeSort() {
			this.mtimeSort = this.mtimeSort === 'desc' ? 'asc' : 'desc'
		},
		rowKey(row) {
			return row.share_id || row.file_path || row.file_name
		},
		applyHighlight() {
			try {
				const sid = sessionStorage.getItem('sharegateHighlightShare') || ''
				if (sid) {
					sessionStorage.removeItem('sharegateHighlightShare')
					this.highlightId = sid
					this.selectedKey = sid
				}
			} catch {
				// ignore
			}
		},
		async reload() {
			this.loading = true
			this.loadError = ''
			try {
				const data = await loadShares({
					filter: this.filter,
					query: this.searchQuery,
					offset: this.offset,
					limit: this.pageSize,
				})
				if (data.success) {
					this.items = data.items || []
					this.total = data.total || 0
				} else {
					this.loadError = data.error || this.t('Loading failed', '加载失败')
					this.items = []
				}
			} catch (e) {
				this.loadError = this.t('Network error', '网络错误') + ': ' + e.message
				this.items = []
			} finally {
				this.loading = false
			}
		},
		prevPage() {
			this.offset = Math.max(0, this.offset - this.pageSize)
			this.selectedKey = ''
			this.reload()
		},
		nextPage() {
			this.offset += this.pageSize
			this.selectedKey = ''
			this.reload()
		},
		openCreateForFile(row) {
			if (!row.file_path || !row.file_name) {
				return
			}
			this.selectedKey = this.rowKey(row)
			this.$emit('open-create', {
				file_path: row.file_path,
				file_name: row.file_name,
				file_size: row.file_size || 0,
				file_id: row.file_id || 0,
				mime_type: row.mime_type || this.rowMime(row),
			})
		},
		gotoPaid(shareId) {
			try {
				sessionStorage.setItem('sharegateHighlightShare', shareId)
			} catch {
				// ignore
			}
			setHash('paid')
		},
		copyLink(url) {
			const fullUrl = buildPublicUrl(url)
			if (!fullUrl) {
				return
			}
			navigator.clipboard.writeText(fullUrl).catch(() => {})
			showTemporary(this.t('Link copied', '已复制链接'))
		},
	},
}
</script>
