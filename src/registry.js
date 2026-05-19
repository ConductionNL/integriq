// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for openconnector's manifest-driven app shell.
//
// Every entry here is the "escape hatch" — pages that don't fit one of the
// manifest's built-in types/widgets. OpenConnector's surfaces are heavily
// interactive (connection testing, drag-and-drop mapping editors, cron
// builders, visual rule conditions, CloudEvent subscription management)
// which exceed what declarative index/detail manifest types can express
// today. ALL entries below are therefore genuine exceptions rather than
// deferred migrations.
//
// Resolution order at runtime:
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See:
//   - src/manifest.json for the `_note` on each page entry explaining why
//     custom type was chosen over a built-in type.

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
