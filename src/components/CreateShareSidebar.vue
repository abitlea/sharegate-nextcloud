<template>
	<NcAppSidebar
		:open.sync="sidebarOpen"
		:no-toggle="true"
		:name="sidebarName"
		:subname="form.file_path"
		:subtitle="t('File path')"
		@close="onClose">
		<template #description>
			<NcNoteCard type="info">
				{{ t('Manage paid share settings (price, expiry) in the paid share panel after creation') }}
			</NcNoteCard>
		</template>

		<NcAppSidebarTab
			:id="TAB_ID"
			:name="t('Paid share')"
			:order="0">
			<div v-if="success" class="sg-sidebar-form">
				<NcNoteCard type="success">
					{{ t('Share created successfully') }}
				</NcNoteCard>
				<NcTextField
					:label="t('Share link')"
					:readonly="true"
					:show-trailing-button="false"
					:value="successUrl" />
				<div class="sg-sidebar-form__meta">
					{{ t('Price') }}: {{ successPriceDisplay }} ·
					{{ t('Access days') }}: {{ successAccessDays }}
					{{ t('days') }}
				</div>
				<div class="sg-sidebar-form__actions">
					<NcButton type="primary" @click="copySuccessUrl">
						{{ t('Copy link') }}
					</NcButton>
				</div>
			</div>
			<form v-else class="sg-sidebar-form" novalidate @submit.prevent="submit">
				<NcNoteCard v-if="error" type="warning">
					{{ error }}
				</NcNoteCard>
				<div v-if="existingShareId" class="sg-sidebar-form__actions">
					<NcButton type="primary" @click="openExistingShare">
						{{ t('Open existing share') }}
					</NcButton>
				</div>
				<p class="sg-sidebar-form__note">
					{{ t('Note: After creation, please go to the Paid Shares module to modify the share record settings.') }}
				</p>
				<NcTextField
					:label="t('File path')"
					:disabled="pathLocked"
					:show-trailing-button="false"
					:value.sync="form.file_path"
					:placeholder="t('e.g. Documents/report.pdf')" />
				<NcTextField
					:label="t('File name')"
					:disabled="pathLocked"
					:show-trailing-button="false"
					:value.sync="form.file_name" />
				<NcTextField
					:label="t('Share title')"
					:show-trailing-button="false"
					:value.sync="form.title"
					:placeholder="t('e.g. Paid document')" />
				<NcTextField
					:label="priceFieldLabel"
					type="number"
					:show-trailing-button="false"
					:value.sync="form.priceAmount"
					:min="priceInput.min"
					:step="priceInput.step" />
				<p class="sg-sidebar-form__note">
					{{ priceChargedHint }} · {{ priceFieldHint }}
				</p>
				<NcTextField
					:label="t('Access days after payment')"
					type="number"
					:show-trailing-button="false"
					:value.sync="form.access_days"
					:min="1"
					:max="365" />
				<NcTextField
					:label="t('Link expiry (days)')"
					type="number"
					:show-trailing-button="false"
					:value.sync="form.share_expire_days"
					:min="1"
					:max="3650"
					:placeholder="t('Leave empty for no expiry')" />
				<div class="sg-sidebar-form__actions">
					<NcButton
						type="primary"
						native-type="submit"
						:disabled="saving">
						{{ t('Create share') }}
					</NcButton>
					<NcButton :disabled="saving" @click="close">
						{{ t('Cancel') }}
					</NcButton>
				</div>
			</form>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { t } from '../utils/l10n.js'
import { showError, showTemporary } from '../utils/notify.js'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { createShare } from '../utils/api.js'
import { buildPublicUrl } from '../utils/format.js'
import {
	formatMoney,
	getDisplayCurrency,
	majorAmountToMinor,
	minimumPriceHint,
	priceChargedInHint,
	priceColumnLabel,
	priceInputConfig,
} from '../utils/currency.js'
const TAB_ID = 'sharegate-create'

export default {
	name: 'CreateShareSidebar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcNoteCard,
		NcTextField,
	},
	props: {
		open: { type: Boolean, default: false },
		filePreset: {
			type: Object,
			default: null,
		},
		displayCurrency: { type: String, default: 'CNY' },
	},
	data() {
		return {
			TAB_ID,
			saving: false,
			error: '',
			existingShareId: '',
			success: false,
			successUrl: '',
			successPriceCents: 0,
			successAccessDays: 0,
			fileSize: 0,
			fileId: 0,
			form: this.emptyForm(),
		}
	},
	computed: {
		sidebarOpen: {
			get() {
				return this.open
			},
			set(value) {
				this.$emit('update:open', value)
				if (!value) {
					this.resetState()
				}
			},
		},
		pathLocked() {
			return !!(this.filePreset?.file_path && this.filePreset?.file_name)
		},
		sidebarName() {
			return this.form.file_name
				|| this.t('Create paid share')
		},
		priceFieldLabel() {
			return priceColumnLabel(this.effectiveCurrency)
		},
		priceFieldHint() {
			return minimumPriceHint(this.effectiveCurrency)
		},
		priceChargedHint() {
			return priceChargedInHint(this.effectiveCurrency)
		},
		priceInput() {
			return priceInputConfig(this.effectiveCurrency)
		},
		effectiveCurrency() {
			return this.displayCurrency || getDisplayCurrency()
		},
		successPriceDisplay() {
			return formatMoney(this.successPriceCents, this.effectiveCurrency)
		},
	},
	watch: {
		open(val) {
			if (val) {
				this.applyPreset(this.filePreset)
			}
		},
		filePreset: {
			deep: true,
			handler(preset) {
				if (this.open) {
					this.applyPreset(preset)
				}
			},
		},
	},
	methods: {
		t,
		emptyForm() {
			return {
				file_path: '',
				file_name: '',
				title: '',
				priceAmount: '1.00',
				access_days: '30',
				share_expire_days: '',
			}
		},
		applyPreset(preset) {
			this.error = ''
			this.existingShareId = ''
			this.success = false
			const path = preset?.file_path || ''
			const name = preset?.file_name || ''
			this.fileSize = preset?.file_size || 0
			this.fileId = preset?.file_id || 0
			this.form = {
				...this.emptyForm(),
				file_path: path,
				file_name: name,
				title: this.defaultTitle(name),
			}
		},
		defaultTitle(fileName) {
			if (!fileName) {
				return ''
			}
			const dot = fileName.lastIndexOf('.')
			return dot > 0 ? fileName.substring(0, dot) : fileName
		},
		resetState() {
			this.error = ''
			this.existingShareId = ''
			this.success = false
			this.successUrl = ''
			this.successPriceCents = 0
			this.successAccessDays = 0
			this.fileSize = 0
			this.fileId = 0
			this.form = this.emptyForm()
		},
		resetForAnother() {
			this.success = false
			this.error = ''
			this.applyPreset(null)
		},
		close() {
			this.sidebarOpen = false
		},
		onClose() {
			this.resetState()
		},
		showValidationError(message) {
			this.error = message
			showError(message)
		},
		async submit() {
			const filePath = String(this.form.file_path || '').trim()
			const fileName = String(this.form.file_name || '').trim()
			const title = String(this.form.title || '').trim()
			const priceAmount = parseFloat(this.form.priceAmount)
			const accessDays = parseInt(String(this.form.access_days), 10)
			const expireStr = String(this.form.share_expire_days ?? '').trim()

			if (!filePath || !fileName || !title) {
				this.showValidationError(this.t('Please enter file path, name and share title'))
				return
			}
			if (!priceAmount || priceAmount <= 0) {
				this.showValidationError(this.t('Price must be greater than 0'))
				return
			}
			if (!accessDays || accessDays < 1) {
				this.showValidationError(this.t('Access days must be at least 1'))
				return
			}

			const body = {
				file_path: filePath,
				file_name: fileName,
				storage_type: 'nextcloud',
				title,
				price: majorAmountToMinor(priceAmount, this.effectiveCurrency),
				access_days: accessDays,
			}
			if (this.fileSize > 0) {
				body.file_size = this.fileSize
			}
			if (this.fileId > 0) {
				body.file_id = this.fileId
			}
			if (expireStr !== '') {
				body.share_expire_days = parseInt(expireStr, 10)
			}

			this.saving = true
			this.error = ''
			this.existingShareId = ''
			try {
				const data = await createShare(body)
				if (!data.success) {
					const msg = data.error || this.t('Failed to create share')
					this.error = msg
					showError(msg)
					this.existingShareId = data.existing_share_id || ''
					return
				}
				const sharePath = data.share_url || ('/apps/sharegate/s/' + data.share_id)
				this.successUrl = buildPublicUrl(sharePath)
				this.successPriceCents = data.price
				this.successAccessDays = data.access_days
				this.success = true
				this.$emit('created', data)
			} catch (e) {
				const msg = this.t('Network error') + ': ' + e.message
				this.error = msg
				showError(msg)
			} finally {
				this.saving = false
			}
		},
		openExistingShare() {
			if (!this.existingShareId) {
				return
			}
			this.$emit('open-settings', this.existingShareId)
			this.close()
		},
		copySuccessUrl() {
			if (!this.successUrl) {
				return
			}
			navigator.clipboard.writeText(this.successUrl).catch(() => {})
			showTemporary(this.t('Link copied'))
		},
	},
}
</script>
