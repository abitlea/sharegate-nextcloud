<template>
	<div class="files-list sg-account-panel">
		<div class="files-list__header">
			<div class="files-list__header-spacer" aria-hidden="true" />
			<FilesListBreadcrumbs
				:title="breadcrumbTitle"
				:view-icon="breadcrumbIcon"
				@reload="reload" />
		</div>

		<div class="sg-account-panel__notice">
			<NcNoteCard type="info">
				{{ t('Currently only Alipay Face-to-Face is supported.', '目前只支持（支付宝当面付）') }}
			</NcNoteCard>
		</div>

		<div class="sg-account-panel__body">
			<NcLoadingIcon v-if="loading" class="sg-dashboard__loading" :size="32" />
			<p v-else-if="loadError" class="warning">{{ loadError }}</p>

			<template v-else>
				<p v-if="showHint" class="settings-hint">
					{{ t('Payment account is configured site-wide. Sellers receive payments after binding Alipay Face-to-Face.', '收款账户由站点统一配置；绑定支付宝当面付后即可收款。') }}
				</p>

				<form
					v-if="isAdmin"
					class="sg-account-form"
					@submit.prevent="save">
					<p v-show="showSection('provider')" class="sg-account-form__meta">
						<strong>{{ t('Effective provider', '当前生效') }}:</strong>
						{{ form.effective_provider || '—' }}
					</p>

					<div v-show="showSection('mode')" class="sg-account-form__field">
						<label class="sg-account-form__label" for="sg-payment-mode">
							{{ t('Payment mode', '支付模式') }}
						</label>
						<select
							id="sg-payment-mode"
							v-model="form.payment_mode"
							class="sg-account-select">
							<option value="mock">
								{{ t('Mock (development)', 'Mock（开发测试）') }}
							</option>
							<option value="alipay_f2f">
								{{ t('Alipay Face-to-Face', '支付宝当面付') }}
							</option>
						</select>
					</div>

					<p
						v-if="form.payment_mode === 'mock' && showSection('mode')"
						class="settings-hint">
						{{ t('Mock mode is for development. Select Alipay Face-to-Face below to configure the payment account.', 'Mock 模式用于开发测试。请在上方选择「支付宝当面付」以配置收款账户。') }}
					</p>

					<div
						v-show="form.payment_mode === 'alipay_f2f' && showAlipaySection"
						class="sg-alipay-panel">
						<h3>{{ t('Alipay Face-to-Face', '支付宝当面付') }}</h3>
						<div v-show="showSection('app_id')" class="sg-account-form__field">
							<NcTextField
								label="App ID"
								:show-trailing-button="false"
								:value.sync="form.alipay.app_id"
								autocomplete="off" />
						</div>
						<div v-show="showSection('private_key')" class="sg-account-form__field">
							<NcTextArea
								:label="t('Application private key', '应用私钥')"
								:value.sync="form.alipay.private_key"
								:rows="4"
								autocomplete="off" />
						</div>
						<div v-show="showSection('public_key')" class="sg-account-form__field">
							<NcTextArea
								:label="t('Alipay public key', '支付宝公钥')"
								:value.sync="form.alipay.alipay_public_key"
								:rows="4"
								autocomplete="off" />
						</div>
						<div v-show="showSection('sandbox')" class="sg-account-form__field">
							<label class="sg-account-form__label" for="sg-sandbox">
								{{ t('Sandbox mode', '沙箱模式') }}
							</label>
							<select
								id="sg-sandbox"
								v-model="sandboxChoice"
								class="sg-account-select">
								<option value="true">
									{{ t('Yes (sandbox)', '是（沙箱）') }}
								</option>
								<option value="false">
									{{ t('No (production)', '否（生产）') }}
								</option>
							</select>
						</div>
						<div v-show="showSection('notify_base')" class="sg-account-form__field">
							<NcTextField
								:label="t('Notify URL base (optional)', '通知 URL 根地址（可选）')"
								:placeholder="t('Leave empty to use Nextcloud site URL', '留空则使用 Nextcloud 站点 URL')"
								:show-trailing-button="false"
								:value.sync="form.alipay.notify_url_base" />
						</div>
						<p v-show="showSection('notify')" class="settings-hint">
							{{ t('Async notify URL (register in Alipay console)', '异步通知 URL（请在支付宝控制台登记）') }}:
							<code class="sg-code">{{ form.alipay.notify_url || '—' }}</code>
						</p>
					</div>

					<div v-show="showSection('save')" class="sg-account-form__actions">
						<NcButton type="primary" native-type="submit" :disabled="saving">
							{{ t('Save settings', '保存设置') }}
						</NcButton>
						<span
							v-if="saveStatus"
							class="sg-save-status"
							:class="saveStatusClass">{{ saveStatus }}</span>
					</div>
				</form>

				<div v-else-if="account" class="sg-account-readonly">
					<p v-if="showSection('mode')">
						<strong>{{ t('Payment mode', '支付模式') }}:</strong> {{ modeLabel }}
					</p>
					<p v-if="showSection('provider')">
						<strong>{{ t('Effective provider', '当前生效') }}:</strong> {{ account.effective_provider || '—' }}
					</p>
					<p v-if="showSection('alipay')">
						<strong>{{ t('Alipay Face-to-Face', '支付宝当面付') }}:</strong> {{ boundLabel }}
					</p>
					<p v-if="account.alipay_sandbox && showSection('sandbox')" class="settings-hint">
						{{ t('Sandbox mode', '沙箱模式') }}
					</p>
					<p v-if="account.notify_url && showSection('notify')">
						<strong>{{ t('Async notify URL (register in Alipay console)', '异步通知 URL') }}:</strong><br>
						<code class="sg-code">{{ account.notify_url }}</code>
					</p>
					<p v-if="showSection('contact')" class="settings-hint">
						{{ t('Contact your Nextcloud admin to configure payment.', '请联系 Nextcloud 管理员配置收款账户。') }}
					</p>
				</div>

				<div v-if="hasActiveSearch && !hasVisibleContent" class="emptycontent">
					{{ t('No settings match the search', '没有符合搜索条件的设置项') }}
				</div>
			</template>
		</div>
	</div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import Cog from 'vue-material-design-icons/Cog.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FilesListBreadcrumbs from './FilesListBreadcrumbs.vue'
import { loadAccountSettings, loadPaymentConfig, savePaymentConfig } from '../utils/api.js'
import { getDashboardConfig } from '../utils/config.js'
import { showTemporary } from '../utils/notify.js'

const SEARCH_SECTIONS = {
	provider: ['effective', 'provider', '生效', 'mock', 'alipay'],
	mode: ['payment', '支付', 'mock', 'mode', '模式', 'alipay'],
	app_id: ['app', 'id'],
	private_key: ['private', 'key', '私钥'],
	public_key: ['public', 'key', '公钥', 'alipay'],
	sandbox: ['sandbox', '沙箱'],
	notify_base: ['notify', 'base', 'url', '通知', '根'],
	notify: ['notify', 'url', '通知', '异步'],
	save: ['save', '保存', 'settings', '设置'],
	alipay: ['alipay', '支付宝', 'bound', '绑定', 'face'],
	contact: ['contact', 'admin', '联系', '管理员'],
}

function emptyAlipayForm() {
	return {
		app_id: '',
		private_key: '',
		alipay_public_key: '',
		sandbox: true,
		notify_url_base: '',
		notify_url: '',
	}
}

export default {
	name: 'AccountPanel',
	components: {
		FilesListBreadcrumbs,
		Cog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},
	props: {
		searchQuery: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			loading: true,
			loadError: '',
			account: null,
			form: {
				payment_mode: 'mock',
				effective_provider: '',
				alipay: emptyAlipayForm(),
			},
			saving: false,
			saveStatus: '',
			saveOk: false,
		}
	},
	computed: {
		isAdmin() {
			return this.resolveIsAdmin(this.account)
		},
		breadcrumbTitle() {
			return this.t('Account binding', '账号绑定')
		},
		breadcrumbIcon() {
			return Cog
		},
		hasActiveSearch() {
			return !!this.normalizedSearch
		},
		normalizedSearch() {
			return String(this.searchQuery || '').trim().toLowerCase()
		},
		showHint() {
			if (!this.hasActiveSearch) {
				return true
			}
			return this.matchesSearch([
				'收款',
				'账户',
				'支付宝',
				'payment',
				'account',
				'alipay',
				'configured',
				'绑定',
			])
		},
		sandboxChoice: {
			get() {
				return this.form.alipay.sandbox ? 'true' : 'false'
			},
			set(value) {
				this.form.alipay.sandbox = value === 'true'
			},
		},
		modeLabel() {
			if (!this.account) {
				return '—'
			}
			return this.account.payment_mode === 'alipay_f2f'
				? this.t('Alipay Face-to-Face', '支付宝当面付')
				: this.t('Mock (development)', 'Mock（开发测试）')
		},
		boundLabel() {
			if (!this.account) {
				return '—'
			}
			return this.account.alipay_configured
				? this.t('Bound', '已绑定')
				: this.t('Not bound', '未绑定')
		},
		showAlipaySection() {
			if (!this.hasActiveSearch) {
				return true
			}
			return ['app_id', 'private_key', 'public_key', 'sandbox', 'notify_base', 'notify']
				.some((key) => this.showSection(key))
		},
		saveStatusClass() {
			return this.saveOk ? 'ok' : 'err'
		},
		hasVisibleContent() {
			if (!this.hasActiveSearch) {
				return true
			}
			if (this.showHint) {
				return true
			}
			if (this.isAdmin) {
				const keys = Object.keys(SEARCH_SECTIONS)
				if (this.form.payment_mode === 'alipay_f2f') {
					return keys.some((key) => this.showSection(key))
				}
				return ['provider', 'mode', 'save'].some((key) => this.showSection(key))
			}
			if (!this.account) {
				return false
			}
			return ['mode', 'provider', 'alipay', 'sandbox', 'notify', 'contact']
				.some((key) => this.showSection(key))
		},
	},
	mounted() {
		this.loadAccount()
	},
	methods: {
		t(key, fallback) {
			const v = translate('sharegate', key)
			return v && v !== key ? v : fallback
		},
		matchesSearch(terms) {
			if (!this.hasActiveSearch) {
				return true
			}
			const haystack = terms.join(' ').toLowerCase()
			return haystack.includes(this.normalizedSearch)
				|| terms.some((term) => term.toLowerCase().includes(this.normalizedSearch))
		},
		showSection(key) {
			if (!this.hasActiveSearch) {
				return true
			}
			const terms = SEARCH_SECTIONS[key] || []
			const values = {
				provider: this.isAdmin ? this.form.effective_provider : this.account?.effective_provider,
				mode: this.modeLabel,
				app_id: this.form.alipay?.app_id,
				private_key: this.t('Application private key', '应用私钥'),
				public_key: this.t('Alipay public key', '支付宝公钥'),
				sandbox: this.t('Sandbox mode', '沙箱模式'),
				notify_base: this.form.alipay?.notify_url_base,
				notify: this.form.alipay?.notify_url || this.account?.notify_url,
				save: this.t('Save settings', '保存设置'),
				alipay: this.boundLabel,
				contact: this.t('Contact your Nextcloud admin to configure payment.', '请联系 Nextcloud 管理员配置收款账户。'),
			}
			const text = String(values[key] || '').toLowerCase()
			return terms.some((term) => term.includes(this.normalizedSearch))
				|| text.includes(this.normalizedSearch)
				|| this.normalizedSearch.split(/\s+/).every((part) => text.includes(part) || terms.some((term) => term.includes(part)))
		},
		applyAdminSummary(summary) {
			if (!summary) {
				return
			}
			const alipay = summary.alipay_f2f || {}
			this.form = {
				payment_mode: summary.payment_mode || 'mock',
				effective_provider: summary.effective_provider || '',
				alipay: {
					app_id: alipay.app_id || '',
					private_key: alipay.private_key || '',
					alipay_public_key: alipay.alipay_public_key || '',
					sandbox: alipay.sandbox !== false,
					notify_url_base: alipay.notify_url_base || '',
					notify_url: alipay.notify_url || '',
				},
			}
		},
		reload() {
			this.loadAccount()
		},
		resolveIsAdmin(account) {
			const config = getDashboardConfig()
			if (config.isAdmin === true || config.isAdmin === 1) {
				return true
			}
			return !!(account?.is_admin === true || account?.is_admin === 1)
		},
		async loadAccount() {
			this.loading = true
			this.loadError = ''
			try {
				const data = await loadAccountSettings()
				if (!data) {
					this.loadError = this.t('Dashboard config missing', '页面配置缺失，请刷新后重试')
					return
				}
				if (data.account) {
					this.account = data.account
				}
				const isAdmin = this.resolveIsAdmin(data.account)
				if (data.success === false) {
					if (isAdmin) {
						await this.loadAdminPaymentConfig()
						if (this.form.effective_provider) {
							return
						}
					}
					this.loadError = data.error
						|| this.t('Failed to load account settings', '加载账户设置失败')
					return
				}
				if (data.payment_config) {
					this.applyAdminSummary(data.payment_config)
				} else if (isAdmin) {
					await this.loadAdminPaymentConfig()
				}
				if (!this.account && !isAdmin) {
					this.loadError = data.error
						|| this.t('Failed to load account settings', '加载账户设置失败')
				}
			} catch (e) {
				this.loadError = e?.message || this.t('Failed to load account settings', '加载账户设置失败')
			} finally {
				this.loading = false
			}
		},
		async loadAdminPaymentConfig() {
			try {
				const summary = await loadPaymentConfig()
				this.applyAdminSummary(summary)
			} catch (e) {
				if (!this.account) {
					throw e
				}
				this.loadError = e?.message
					|| this.t('Failed to load payment settings', '加载支付配置失败')
			}
		},
		async save() {
			this.saving = true
			this.saveStatus = ''
			this.saveOk = false
			try {
				const data = await savePaymentConfig({
					payment_mode: this.form.payment_mode,
					alipay_f2f: {
						app_id: this.form.alipay.app_id,
						private_key: this.form.alipay.private_key,
						alipay_public_key: this.form.alipay.alipay_public_key,
						sandbox: this.form.alipay.sandbox,
						notify_url_base: this.form.alipay.notify_url_base,
					},
				})
				if (data?.success) {
					this.saveOk = true
					this.saveStatus = data.message || this.t('Settings saved', '配置已保存')
					if (data.summary) {
						this.applyAdminSummary(data.summary)
					}
					showTemporary(this.saveStatus)
					window.dispatchEvent(new Event('sharegate:payment-saved'))
				} else {
					this.saveStatus = data?.error || data?.message || this.t('Save failed', '保存失败')
				}
			} catch (e) {
				this.saveStatus = e?.message || this.t('Save failed', '保存失败')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
