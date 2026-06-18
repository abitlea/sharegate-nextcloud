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
				{{ t('Configure price and access for this paid share link. The file cannot be changed here.') }}
			</NcNoteCard>
		</template>

		<NcAppSidebarTab
			:id="TAB_ID"
			:name="t('Paid share settings')"
			:order="0">
			<NcLoadingIcon v-if="loading" class="sg-sidebar__loading" :size="32" />
			<div v-else-if="loadError" class="sg-sidebar-form">
				<p class="warning">{{ loadError }}</p>
				<div class="sg-sidebar-form__actions">
					<NcButton @click="close">
						{{ t('Close') }}
					</NcButton>
				</div>
			</div>
			<form v-else class="sg-sidebar-form" @submit.prevent="save">
				<p v-if="error" class="warning">
					{{ error }}
				</p>
				<NcTextField
					:label="t('File path')"
					:readonly="true"
					:show-trailing-button="false"
					:value="form.file_path" />
				<NcTextField
					:label="t('File name')"
					:readonly="true"
					:show-trailing-button="false"
					:value="form.file_name" />
				<NcTextField
					:label="t('Share title')"
					:show-trailing-button="false"
					:value.sync="form.title"
					required />
				<NcTextField
					:label="t('Price (CNY)')"
					type="number"
					:show-trailing-button="false"
					:value.sync="form.priceYuan"
					:min="0.01"
					step="0.01"
					required />
				<NcTextField
					:label="t('Access days after payment')"
					type="number"
					:show-trailing-button="false"
					:value.sync="form.access_days"
					:min="1"
					:max="365"
					required />
				<NcTextField
					:label="t('Link expiry (days)')"
					type="number"
					:show-trailing-button="false"
					:value.sync="form.share_expire_days"
					:min="1"
					:max="3650"
					:placeholder="t('Leave empty for no expiry')" />
				<NcTextField
					:label="t('Public share link')"
					:readonly="true"
					:show-trailing-button="false"
					:value="form.share_url" />
				<div class="sg-sidebar-form__actions">
					<NcButton
						type="primary"
						native-type="submit"
						:disabled="saving">
						{{ t('Save settings') }}
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
import { showTemporary } from '../utils/notify.js'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { getShareSettings, updateShareSettings } from '../utils/api.js'

const TAB_ID = 'sharegate-settings'

export default {
	name: 'ShareSettingsSidebar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},
	props: {
		open: { type: Boolean, default: false },
		shareId: { type: String, default: '' },
	},
	emits: ['update:open', 'saved'],
	data() {
		return {
			TAB_ID,
			loading: false,
			saving: false,
			loadError: '',
			error: '',
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
		sidebarName() {
			return this.form.file_name
				|| this.form.title
				|| this.t('Paid share settings')
		},
	},
	watch: {
		open(val) {
			if (val && this.shareId) {
				this.loadShare()
			}
		},
		shareId(val) {
			if (this.open && val) {
				this.loadShare()
			}
		},
	},
	methods: {
		t,
		emptyForm() {
			return {
				file_path: '',
				file_name: '',
				title: '',
				priceYuan: '',
				access_days: '30',
				share_expire_days: '',
				share_url: '',
			}
		},
		resetState() {
			this.loading = false
			this.saving = false
			this.loadError = ''
			this.error = ''
			this.form = this.emptyForm()
		},
		onClose() {
			this.resetState()
		},
		close() {
			this.sidebarOpen = false
		},
		async loadShare() {
			this.loading = true
			this.loadError = ''
			this.error = ''
			try {
				const data = await getShareSettings(this.shareId)
				if (!data.success || !data.share) {
					this.loadError = data.error || this.t('Loading failed')
					return
				}
				const share = data.share
				this.form = {
					file_path: share.file_path || '',
					file_name: share.file_name || '',
					title: share.title || '',
					priceYuan: (share.price / 100).toFixed(2),
					access_days: String(share.access_days || 30),
					share_expire_days: share.share_expire_days == null ? '' : String(share.share_expire_days),
					share_url: share.share_url || '',
				}
			} catch (e) {
				this.loadError = this.t('Network error') + ': ' + e.message
			} finally {
				this.loading = false
			}
		},
		async save() {
			const title = String(this.form.title || '').trim()
			const priceYuan = parseFloat(this.form.priceYuan)
			const accessDays = parseInt(String(this.form.access_days), 10)
			const expireStr = String(this.form.share_expire_days ?? '').trim()

			if (!title) {
				this.error = this.t('Please enter a share title')
				return
			}
			if (!priceYuan || priceYuan <= 0) {
				this.error = this.t('Price must be greater than 0')
				return
			}
			if (!accessDays || accessDays < 1) {
				this.error = this.t('Access days must be at least 1')
				return
			}

			const body = {
				title,
				price: Math.round(priceYuan * 100),
				access_days: accessDays,
				share_expire_days: expireStr === '' ? null : parseInt(expireStr, 10),
			}

			this.saving = true
			this.error = ''
			try {
				const data = await updateShareSettings(this.shareId, body)
				if (data.success) {
					showTemporary(this.t('Settings saved'))
					this.$emit('saved')
					this.close()
				} else {
					this.error = data.error || this.t('Save failed')
				}
			} catch (e) {
				this.error = this.t('Network error') + ': ' + e.message
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
