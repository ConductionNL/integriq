// The store script handles app wide variables (or state), for the use of these variables and their governing concepts read the design.md
import pinia from '../pinia.js'
import { useNavigationStore } from './modules/navigation.js'
import { useSearchStore } from './modules/search.ts'
import { useJobStore } from './modules/job.ts'
import { useLogStore } from './modules/log.ts'
import { useMappingStore } from './modules/mapping.ts'
import { useSourceStore } from './modules/source.ts'
import { useSynchronizationStore } from './modules/synchronization.ts'
import { useWebhookStore } from './modules/webhooks.ts'
import { useEndpointStore } from './modules/endpoints.ts'
import { useConsumerStore } from './modules/consumer.ts'
import { useImportExportStore } from './modules/importExport.js'
import { useEventStore } from './modules/event.ts'
import { useRuleStore } from './modules/rule.ts'
import { useContractStore } from './modules/contract.ts'
import { useSettingsStore } from './modules/settings.js'

const navigationStore = useNavigationStore(pinia)
const searchStore = useSearchStore(pinia)
const jobStore = useJobStore(pinia)
const logStore = useLogStore(pinia)
const mappingStore = useMappingStore(pinia)
const sourceStore = useSourceStore(pinia)
const synchronizationStore = useSynchronizationStore(pinia)
const webhookStore = useWebhookStore(pinia)
const endpointStore = useEndpointStore(pinia)
const consumerStore = useConsumerStore(pinia)
const importExportStore = useImportExportStore(pinia)
const eventStore = useEventStore(pinia)
const ruleStore = useRuleStore(pinia)
const contractStore = useContractStore(pinia)
const settingsStore = useSettingsStore(pinia)

export {
	// generic
	navigationStore,
	searchStore,
	// entity-specific
	jobStore,
	logStore,
	mappingStore,
	sourceStore,
	synchronizationStore,
	webhookStore,
	endpointStore,
	consumerStore,
	importExportStore,
	eventStore,
	ruleStore,
	contractStore,
	settingsStore,
}

export { useSettingsStore }
