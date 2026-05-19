import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import SourceSyncWidget from './views/widgets/SourceSyncWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('openconnector_source_sync_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(SourceSyncWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
