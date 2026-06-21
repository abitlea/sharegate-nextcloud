<!--
  对齐 apps/files/src/components/BreadCrumbs.vue（单段「你的共享」视图）
  使用 NcBreadcrumbs + NcBreadcrumb + force-menu + menu-icon  Chevron
-->
<template>
	<NcBreadcrumbs
		data-cy-files-content-breadcrumbs
		:aria-label="t('Current directory path')"
		class="files-list__breadcrumbs">
		<NcBreadcrumb
			:name="title"
			:title="reloadTitle"
			dir="auto"
			:force-icon-text="showViewIcon"
			force-menu
			:open.sync="isMenuOpen">
			<template v-if="showViewIcon" #icon>
				<component :is="viewIcon" :size="20" />
			</template>
			<template #menu-icon>
				<ChevronUp v-if="isMenuOpen" :size="20" />
				<ChevronDown v-else :size="20" />
			</template>
			<NcActionButton
				:close-after-click="true"
				@click="onReload">
				<template #icon>
					<Reload :size="20" />
				</template>
				{{ t('Reload content') }}
			</NcActionButton>
		</NcBreadcrumb>
	</NcBreadcrumbs>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcBreadcrumb from '@nextcloud/vue/components/NcBreadcrumb'
import NcBreadcrumbs from '@nextcloud/vue/components/NcBreadcrumbs'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Reload from 'vue-material-design-icons/Reload.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'

export default {
	name: 'FilesListBreadcrumbs',
	components: {
		NcActionButton,
		NcBreadcrumb,
		NcBreadcrumbs,
		ChevronDown,
		ChevronUp,
		Reload,
		LinkVariant,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
		viewIcon: {
			type: [Object, Function],
			default: () => LinkVariant,
		},
		showViewIcon: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['reload'],
	data() {
		return {
			isMenuOpen: false,
		}
	},
	computed: {
		reloadTitle() {
			return this.t('Reload current directory')
		},
	},
	methods: {
		t(key) {
			const v = translate('sharegate', key)
			if (v && v !== key) {
				return v
			}
			const files = translate('files', key)
			return files && files !== key ? files : key
		},
		onReload() {
			this.isMenuOpen = false
			this.$emit('reload')
		},
	},
}
</script>
