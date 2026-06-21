<template>
	<div class="files-list sg-ledger-panel">
		<div class="files-list__header">
			<div class="files-list__header-spacer" aria-hidden="true" />
			<FilesListBreadcrumbs
				:title="breadcrumbTitle"
				:view-icon="breadcrumbIcon"
				@reload="reload" />
			<div class="files-list__toolbar" data-test-id="ledger-filters">
				<NcActions
					type="tertiary"
					:menu-name="statusFilterLabel"
					:aria-label="statusFilterLabel"
					force-menu>
					<template #icon>
						<FilterVariant :size="20" />
					</template>
					<NcActionButton
						v-for="option in statusOptions"
						:key="option.value"
						:close-after-click="true"
						@click="setStatusFilter(option.value)">
						{{ option.label }}
					</NcActionButton>
				</NcActions>
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
						:key="row.order_id"
						class="files-list__row">
						<td class="sg-ledger-panel__mono">{{ row.order_id }}</td>
						<td>{{ row.share_title || row.file_name || row.share_id }}</td>
						<td>{{ row.amount_display }}</td>
						<td>{{ row.provider_label }}</td>
						<td class="sg-ledger-panel__payer">
							<div>{{ row.payer_account || '—' }}</div>
							<div
								v-if="row.payer_logon_masked"
								class="sg-ledger-panel__payer-note">
								{{ tf('Alipay login (masked by Alipay): %s', row.payer_logon_masked) }}
							</div>
						</td>
						<td>
							<span :class="statusClass(row.status)">{{ row.status_label }}</span>
						</td>
						<td>{{ formatDate(row.created_at) }}</td>
						<td>{{ formatDate(row.paid_at) }}</td>
						<td>{{ formatDate(row.refunded_at) }}</td>
						<td class="sg-ledger-panel__reason">{{ row.failure_reason || '—' }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { t, tf } from '../utils/l10n.js'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import ReceiptText from 'vue-material-design-icons/ReceiptText.vue'
import FilesListBreadcrumbs from './FilesListBreadcrumbs.vue'
import { loadPaymentLedger } from '../utils/api.js'
import { formatShareDate } from '../utils/format.js'
import { priceColumnLabel } from '../utils/currency.js'

export default {
	name: 'PaymentLedgerPanel',
	components: {
		FilesListBreadcrumbs,
		NcActions,
		NcActionButton,
		NcLoadingIcon,
		FilterVariant,
	},
	props: {
		searchQuery: {
			type: String,
			default: '',
		},
		displayCurrency: { type: String, default: 'CNY' },
	},
	data() {
		return {
			loading: true,
			loadError: '',
			items: [],
			statusFilter: 'all',
		}
	},
	computed: {
		breadcrumbTitle() {
			return this.t('Payment ledger')
		},
		breadcrumbIcon() {
			return ReceiptText
		},
		statusOptions() {
			return [
				{ value: 'all', label: this.t('All statuses') },
				{ value: 'paid', label: this.t('Paid') },
				{ value: 'pending', label: this.t('Pending') },
				{ value: 'failed', label: this.t('Failed') },
				{ value: 'cancelled', label: this.t('Cancelled') },
				{ value: 'refunded', label: this.t('Refunded') },
			]
		},
		statusFilterLabel() {
			const match = this.statusOptions.find((o) => o.value === this.statusFilter)
			return match ? match.label : this.t('All statuses')
		},
		columns() {
			return [
				{ key: 'order', label: this.t('Order ID') },
				{ key: 'share', label: this.t('Title') },
				{ key: 'amount', label: priceColumnLabel(this.displayCurrency) },
				{ key: 'provider', label: this.t('Payment method') },
				{ key: 'payer', label: this.t('Payer account') },
				{ key: 'status', label: this.t('Status') },
				{ key: 'created', label: this.t('Created on') },
				{ key: 'paid', label: this.t('Paid on') },
				{ key: 'refunded', label: this.t('Refunded on') },
				{ key: 'reason', label: this.t('Failure reason') },
			]
		},
		normalizedSearch() {
			return String(this.searchQuery || '').trim()
		},
		displayItems() {
			return this.items
		},
		emptyMessage() {
			if (this.statusFilter !== 'all' || this.normalizedSearch) {
				return this.t('No matching payment records')
			}
			return this.t('No payment records yet')
		},
	},
	watch: {
		statusFilter() {
			this.reload()
		},
		normalizedSearch() {
			this.reload()
		},
	},
	mounted() {
		this.reload()
	},
	methods: {
		t,
		tf,
		setStatusFilter(value) {
			this.statusFilter = value
		},
		formatDate(ms) {
			if (!ms) {
				return '—'
			}
			return formatShareDate(ms)
		},
		statusClass(status) {
			return 'sg-ledger-panel__status sg-ledger-panel__status--' + status
		},
		async reload() {
			this.loading = true
			this.loadError = ''
			try {
				const data = await loadPaymentLedger({
					status: this.statusFilter,
					query: this.normalizedSearch,
				})
				if (data?.success) {
					this.items = data.items || []
				} else {
					this.loadError = data?.error || this.t('Loading failed')
				}
			} catch (e) {
				this.loadError = e.message || this.t('Loading failed')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.sg-ledger-panel__mono {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.9em;
	word-break: break-all;
}
.sg-ledger-panel__reason {
	max-width: 220px;
	word-break: break-word;
}
.sg-ledger-panel__payer-note {
	margin-top: 0.2em;
	font-size: 0.82em;
	color: var(--color-text-lighter, #666);
	word-break: break-all;
}
.sg-ledger-panel__status--paid {
	color: var(--color-success, #2d7b46);
}
.sg-ledger-panel__status--pending {
	color: var(--color-warning, #b08900);
}
.sg-ledger-panel__status--failed,
.sg-ledger-panel__status--cancelled {
	color: var(--color-error, #c44);
}
.sg-ledger-panel__status--refunded {
	color: var(--color-text-lighter, #666);
}
</style>
