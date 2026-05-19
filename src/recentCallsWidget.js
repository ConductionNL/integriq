import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import RecentCallsWidget from './views/widgets/RecentCallsWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('openconnector_recent_calls_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(RecentCallsWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
