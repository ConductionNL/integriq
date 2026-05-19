import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import JobQueueWidget from './views/widgets/JobQueueWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('openconnector_job_queue_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(JobQueueWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
