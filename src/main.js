import Vue from 'vue'
import DashboardApp from './DashboardApp.vue'

Vue.config.productionTip = false

const mountEl = document.getElementById('sharegate-dashboard')
if (mountEl) {
	new Vue({
		el: mountEl,
		render(h) {
			return h(DashboardApp)
		},
	})
}
