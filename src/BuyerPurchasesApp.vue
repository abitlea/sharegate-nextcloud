<template>
	<div class="card sg-buyer-purchases__card">
		<h1>{{ t('My purchases') }}</h1>
		<p class="hint sg-buyer-purchases__hint">
			{{ t('Purchase list is protected. Verify your payment account or open a signed link after payment.') }}
		</p>
		<PurchasesPanel />
	</div>
</template>

<script>
import { t } from './utils/l10n.js'
import PurchasesPanel from './components/PurchasesPanel.vue'

export default {
	name: 'BuyerPurchasesApp',
	components: {
		PurchasesPanel,
	},
	mounted() {
		document.title = this.t('My purchases')
	},
	methods: {
		t,
	},
}
</script>
