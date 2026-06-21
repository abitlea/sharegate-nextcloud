<template>
	<div class="sg-purchases-panel">
		<div class="sg-purchases-panel__toolbar">
			<label class="sg-purchases-panel__search">
				<span class="hidden-visually">{{ t('Search my purchases') }}</span>
				<input
					v-model="searchQuery"
					type="search"
					class="sg-purchases-panel__search-input"
					:placeholder="t('Search my purchases')"
					autocomplete="off" />
			</label>
		</div>

		<div v-if="loading" class="loading sg-purchases-panel__loading">
			<div class="spinner" />
			<p class="hint">{{ t('Loading purchases...') }}</p>
		</div>
		<p v-else-if="loadError" class="error sg-purchases-panel__error">{{ loadError }}</p>
		<div v-else-if="needsVerification" class="sg-purchases-empty">
			<p>{{ t('Verify payment account to view purchases') }}</p>
			<p class="hint">{{ t('Enter the full payment account you used at checkout') }}</p>
			<div class="sg-purchases-recover__form">
				<input
					v-model="recoverPayerId"
					type="text"
					class="sg-purchases-recover__input"
					:placeholder="t('Alipay / PayPal / Stripe account used to pay')"
					autocomplete="off" />
				<button
					type="button"
					class="action sg-purchases-recover__btn"
					:disabled="recovering"
					@click="recoverPurchases">
					{{ recovering ? t('Recovering...') : t('Verify and view purchases') }}
				</button>
			</div>
			<p v-if="recoverError" class="error sg-purchases-recover__error">{{ recoverError }}</p>
		</div>
		<div v-else-if="!items.length" class="sg-purchases-empty">
			<p>{{ t('No purchases yet') }}</p>
		</div>
		<div v-else-if="!displayItems.length" class="sg-purchases-empty">
			{{ t('No matching purchases') }}
		</div>
		<div v-else class="sg-purchases-panel__table-wrap">
			<table class="sg-purchases-table sg-table">
				<thead>
					<tr>
						<th v-for="col in columns" :key="col.key">
							{{ col.label }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in displayItems"
						:key="row.share_id + '-' + row.paid_at"
						class="sg-purchases-table__row"
						:class="rowClasses(row)"
						@click="onRowClick(row, $event)">
						<td @click.stop>
							<a
								v-if="row.viewer_url"
								:href="row.viewer_url"
								class="action"
								target="_blank"
								rel="noopener noreferrer">
								{{ displayTitle(row) }}
							</a>
							<span v-else>{{ displayTitle(row) }}</span>
						</td>
						<td>{{ formatSize(row.file_size || 0) }}</td>
						<td>{{ formatPaidAt(row.paid_at) }}</td>
						<td>{{ formatExpiresAt(row.expires_at) }}</td>
						<td>{{ statusLabel(row.status) }}</td>
						<td @click.stop>
							<a
								v-if="row.status === 'active' && row.download_url"
								:href="row.download_url"
								class="action"
								rel="noopener">
								{{ t('Download') }}
							</a>
							<span v-else>—</span>
						</td>
						<td @click.stop>
							<a
								v-if="row.viewer_url"
								:href="row.viewer_url"
								class="action"
								target="_blank"
								rel="noopener noreferrer">
								{{ t('Open') }}
							</a>
							<span v-else>—</span>
						</td>
						<td class="sg-purchases-table__actions" @click.stop>
							<a
								v-if="row.status === 'active' && canSaveToCloud"
								href="#"
								class="action"
								@click.prevent="saveToCloud(row)">
								{{ t('Save to my Nextcloud') }}
							</a>
							<span v-else>—</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { t } from '../utils/l10n.js'
import { showError, showTemporary } from '../utils/notify.js'
import { getPurchasesConfig } from '../utils/config.js'
import { formatSize, formatShareDate } from '../utils/format.js'
import { loadPurchases, verifyPayerAccount, bootstrapPurchasesToken } from '../utils/api.js'
import { getBuyerAccountId, capturePurchasesTokenFromUrl, applyPurchasesSessionFromResponse, requiresPaymentAccountLogin, canBootstrapPurchasesToken, isValidPayerAccountId } from '../utils/buyerAccount.js'

export default {
	name: 'PurchasesPanel',
	data() {
		return {
			loading: true,
			loadError: '',
			items: [],
			searchQuery: '',
			selectedKey: '',
			recoverPayerId: '',
			recovering: false,
			recoverError: '',
			needsVerification: false,
		}
	},
	computed: {
		columns() {
			return [
				{ key: 'name', label: this.t('File name') },
				{ key: 'size', label: this.t('Size') },
				{ key: 'paid', label: this.t('Purchased on') },
				{ key: 'expires', label: this.t('Valid until') },
				{ key: 'status', label: this.t('Status') },
				{ key: 'download', label: this.t('Download again') },
				{ key: 'viewer', label: this.t('Open purchase page') },
				{ key: 'save', label: this.t('Save to my Nextcloud') },
			]
		},
		normalizedSearch() {
			return String(this.searchQuery || '').trim().toLowerCase()
		},
		canSaveToCloud() {
			return !!getPurchasesConfig().ncLoggedIn
		},
		displayItems() {
			if (!this.normalizedSearch) {
				return this.items
			}
			return this.items.filter((row) => {
				const hay = [
					row.title,
					row.file_name,
					row.share_id,
					this.statusLabel(row.status),
				].join(' ').toLowerCase()
				return hay.includes(this.normalizedSearch)
			})
		},
	},
	mounted() {
		capturePurchasesTokenFromUrl()
		this.preparePurchasesAccess().finally(() => this.reload())
	},
	methods: {
		t,
		formatSize,
		displayTitle(row) {
			return row.title || row.file_name || row.share_id
		},
		formatPaidAt(ms) {
			return formatShareDate(ms)
		},
		formatExpiresAt(ms) {
			if (!ms) {
				return this.t('Unknown')
			}
			return formatShareDate(ms)
		},
		statusLabel(status) {
			const map = {
				active: this.t('Active'),
				expired: this.t('Access expired'),
				unavailable: this.t('Unavailable'),
			}
			return map[status] || status
		},
		rowKey(row) {
			return row.share_id + '-' + row.paid_at
		},
		rowClasses(row) {
			return {
				'sg-purchases-table__row--selected': this.selectedKey === this.rowKey(row),
			}
		},
		onRowClick(row, event) {
			if (event.target.closest('a, .action')) {
				return
			}
			this.selectedKey = this.rowKey(row)
		},
		async saveToCloud(row) {
			const url = row.save_to_cloud_url
			if (!url) {
				showError(this.t('Save to cloud failed'))
				return
			}
			const config = getPurchasesConfig()
			const headers = {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			}
			if (config.requestToken) {
				headers.requesttoken = config.requestToken
			}
			try {
				const res = await fetch(url, {
					method: 'POST',
					credentials: 'same-origin',
					headers,
					body: JSON.stringify({
						provider_user_id: row.provider_user_id || getBuyerAccountId(),
					}),
				})
				const data = await res.json()
				if (data?.success) {
					showTemporary(this.t('File saved to your Nextcloud'))
					return
				}
				showError(data?.error || this.t('Save to cloud failed'))
			} catch (e) {
				showError(this.t('Save to cloud failed') + ': ' + e.message)
			}
		},
		async preparePurchasesAccess() {
			// 无痕迹且无 token：不自动签发，reload 后展示支付账号登录表单
			if (requiresPaymentAccountLogin()) {
				return
			}
			// 有痕迹但无 token：用记住的支付账号静默签发
			if (canBootstrapPurchasesToken()) {
				const result = await bootstrapPurchasesToken()
				if (result.bootstrapped && result.data?.purchases_url && globalThis.history?.replaceState) {
					try {
						globalThis.history.replaceState({}, '', result.data.purchases_url)
					} catch {
						// ignore
					}
				}
			}
		},
		async reload() {
			this.loading = true
			this.loadError = ''
			this.recoverError = ''
			this.needsVerification = false
			try {
				const data = await loadPurchases()
				if (data?.code === 'PURCHASES_TOKEN_REQUIRED') {
					this.items = []
					this.needsVerification = true
					return
				}
				if (data?.code === 'PURCHASES_TOKEN_INVALID') {
					this.items = []
					this.needsVerification = true
					this.recoverError = data?.error || this.t('Invalid or expired purchases session')
					return
				}
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
		async recoverPurchases() {
			const payerId = String(this.recoverPayerId || '').trim()
			if (!payerId || !isValidPayerAccountId(payerId)) {
				this.recoverError = this.t('Enter the full payment account you used at checkout')
				return
			}
			this.recovering = true
			this.recoverError = ''
			try {
				const data = await verifyPayerAccount(payerId)
				if (!data?.success) {
					this.recoverError = data?.error || this.t('Recovery failed')
					return
				}
				if (!data.found) {
					this.recoverError = this.t('No purchases found for this payment account')
					return
				}
				applyPurchasesSessionFromResponse(data)
				this.recoverPayerId = ''
				this.needsVerification = false
				if (data.purchases_url && globalThis.history?.replaceState) {
					try {
						globalThis.history.replaceState({}, '', data.purchases_url)
					} catch {
						// ignore
					}
				}
				await this.reload()
			} catch (e) {
				this.recoverError = e.message || this.t('Recovery failed')
			} finally {
				this.recovering = false
			}
		},
	},
}
</script>
