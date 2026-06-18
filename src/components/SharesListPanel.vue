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
						{{ t('All files') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="shareFilter = 'shared'">
						{{ t('Already shared') }}
					</NcActionButton>
					<NcActionButton
						:close-after-click="true"
						@click="shareFilter = 'unshared'">
						{{ t('Not shared yet') }}
					</NcActionButton>
				</NcActions>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" class="sg-dashboard__loading" :size="32" />
		<p v-else-if="loadError" class="warning">{{ loadError }}</p>
		<div v-else-if="!displayItems.length" class="emptycontent">
			{{ emptyMessage }}
		</div>
		<template v-else>
			<div class="files-filestable">
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
								<FileNameCell
									:row="row"
									activate-mode="settings"
									@activate="onFileNameActivate" />
								<td @click.stop>
									<a href="#" class="action" @click.prevent="copyLink(row.share_url)">
										{{ t('Copy link') }}
									</a>
								</td>
								<td>{{ formatShareDate(row.created_at) }}</td>
								<td>{{ formatPriceYuan(row.price) }}</td>
								<td @click.stop>
									<a
										href="#"
										class="action"
										@click.prevent="$emit('open-settings', row.share_id)">
										{{ t('Edit') }}
									</a>
								</td>
								<td class="sg-actions" @click.stop>
									<a
										v-if="row.display_status !== 'disabled'"
										href="#"
										class="action"
										@click.prevent="$emit('disable-share', row.share_id)">
										{{ t('Cancel') }}
									</a>
								</td>
							</template>
							<template v-else>
								<FileNameCell :row="row" @activate="onFileNameActivate" />
								<td>{{ formatSize(row.file_size || 0) }}</td>
								<td>{{ formatRelativeDate(row.file_mtime) }}</td>
								<td class="sg-paid-share-col" @click.stop>
									<a
										v-if="row.has_share && row.share_id"
										href="#"
										class="action"
										@click.prevent="gotoPaid(row.share_id)">
										{{ t('Already shared') }}
									</a>
									<a
										v-else
										href="#"
										class="action sg-action-link"
										:aria-label="t('Add share')"
										:title="t('Add share')"
										@click.prevent="openCreateForFile(row)">
										<PlusCircleOutline :size="16" class="sg-action-link__icon" />
										{{ t('Add share') }}
									</a>
								</td>
							</template>
						</tr>
					</tbody>
				</table>
			</div>
			<div v-if="totalPages > 1" class="sg-pagination">
				<span class="sg-pagination__info">{{ currentPage }}/{{ totalPages }}</span>
				<NcButton v-if="offset > 0" @click="prevPage">
					&laquo; {{ t('Previous') }}
				</NcButton>
				<NcButton v-if="offset + pageSize < total" @click="nextPage">
					{{ t('Next') }} &raquo;
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { t } from '../utils/l10n.js'
import { showTemporary } from '../utils/notify.js'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import CalendarMonth from 'vue-material-design-icons/CalendarMonth.vue'
import AccountCash from 'vue-material-design-icons/AccountCash.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import PlusCircleOutline from 'vue-material-design-icons/PlusCircleOutline.vue'
import FileNameCell from './FileNameCell.vue'
import FilesListBreadcrumbs from './FilesListBreadcrumbs.vue'
import { loadShares } from '../utils/api.js'
import { openUserFile } from '../utils/files.js'
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
		}
	},
	computed: {
		isPaid() {
			return this.filter === 'active'
		},
		breadcrumbTitle() {
			return this.isPaid
				? this.t('Paid shares')
				: this.t('Your shares')
		},
		breadcrumbIcon() {
			return this.isPaid ? AccountCash : LinkVariant
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
		shareFilterLabel() {
			const labels = {
				all: this.t('Share status'),
				shared: this.t('Already shared'),
				unshared: this.t('Not shared yet'),
			}
			return labels[this.shareFilter] || labels.all
		},
		mtimeSortLabel() {
			return this.mtimeSort === 'asc'
				? this.t('Modified (oldest first)')
				: this.t('Modified')
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
				return this.t('No files match the filter')
			}
			return this.isPaid
				? this.t('No paid shares yet')
				: this.t('No shared files yet')
		},
		columns() {
			if (this.isPaid) {
				return [
					{ key: 'name', label: this.t('File name') },
					{ key: 'copy', label: this.t('Copy link') },
					{ key: 'time', label: this.t('Share time') },
					{ key: 'price', label: this.t('Price (CNY)') },
					{ key: 'settings', label: this.t('Paid settings') },
					{ key: 'actions', label: this.t('Cancel share') },
				]
			}
			return [
				{ key: 'name', label: this.t('File name') },
				{ key: 'size', label: this.t('Size') },
				{ key: 'modified', label: this.t('Modified') },
				{ key: 'paid', label: this.t('Paid share') },
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
		try {
			sessionStorage.removeItem('sharegateViewMode')
		} catch {
			// ignore
		}
		this.applyHighlight()
		this.reload()
	},
	methods: {
		formatSize,
		formatRelativeDate,
		formatShareDate,
		formatPriceYuan,
		t,
		matchesTypeFilter(row) {
			const cat = mimeCategory(this.rowMime(row))
			return this.typeFilter === 'other' ? cat === 'other' : cat === this.typeFilter
		},
		rowMime(row) {
			return row.mime_type || guessMimeFromFileName(row.file_name || row.title)
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
		onFileNameActivate(row) {
			this.selectedKey = this.rowKey(row)
			if (this.isPaid) {
				if (row.share_id) {
					this.$emit('open-settings', row.share_id)
				}
				return
			}
			this.openFile(row)
		},
		onRowDblClick(row) {
			this.onFileNameActivate(row)
		},
		onRowEnter(row) {
			this.onFileNameActivate(row)
		},
		openFile(row) {
			if (!openUserFile(row)) {
				showTemporary(this.t('Cannot open file'))
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
					this.loadError = data.error || this.t('Loading failed')
					this.items = []
				}
			} catch (e) {
				this.loadError = this.t('Network error') + ': ' + e.message
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
			showTemporary(this.t('Link copied'))
		},
	},
}
</script>
