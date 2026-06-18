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
				{{ t('Choose Stripe or PayPal for international payments, or Alipay Face-to-Face for China. Use sandbox mode while testing Alipay or PayPal.') }}
			</NcNoteCard>
			<ul v-if="visibleProviders.length" class="sg-account-providers">
				<li v-for="provider in visibleProviders" :key="provider.id">
					<strong>{{ provider.label }}</strong>
					<span class="settings-hint"> — {{ provider.description }}</span>
				</li>
			</ul>
		</div>

		<div class="sg-account-panel__body">
			<NcLoadingIcon v-if="loading" class="sg-dashboard__loading" :size="32" />
			<p v-else-if="loadError" class="warning">{{ loadError }}</p>

			<template v-else>
				<p v-if="showHint" class="settings-hint">
					{{ t('Payment account is configured site-wide. Bind Stripe, PayPal, or Alipay to receive payments.') }}
				</p>

				<form
					v-if="isAdmin"
					class="sg-account-form"
					@submit.prevent="onSubmit">
					<div v-show="showSection('mode')" class="sg-account-form__field">
						<label class="sg-account-form__label" for="sg-payment-mode">
							{{ t('Payment mode') }}
						</label>
						<select
							id="sg-payment-mode"
							v-model="form.payment_mode"
							class="sg-account-select">
							<option
								v-for="provider in selectableProviders"
								:key="provider.id"
								:value="provider.id">
								{{ provider.label }}
							</option>
						</select>
					</div>

					<p
						v-if="form.payment_mode === 'mock' && mockSelectable && showSection('mode')"
						class="settings-hint">
						{{ t('Mock mode is for development. Select Stripe, PayPal, or Alipay Face-to-Face to configure a production payment account.') }}
					</p>

					<div
						v-show="form.payment_mode === 'stripe' && showStripeSection"
						class="sg-stripe-panel">
						<h3>{{ t('Stripe') }}</h3>
						<div v-show="showSection('stripe_secret')" class="sg-account-form__field">
							<NcTextField
								ref="stripeSecretKey"
								:label="t('Stripe secret key')"
								:value.sync="form.stripe.secret_key"
								:show-trailing-button="false"
								autocomplete="off" />
						</div>
						<div v-show="showSection('stripe_webhook')" class="sg-account-form__field">
							<NcTextField
								ref="stripeWebhookSecret"
								:label="t('Stripe webhook secret')"
								:value.sync="form.stripe.webhook_secret"
								:show-trailing-button="false"
								autocomplete="off" />
						</div>
						<div v-show="showSection('stripe_currency')" class="sg-account-form__field">
							<label class="sg-account-form__label" for="sg-stripe-currency">
								{{ t('Checkout currency') }}
							</label>
							<select
								id="sg-stripe-currency"
								v-model="form.stripe.currency"
								class="sg-account-select">
								<option
									v-for="code in internationalCurrencies"
									:key="code"
									:value="code">
									{{ code.toUpperCase() }}
								</option>
							</select>
						</div>
						<p v-show="showSection('stripe_webhook_url')" class="settings-hint">
							{{ t('Stripe webhook URL (register in Stripe Dashboard)') }}:
							<code class="sg-code">{{ form.stripe.webhook_url || '—' }}</code>
						</p>
					</div>

					<div
						v-show="form.payment_mode === 'paypal' && showPaypalSection"
						class="sg-paypal-panel">
						<h3>{{ t('PayPal') }}</h3>
						<div v-show="showSection('paypal_client_id')" class="sg-account-form__field">
							<NcTextField
								ref="paypalClientId"
								:label="t('PayPal Client ID')"
								:value.sync="form.paypal.client_id"
								:show-trailing-button="false"
								autocomplete="off" />
						</div>
						<div v-show="showSection('paypal_client_secret')" class="sg-account-form__field">
							<NcTextField
								ref="paypalClientSecret"
								:label="t('PayPal Client Secret')"
								:value.sync="form.paypal.client_secret"
								:show-trailing-button="false"
								autocomplete="off" />
						</div>
						<div v-show="showSection('paypal_webhook_id')" class="sg-account-form__field">
							<NcTextField
								:label="t('PayPal Webhook ID (optional)')"
								:value.sync="form.paypal.webhook_id"
								:show-trailing-button="false"
								autocomplete="off" />
						</div>
						<div v-show="showSection('paypal_sandbox')" class="sg-account-form__field">
							<label class="sg-account-form__label" for="sg-paypal-sandbox">
								{{ t('Sandbox mode') }}
							</label>
							<select
								id="sg-paypal-sandbox"
								v-model="paypalSandboxChoice"
								class="sg-account-select">
								<option value="true">
									{{ t('Yes (sandbox)') }}
								</option>
								<option value="false">
									{{ t('No (production)') }}
								</option>
							</select>
						</div>
						<div v-show="showSection('paypal_currency')" class="sg-account-form__field">
							<label class="sg-account-form__label" for="sg-paypal-currency">
								{{ t('Checkout currency') }}
							</label>
							<select
								id="sg-paypal-currency"
								v-model="form.paypal.currency"
								class="sg-account-select">
								<option
									v-for="code in internationalCurrencies"
									:key="code"
									:value="code">
									{{ code.toUpperCase() }}
								</option>
							</select>
						</div>
						<p v-show="showSection('paypal_webhook_url')" class="settings-hint">
							{{ t('PayPal webhook URL (register in PayPal Developer Dashboard)') }}:
							<code class="sg-code">{{ form.paypal.webhook_url || '—' }}</code>
						</p>
					</div>

					<div
						v-show="form.payment_mode === 'alipay_f2f' && showAlipaySection"
						class="sg-alipay-panel">
						<h3>{{ t('Alipay Face-to-Face') }}</h3>
						<div v-show="showSection('app_id')" class="sg-account-form__field">
							<NcTextField
								ref="alipayAppId"
								label="App ID"
								:show-trailing-button="false"
								:value.sync="form.alipay.app_id"
								autocomplete="off" />
						</div>
						<div v-show="showSection('private_key')" class="sg-account-form__field">
							<NcTextArea
								ref="alipayPrivateKey"
								:label="t('Application private key')"
								:value.sync="form.alipay.private_key"
								:rows="4"
								autocomplete="off" />
						</div>
						<div v-show="showSection('public_key')" class="sg-account-form__field">
							<NcTextArea
								ref="alipayPublicKey"
								:label="t('Alipay public key')"
								:value.sync="form.alipay.alipay_public_key"
								:rows="4"
								autocomplete="off" />
						</div>
						<div v-show="showSection('sandbox')" class="sg-account-form__field">
							<label class="sg-account-form__label" for="sg-sandbox">
								{{ t('Sandbox mode') }}
							</label>
							<select
								id="sg-sandbox"
								v-model="sandboxChoice"
								class="sg-account-select">
								<option value="true">
									{{ t('Yes (sandbox)') }}
								</option>
								<option value="false">
									{{ t('No (production)') }}
								</option>
							</select>
						</div>
						<div v-show="showSection('notify_base')" class="sg-account-form__field">
							<NcTextField
								:label="t('Notify URL base (optional)')"
								:placeholder="t('Leave empty to use Nextcloud site URL')"
								:show-trailing-button="false"
								:value.sync="form.alipay.notify_url_base" />
						</div>
						<p v-show="showSection('notify')" class="settings-hint">
							{{ t('Async notify URL (register in Alipay console)') }}:
							<code class="sg-code">{{ form.alipay.notify_url || '—' }}</code>
						</p>
					</div>

					<div v-show="showSection('save')" class="sg-account-form__actions">
						<NcButton
							type="primary"
							:disabled="saving"
							@click="onSaveClick">
							{{ t('Save settings') }}
						</NcButton>
					</div>
				</form>

				<div v-else-if="account" class="sg-account-readonly">
					<p v-if="showSection('mode')">
						<strong>{{ t('Payment mode') }}:</strong> {{ modeLabel }}
					</p>
					<p v-if="showSection('stripe')">
						<strong>{{ t('Stripe') }}:</strong> {{ stripeBoundLabel }}
					</p>
					<p v-if="showSection('paypal')">
						<strong>{{ t('PayPal') }}:</strong> {{ paypalBoundLabel }}
					</p>
					<p v-if="showSection('alipay')">
						<strong>{{ t('Alipay Face-to-Face') }}:</strong> {{ boundLabel }}
					</p>
					<p v-if="account.alipay_sandbox && showSection('sandbox')" class="settings-hint">
						{{ t('Sandbox mode') }}
					</p>
					<p v-if="account.notify_url && showSection('notify')">
						<strong>{{ t('Async notify URL (register in Alipay console)') }}:</strong><br>
						<code class="sg-code">{{ account.notify_url }}</code>
					</p>
					<p v-if="showSection('contact')" class="settings-hint">
						{{ t('Contact your Nextcloud admin to configure payment.') }}
					</p>
				</div>

				<div v-if="hasActiveSearch && !hasVisibleContent" class="emptycontent">
					{{ t('No settings match the search') }}
				</div>
			</template>
		</div>
	</div>
</template>

<script>
import { t } from '../utils/l10n.js'
import Cog from 'vue-material-design-icons/Cog.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FilesListBreadcrumbs from './FilesListBreadcrumbs.vue'
import { loadAccountSettings, loadPaymentConfig, savePaymentConfig } from '../utils/api.js'
import { getDashboardConfig } from '../utils/config.js'
import { showError, showTemporary } from '../utils/notify.js'

const SEARCH_SECTIONS = {
	provider: ['effective', 'provider', '生效', 'mock', 'alipay', 'stripe'],
	mode: ['payment', '支付', 'mock', 'mode', '模式', 'alipay', 'stripe'],
	app_id: ['app', 'id'],
	private_key: ['private', 'key', '私钥'],
	public_key: ['public', 'key', '公钥', 'alipay'],
	sandbox: ['sandbox', '沙箱'],
	notify_base: ['notify', 'base', 'url', '通知', '根'],
	notify: ['notify', 'url', '通知', '异步'],
	stripe_secret: ['stripe', 'secret', 'key', '密钥'],
	stripe_webhook: ['stripe', 'webhook', 'secret', '回调'],
	stripe_currency: ['stripe', 'currency', 'usd', 'eur', '货币'],
	stripe_webhook_url: ['stripe', 'webhook', 'url', '回调'],
	paypal_client_id: ['paypal', 'client', 'id'],
	paypal_client_secret: ['paypal', 'client', 'secret', '密钥'],
	paypal_webhook_id: ['paypal', 'webhook', 'id'],
	paypal_sandbox: ['paypal', 'sandbox', '沙箱'],
	paypal_currency: ['paypal', 'currency', 'usd', 'eur', '货币'],
	paypal_webhook_url: ['paypal', 'webhook', 'url', '回调'],
	save: ['save', '保存', 'settings', '设置'],
	alipay: ['alipay', '支付宝', 'bound', '绑定', 'face'],
	stripe: ['stripe', 'card', 'international', '国际'],
	paypal: ['paypal', 'wallet', 'international', '国际'],
	contact: ['contact', 'admin', '联系', '管理员'],
}

function emptyPaypalForm() {
	return {
		client_id: '',
		client_secret: '',
		webhook_id: '',
		sandbox: true,
		currency: 'usd',
		webhook_url: '',
	}
}

function emptyStripeForm() {
	return {
		secret_key: '',
		webhook_secret: '',
		currency: 'usd',
		webhook_url: '',
	}
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
				payment_mode: 'alipay_f2f',
				effective_provider: '',
				effective_provider_label: '',
				alipay: emptyAlipayForm(),
				stripe: emptyStripeForm(),
				paypal: emptyPaypalForm(),
			},
			internationalCurrencies: ['usd', 'eur', 'gbp', 'cad', 'aud', 'chf', 'sgd', 'hkd', 'nzd', 'jpy'],
			providers: [],
			mockSelectable: false,
			saving: false,
		}
	},
	computed: {
		isAdmin() {
			return this.resolveIsAdmin(this.account)
		},
		breadcrumbTitle() {
			return this.t('Account binding')
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
		paypalSandboxChoice: {
			get() {
				return this.form.paypal.sandbox ? 'true' : 'false'
			},
			set(value) {
				this.form.paypal.sandbox = value === 'true'
			},
		},
		visibleProviders() {
			return this.providers.filter((provider) => provider.selectable !== false)
		},
		selectableProviders() {
			const fallback = [
				{ id: 'stripe', label: this.t('Stripe'), selectable: true },
				{ id: 'paypal', label: this.t('PayPal'), selectable: true },
				{ id: 'alipay_f2f', label: this.t('Alipay Face-to-Face'), selectable: true },
			]
			if (this.mockSelectable) {
				fallback.unshift({ id: 'mock', label: this.t('Mock (development)'), selectable: true })
			}
			const source = this.providers.length ? this.providers : fallback
			return source.filter((provider) => provider.selectable !== false)
		},
		modeLabel() {
			const mode = this.isAdmin
				? this.form.payment_mode
				: this.account?.payment_mode
			if (!mode) {
				return '—'
			}
			return this.providerLabel(mode)
		},
		boundLabel() {
			if (!this.account) {
				return '—'
			}
			return this.account.alipay_configured
				? this.t('Bound')
				: this.t('Not bound')
		},
		stripeBoundLabel() {
			if (!this.account) {
				return '—'
			}
			return this.account.stripe_configured
				? this.t('Bound')
				: this.t('Not bound')
		},
		paypalBoundLabel() {
			if (!this.account) {
				return '—'
			}
			return this.account.paypal_configured
				? this.t('Bound')
				: this.t('Not bound')
		},
		showPaypalSection() {
			if (!this.hasActiveSearch) {
				return true
			}
			return ['paypal_client_id', 'paypal_client_secret', 'paypal_webhook_id', 'paypal_sandbox', 'paypal_currency', 'paypal_webhook_url']
				.some((key) => this.showSection(key))
		},
		showStripeSection() {
			if (!this.hasActiveSearch) {
				return true
			}
			return ['stripe_secret', 'stripe_webhook', 'stripe_currency', 'stripe_webhook_url']
				.some((key) => this.showSection(key))
		},
		showAlipaySection() {
			if (!this.hasActiveSearch) {
				return true
			}
			return ['app_id', 'private_key', 'public_key', 'sandbox', 'notify_base', 'notify']
				.some((key) => this.showSection(key))
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
				if (this.form.payment_mode === 'alipay_f2f' || this.form.payment_mode === 'stripe' || this.form.payment_mode === 'paypal') {
					return keys.some((key) => this.showSection(key))
				}
				return ['provider', 'mode', 'save'].some((key) => this.showSection(key))
			}
			if (!this.account) {
				return false
			}
			return ['mode', 'provider', 'alipay', 'stripe', 'paypal', 'sandbox', 'notify', 'contact']
				.some((key) => this.showSection(key))
		},
	},
	mounted() {
		this.loadAccount()
	},
	methods: {
		t,
		providerLabel(providerId) {
			if (!providerId) {
				return '—'
			}
			const match = this.providers.find((provider) => provider.id === providerId)
			return match?.label || providerId
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
				provider: this.modeLabel,
				mode: this.modeLabel,
				app_id: this.form.alipay?.app_id,
				private_key: this.t('Application private key'),
				public_key: this.t('Alipay public key'),
				sandbox: this.t('Sandbox mode'),
				notify_base: this.form.alipay?.notify_url_base,
				notify: this.form.alipay?.notify_url || this.account?.notify_url,
				save: this.t('Save settings'),
				alipay: this.boundLabel,
				stripe: this.stripeBoundLabel,
				stripe_secret: this.t('Stripe secret key'),
				stripe_webhook: this.t('Stripe webhook secret'),
				stripe_currency: this.t('Checkout currency'),
				stripe_webhook_url: this.form.stripe?.webhook_url,
				paypal: this.paypalBoundLabel,
				paypal_client_id: this.t('PayPal Client ID'),
				paypal_client_secret: this.t('PayPal Client Secret'),
				paypal_webhook_id: this.t('PayPal Webhook ID (optional)'),
				paypal_sandbox: this.t('Sandbox mode'),
				paypal_currency: this.t('Checkout currency'),
				paypal_webhook_url: this.form.paypal?.webhook_url,
				contact: this.t('Contact your Nextcloud admin to configure payment.'),
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
			this.mockSelectable = summary.mock_selectable === true
			if (Array.isArray(summary.providers)) {
				this.providers = summary.providers
			}
			const alipay = summary.alipay_f2f || {}
			const stripe = summary.stripe || {}
			const paypal = summary.paypal || {}
			let paymentMode = summary.payment_mode || 'alipay_f2f'
			if (paymentMode === 'mock' && !this.mockSelectable) {
				paymentMode = 'alipay_f2f'
			}
			this.form = {
				payment_mode: paymentMode,
				effective_provider: summary.effective_provider || '',
				effective_provider_label: summary.effective_provider_label || '',
				alipay: {
					app_id: alipay.app_id || '',
					private_key: alipay.private_key || '',
					alipay_public_key: alipay.alipay_public_key || '',
					sandbox: alipay.sandbox !== false,
					notify_url_base: alipay.notify_url_base || '',
					notify_url: alipay.notify_url || '',
				},
				stripe: {
					secret_key: stripe.secret_key || '',
					webhook_secret: stripe.webhook_secret || '',
					currency: stripe.currency || 'usd',
					webhook_url: stripe.webhook_url || '',
				},
				paypal: {
					client_id: paypal.client_id || '',
					client_secret: paypal.client_secret || '',
					webhook_id: paypal.webhook_id || '',
					sandbox: paypal.sandbox !== false,
					currency: paypal.currency || 'usd',
					webhook_url: paypal.webhook_url || '',
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
					this.loadError = this.t('Dashboard config missing')
					return
				}
				if (data.account) {
					this.account = data.account
					if (Array.isArray(data.account.providers)) {
						this.providers = data.account.providers
					}
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
						|| this.t('Failed to load account settings')
					return
				}
				if (data.payment_config) {
					this.applyAdminSummary(data.payment_config)
				} else if (isAdmin) {
					await this.loadAdminPaymentConfig()
				}
				if (!this.account && !isAdmin) {
					this.loadError = data.error
						|| this.t('Failed to load account settings')
				}
			} catch (e) {
				this.loadError = e?.message || this.t('Failed to load account settings')
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
					|| this.t('Failed to load payment settings')
			}
		},
		onSubmit(event) {
			event.preventDefault()
			this.onSaveClick()
		},
		onSaveClick(event) {
			if (this.saving) {
				return
			}
			if (event?.preventDefault) {
				event.preventDefault()
			}
			if (!this.validateRequiredFields()) {
				return
			}
			this.save()
		},
		getNativeInput(refName) {
			const root = this.$refs[refName]
			if (!root) {
				return null
			}
			const el = root.$el || root
			if (typeof el.querySelector !== 'function') {
				return null
			}
			return el.querySelector('input, textarea')
		},
		reportEmptyField(refName) {
			const input = this.getNativeInput(refName)
			if (!input) {
				return false
			}
			const wasRequired = input.required
			input.required = true
			const empty = !String(input.value || '').trim()
			if (empty) {
				input.reportValidity()
				input.focus()
			}
			input.required = wasRequired
			return !empty
		},
		validateRequiredFields() {
			const mode = this.form.payment_mode
			const fields = {
				paypal: ['paypalClientId', 'paypalClientSecret'],
				stripe: ['stripeSecretKey', 'stripeWebhookSecret'],
				alipay_f2f: ['alipayAppId', 'alipayPrivateKey', 'alipayPublicKey'],
			}[mode]
			if (!fields) {
				return true
			}
			for (const refName of fields) {
				if (!this.reportEmptyField(refName)) {
					return false
				}
			}
			return true
		},
		async save() {
			this.saving = true
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
					stripe: {
						secret_key: this.form.stripe.secret_key,
						webhook_secret: this.form.stripe.webhook_secret,
						currency: this.form.stripe.currency,
					},
					paypal: {
						client_id: this.form.paypal.client_id,
						client_secret: this.form.paypal.client_secret,
						webhook_id: this.form.paypal.webhook_id,
						sandbox: this.form.paypal.sandbox,
						currency: this.form.paypal.currency,
					},
				})
				if (data?.success) {
					if (data.summary) {
						this.applyAdminSummary(data.summary)
					}
					showTemporary(data.message || this.t('Settings saved'))
					window.dispatchEvent(new Event('sharegate:payment-saved'))
					return
				}
				showError(data?.error || data?.message || this.t('Save failed'))
			} catch (e) {
				showError(e?.message || this.t('Save failed'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
