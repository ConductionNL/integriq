// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for openconnector's manifest-driven app shell.
//
// Two flavors of entry live here, per Tier-4 manifest-v2 (ADR-024/036):
//
// 1. **Typed-primitive wrappers** (preferred) — thin shims like `LogIndex`
//    that wrap CnIndexPage / CnDetailPage from @conduction/nextcloud-vue
//    and drive store + column selection from `page.config`. Manifest pages
//    still declare `type: "custom"` (the type enum doesn't yet express
//    "custom-shim-over-primitive"), but the implementation IS the typed
//    primitive.
//
// 2. **Genuine custom views** — pages whose interaction model exceeds what
//    declarative index/detail manifest types can express today: connection
//    testing, drag-and-drop mapping editors, cron builders, visual rule
//    conditions, CloudEvent subscription management. These are flagged with
//    a `_note` on the manifest entry explaining why.
//
// Resolution order at runtime:
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components

// --- Typed-primitive wrappers (thin shims over CnIndexPage / CnDetailPage) ---
import LogIndex from './views/wrappers/LogIndex.vue'

// --- Main surfaces ---
import DashboardView from './views/dashboard/DashboardIndex.vue'
import SourcesIndex from './views/Source/SourcesIndex.vue'
import SourceLogIndex from './views/Source/SourceLogIndex.vue'
import EndpointsIndex from './views/Endpoint/EndpointsIndex.vue'
import EndpointLogIndex from './views/Endpoint/EndpointLogIndex.vue'
import ConsumersIndex from './views/Consumer/ConsumersIndex.vue'
import WebhooksIndex from './views/Webhook/WebhooksIndex.vue'
import JobsIndex from './views/Job/JobsIndex.vue'
import JobLogIndex from './views/Job/JobLogIndex.vue'
import MappingsIndex from './views/Mapping/MappingsIndex.vue'
import RuleIndex from './views/rule/RuleIndex.vue'
import SynchronizationsIndex from './views/Synchronization/SynchronizationsIndex.vue'
import ContractsIndex from './views/contracts/ContractsIndex.vue'
import SynchronizationLogIndex from './views/Synchronization/SynchronizationLogIndex.vue'
import EventIndex from './views/event/EventIndex.vue'
import EventLogIndex from './views/event/EventLogIndex.vue'
import ImportIndex from './views/Import/ImportIndex.vue'
import SettingsView from './views/settings/Settings.vue'

export default {
	// --- Generic typed-primitive wrappers ---
	// Single component, runtime-discriminated by `config.logType`. Each log
	// type added here removes one bespoke index view from the bundle.
	LogIndex,

	// --- Integration definition editors (connection testing + auth UI) ---
	DashboardView,
	SourcesIndex,
	SourceLogIndex,

	// --- API surface editors (request/response schema + live testing) ---
	EndpointsIndex,
	EndpointLogIndex,

	// --- Consumer + webhook management ---
	ConsumersIndex,
	WebhooksIndex,

	// --- Scheduled job management (cron builder + run history) ---
	JobsIndex,
	JobLogIndex,

	// --- Field-translation editor (drag-and-drop mapping builder) ---
	MappingsIndex,

	// --- Logical rule builder (condition + action expression editor) ---
	RuleIndex,

	// --- Synchronization pipeline (source/target selector + contract log) ---
	SynchronizationsIndex,
	ContractsIndex,
	SynchronizationLogIndex,

	// --- CloudEvents management (subscription + delivery log) ---
	EventIndex,
	EventLogIndex,

	// --- Bulk import wizard ---
	ImportIndex,

	// --- App settings ---
	SettingsView,
}
