import Vue from 'vue'
import BuyerPurchasesApp from '../BuyerPurchasesApp.vue'

Vue.config.productionTip = false

const mountEl = document.getElementById('sharegate-buyer-purchases')
if (mountEl) {
	new Vue({
		el: mountEl,
		render(h) {
			return h(BuyerPurchasesApp)
		},
	})
}
