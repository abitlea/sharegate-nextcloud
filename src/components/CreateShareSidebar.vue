<template>
	<NcAppSidebar
		:open.sync="sidebarOpen"
		:no-toggle="true"
		:name="sidebarName"
		:subname="form.file_path"
		:subtitle="t('File path', '文件路径')"
		@close="onClose">
		<template #description>
			<NcNoteCard type="info">
				{{ t('Manage paid share settings (price, expiry) in the paid share panel after creation', '需要修改添加后[付费分享]记录，请到[付费分享]模块') }}
			</NcNoteCard>
		</template>

		<NcAppSidebarTab
			:id="TAB_ID"
			:name="t('Paid share', '付费分享')"
			:order="0">
			<div v-if="success" class="sg-sidebar-form">
				<NcNoteCard type="success">
					{{ t('Share created successfully', '分享创建成功') }}
				</NcNoteCard>
				<NcTextField
					:label="t('Share link', '分享链接')"
					:readonly="true"
					:show-trailing-button="false"
					:value="successUrl" />
				<div class="sg-sidebar-form__meta">
					{{ t('Price', '价格') }}: ¥{{ successPrice }} ·
					{{ t('Access days', '授权') }}: {{ successAccessDays }}
					{{ t('days', '天') }}
				</div>
				<div class="sg-sidebar-form__actions">
					<NcButton type="primary" @click="copySuccessUrl">
						{{ t('Copy link', '复制链接') }}
					</NcButton>
				</div>
			</div>
			<form v-else class="sg-sidebar-form" @submit.prevent="submit">
				<p v-if="error" class="warning">
					{{ error }}
				</p>
				<div v-if="existingShareId" class="sg-sidebar-form__actions">
					<NcButton type="primary" @click="openExistingShare">
						{{ t('Open existing share', '打开已有分享') }}
					</NcButton>
				</div>
				<p class="sg-sidebar-form__note">
					{{ t('Note: After creation, please go to the Paid Shares module to modify the share record settings.', '注意：创建后需要到【付费分享】模块修改该分享记录的设置。') }}
				</p>
				<NcTextField
					:label="t('File path', '文件路径')"
					:disabled="pathLocked"
					:show-trailing-button="false"
					:value.sync="form.file_path"
					:placeholder="t('e.g. Documents/report.pdf', '例如 Documents/report.pdf')"
					required />
				<NcTextField
					:label="t('File name', '文件名')"
					:disabled="pathLocked"
					:show-trailing-button="false"
					:value.sync="form.file_name"
					required />
				<NcTextField
					:label="t('Share title', '分享标题')"
					:show-trailing-button="false"
					:value.sync="form.title"
					:placeholder="t('e.g. Paid document', '例如：付费文档')"
					required />
				<NcTextField
					:label="t('Price (CNY)', '定价（元）')"
					type="number"
					:show-trailing-button="false"
					:value.sync="form.priceYuan"
					:min="0.01"
					step="0.01"
					required />
				<NcTextField
					:label="t('Access days after payment', '付款后可访问天数')"
					type="number"
					:show-trailing-button="false"
					:value.sync="form.access_days"
					:min="1"
					:max="365"
					required />
				<NcTextField
					:label="t('Link expiry (days)', '链接有效期（天）')"
					type="number"
					:show-trailing-button="false"
					:value.sync="form.share_expire_days"
					:min="1"
					:max="3650"
					:placeholder="t('Leave empty for no expiry', '留空表示不过期')" />
				<div class="sg-sidebar-form__actions">
					<NcButton
						type="primary"
						native-type="submit"
						:disabled="saving">
						{{ t('Create share', '创建分享') }}
					</NcButton>
					<NcButton :disabled="saving" @click="close">
						{{ t('Cancel', '取消') }}
					</NcButton>
				</div>
			</form>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import { showTemporary } from '../utils/notify.js'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { createShare } from '../utils/api.js'
import { buildPublicUrl } from '../utils/format.js'
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
	},
	data() {
		return {
			TAB_ID,
			saving: false,
			error: '',
			existingShareId: '',
			success: false,
			successUrl: '',
			successPrice: '',
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
				|| this.t('Create paid share', '创建付费分享')
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
		t(key, fallback) {
			const v = translate('sharegate', key)
			return v && v !== key ? v : fallback
		},
		emptyForm() {
			return {
				file_path: '',
				file_name: '',
				title: '',
				priceYuan: '1.00',
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
			this.successPrice = ''
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
		async submit() {
			const filePath = String(this.form.file_path || '').trim()
			const fileName = String(this.form.file_name || '').trim()
			const title = String(this.form.title || '').trim()
			const priceYuan = parseFloat(this.form.priceYuan)
			const accessDays = parseInt(String(this.form.access_days), 10)
			const expireStr = String(this.form.share_expire_days ?? '').trim()

			if (!filePath || !fileName || !title) {
				this.error = this.t('Please fill file path, name and title', '请填写文件路径、文件名和分享标题')
				return
			}
			if (!priceYuan || priceYuan <= 0) {
				this.error = this.t('Price must be greater than 0', '价格必须大于 0')
				return
			}
			if (!accessDays || accessDays < 1) {
				this.error = this.t('Access days must be at least 1', '授权天数至少为 1')
				return
			}

			const body = {
				file_path: filePath,
				file_name: fileName,
				storage_type: 'nextcloud',
				title,
				price: Math.round(priceYuan * 100),
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
					this.error = data.error || this.t('Create failed', '创建失败')
					this.existingShareId = data.existing_share_id || ''
					return
				}
				const sharePath = data.share_url || ('/apps/sharegate/s/' + data.share_id)
				this.successUrl = buildPublicUrl(sharePath)
				this.successPrice = (data.price / 100).toFixed(2)
				this.successAccessDays = data.access_days
				this.success = true
				this.$emit('created', data)
			} catch (e) {
				this.error = this.t('Network error', '网络错误') + ': ' + e.message
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
			showTemporary(this.t('Link copied', '已复制链接'))
		},
	},
}
</script>
