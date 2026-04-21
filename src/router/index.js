import Vue from 'vue'
import Router from 'vue-router'

// Views
import Dashboard from '../views/dashboard/DashboardIndex.vue'
import SourcesIndex from '../views/Source/SourcesIndex.vue'
import SourceLogIndex from '../views/Source/SourceLogIndex.vue'
import EndpointsIndex from '../views/Endpoint/EndpointsIndex.vue'
import EndpointDetails from '../views/Endpoint/EndpointDetails.vue'
import EndpointLogIndex from '../views/Endpoint/EndpointLogIndex.vue'
import ConsumersIndex from '../views/Consumer/ConsumersIndex.vue'
import WebhooksIndex from '../views/Webhook/WebhooksIndex.vue'
import JobsIndex from '../views/Job/JobsIndex.vue'
import JobLogIndex from '../views/Job/JobLogIndex.vue'
import MappingsIndex from '../views/Mapping/MappingsIndex.vue'
import RulesIndex from '../views/rule/RuleIndex.vue'
import SynchronizationsIndex from '../views/Synchronization/SynchronizationsIndex.vue'
import ContractsIndex from '../views/contracts/ContractsIndex.vue'
import SynchronizationLogIndex from '../views/Synchronization/SynchronizationLogIndex.vue'
import EventsIndex from '../views/event/EventIndex.vue'
import EventLogIndex from '../views/event/EventLogIndex.vue'
import ImportIndex from '../views/Import/ImportIndex.vue'

// Sidebars (named view "sidebar")
import SourceLogSideBar from '../sidebars/Source/SourceLogSideBar.vue'
import EndpointLogSideBar from '../sidebars/Endpoint/EndpointLogSideBar.vue'
import JobLogSideBar from '../sidebars/Job/JobLogSideBar.vue'
import LogsSideBar from '../sidebars/logs/LogsSideBar.vue'
import EventLogSideBar from '../sidebars/event/EventLogSideBar.vue'
import ContractsSideBar from '../sidebars/contracts/ContractsSideBar.vue'

Vue.use(Router)

const router = new Router({
	mode: 'history',
	base: '/index.php/apps/openconnector/',
	routes: [
		{ path: '/', components: { default: Dashboard } },
		{ path: '/sources', components: { default: SourcesIndex } },
		{ path: '/sources/logs', components: { default: SourceLogIndex, sidebar: SourceLogSideBar } },
		{ path: '/endpoints', components: { default: EndpointsIndex } },
		{ path: '/endpoints/logs', components: { default: EndpointLogIndex, sidebar: EndpointLogSideBar } },
		{ path: '/endpoints/:id', components: { default: EndpointDetails } },
		{ path: '/consumers', components: { default: ConsumersIndex } },
		{ path: '/consumers/:id', components: { default: ConsumersIndex } },
		{ path: '/webhooks', components: { default: WebhooksIndex } },
		{ path: '/jobs', components: { default: JobsIndex } },
		{ path: '/jobs/logs', components: { default: JobLogIndex, sidebar: JobLogSideBar } },
		{ path: '/mappings', components: { default: MappingsIndex } },
		{ path: '/mappings/:id', components: { default: MappingsIndex } },
		{ path: '/rules', components: { default: RulesIndex } },
		{ path: '/rules/:id', components: { default: RulesIndex } },
		{ path: '/synchronizations', components: { default: SynchronizationsIndex } },
		{ path: '/synchronizations/contracts', components: { default: ContractsIndex, sidebar: ContractsSideBar } },
		{ path: '/synchronizations/logs', components: { default: SynchronizationLogIndex, sidebar: LogsSideBar } },
		{ path: '/cloud-events', redirect: '/cloud-events/events' },
		{ path: '/cloud-events/events', components: { default: EventsIndex } },
		{ path: '/cloud-events/events/:id', components: { default: EventsIndex } },
		{ path: '/cloud-events/logs', components: { default: EventLogIndex, sidebar: EventLogSideBar } },
		{ path: '/import', components: { default: ImportIndex } },
		{ path: '*', redirect: '/' },
	],
})

export default router
