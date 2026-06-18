<template>
	<NcContent app-name="sharegate">
		<NcAppNavigation
			:open.sync="navigationOpen"
			:aria-label="t('Paid sharing')">
			<template v-if="showSearch" #search>
				<NcAppNavigationSearch
					v-model="searchQuery"
					:label="searchLabel"
					:placeholder="searchLabel" />
			</template>
			<template #list>
				<NcAppNavigationItem
					v-for="item in navItems"
					:key="item.hash"
					:name="item.name"
					:href="navHref(item.hash)"
					:active="activeHash === item.hash"
					@click.prevent="navigate(item.hash)">
					<template #icon>
						<component :is="item.icon" :size="20" />
					</template>
					<template v-if="item.countKey" #counter>
						<NcCounterBubble v-if="counts[item.countKey] > 0">
							{{ formatNavCounter(counts[item.countKey]) }}
						</NcCounterBubble>
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent :page-heading="contentPageHeading">
			<SharesListPanel
				v-if="route.view === 'list'"
				:key="route.filter"
				ref="listPanel"
				:filter="route.filter"
				:search-query="searchQuery"
				@open-settings="openSettings"
				@open-create="openCreateShare"
				@disable-share="confirmDisableShare" />
			<StatsPanel
				v-else-if="route.view === 'stats'"
				ref="statsPanel"
				:search-query="searchQuery" />
			<AccountPanel
				v-else-if="route.view === 'account'"
				:search-query="searchQuery" />
		</NcAppContent>

		<CreateShareSidebar
			v-if="route.view === 'list'"
			:open.sync="createOpen"
			:file-preset="createFile"
			@created="onShareCreated"
			@open-settings="openSettings" />

		<ShareSettingsSidebar
			v-if="route.view === 'list'"
			:open.sync="settingsOpen"
			:share-id="settingsShareId"
			@saved="onSettingsSaved" />
	</NcContent>
</template>

<script>
import { showError, showTemporary } from './utils/notify.js'
import { t } from './utils/l10n.js'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import AccountCash from 'vue-material-design-icons/AccountCash.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ChartDonut from 'vue-material-design-icons/ChartDonut.vue'
import AccountPanel from './components/AccountPanel.vue'
import CreateShareSidebar from './components/CreateShareSidebar.vue'
import ShareSettingsSidebar from './components/ShareSettingsSidebar.vue'
import SharesListPanel from './components/SharesListPanel.vue'
import StatsPanel from './components/StatsPanel.vue'
import { disableShare, loadSummary } from './utils/api.js'
import { getDashboardConfig } from './utils/config.js'
import { formatNavCounter } from './utils/format.js'
import { consumeEditParam, parseHash, setHash } from './utils/hashRouter.js'

const NAV_OPEN_KEY = 'sharegate-nav-open'

export default {
	name: 'DashboardApp',
	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSearch,
		NcAppContent,
		NcCounterBubble,
		SharesListPanel,
		StatsPanel,
		AccountPanel,
		CreateShareSidebar,
		ShareSettingsSidebar,
		LinkVariant,
		AccountCash,
		Cog,
		ChartDonut,
	},
	data() {
		const route = parseHash()
		return {
			route,
			searchQuery: '',
			counts: {},
			navigationOpen: true,
			settingsOpen: false,
			settingsShareId: '',
			createOpen: false,
			createFile: null,
		}
	},
	computed: {
		activeHash() {
			return this.route.hash
		},
		showSearch() {
			return this.route.view === 'list'
				|| this.route.view === 'account'
				|| this.route.view === 'stats'
		},
		pageTitle() {
			const titles = {
				public: this.t('Your shares'),
				paid: this.t('Paid shares'),
				account: this.t('Account binding'),
				stats: this.t('Statistics'),
			}
			return titles[this.activeHash] || titles.public
		},
		contentPageHeading() {
			if (this.route.view === 'list' && this.route.filter === 'all') {
				return this.t('Your shares')
			}
			if (this.route.view === 'list' && this.route.filter === 'active') {
				return this.t('Paid shares')
			}
			return this.pageTitle
		},
		searchLabel() {
			if (this.route.view === 'account') {
				return this.t('Search account settings')
			}
			if (this.route.view === 'stats') {
				return this.t('Search statistics')
			}
			return this.route.filter === 'active'
				? this.t('Search paid shares')
				: this.t('Search your shares')
		},
		navItems() {
			return [
				{
					hash: 'public',
					name: this.t('Your shares'),
					icon: LinkVariant,
					countKey: 'all',
				},
				{
					hash: 'paid',
					name: this.t('Paid shares'),
					icon: AccountCash,
					countKey: 'active',
				},
				{
					hash: 'account',
					name: this.t('Account binding'),
					icon: Cog,
					countKey: null,
				},
				{
					hash: 'stats',
					name: this.t('Statistics'),
					icon: ChartDonut,
					countKey: 'stats',
				},
			]
		},
	},
	watch: {
		contentPageHeading(title) {
			document.title = title
		},
		'route.hash'() {
			this.closePanels()
		},
		'route.view'() {
			this.closePanels()
		},
		navigationOpen(open) {
			try {
				localStorage.setItem(NAV_OPEN_KEY, open ? '1' : '0')
			} catch {
				// ignore
			}
		},
	},
	mounted() {
		this.restoreNavigationOpen()
		document.title = this.contentPageHeading
		this.refreshCounts()

		const editShareId = consumeEditParam()
		if (editShareId) {
			this.route = parseHash('#paid')
			setTimeout(() => this.openSettings(editShareId), 150)
		}

		window.addEventListener('hashchange', this.onHashChange)
		window.addEventListener('sharegate:payment-saved', this.refreshCounts)
	},
	beforeDestroy() {
		window.removeEventListener('hashchange', this.onHashChange)
		window.removeEventListener('sharegate:payment-saved', this.refreshCounts)
	},
	methods: {
		formatNavCounter,
		t,
		navHref(hash) {
			const config = getDashboardConfig()
			const base = (config.dashboardUrl || '').split('#')[0].replace(/\/$/, '')
			return base + '#' + hash
		},
		navigate(hash) {
			this.closePanels()
			setHash(hash)
			this.route = parseHash('#' + hash)
		},
		closePanels() {
			this.createOpen = false
			this.createFile = null
			this.settingsOpen = false
			this.settingsShareId = ''
		},
		onHashChange() {
			this.route = parseHash()
		},
		restoreNavigationOpen() {
			try {
				const saved = localStorage.getItem(NAV_OPEN_KEY)
				if (saved === '0') {
					this.navigationOpen = false
				}
			} catch {
				// ignore
			}
		},
		async refreshCounts() {
			try {
				const data = await loadSummary()
				if (data?.success && data.filters) {
					this.counts = data.filters
				}
			} catch {
				// ignore
			}
		},
		openSettings(shareId) {
			this.createOpen = false
			this.createFile = null
			this.settingsShareId = shareId
			this.settingsOpen = true
		},
		openCreateShare(file) {
			this.settingsOpen = false
			this.settingsShareId = ''
			this.createFile = file
			this.createOpen = true
		},
		onShareCreated() {
			this.refreshCounts()
			this.$refs.listPanel?.reload?.()
		},
		onSettingsSaved() {
			this.refreshCounts()
			this.$refs.listPanel?.reload?.()
		},
		async confirmDisableShare(shareId) {
			const confirmed = confirm(
				this.t('Confirm cancel share'),
			)
			if (!confirmed) {
				return
			}
			try {
				const data = await disableShare(shareId)
				if (data.success) {
					showTemporary(this.t('Share cancelled'))
					this.refreshCounts()
					this.$refs.listPanel?.reload?.()
				} else {
					showError(data.error || this.t('Failed to cancel share'))
				}
			} catch (e) {
				showError(this.t('Network error') + ': ' + e.message)
			}
		},
	},
}
</script>
