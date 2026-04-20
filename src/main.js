import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import { registerIcons, registerTranslations } from '@conduction/nextcloud-vue'
import '@conduction/nextcloud-vue/css/index.css'
import { Fragment } from 'vue-frag'
import pinia from './pinia.js'
import App from './App.vue'
import router from './router/index.js'
import './assets/app.css'

registerIcons({})
registerTranslations()

Vue.mixin({ methods: { t, n } })

Vue.use(PiniaVuePlugin)
Vue.directive('tooltip', Tooltip)

Vue.component('Fragment', Fragment)

new Vue(
	{
		pinia,
		router,
		render: h => h(App),
	},
).$mount('#content')
