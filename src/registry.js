// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component + custom-handler registry for openconnector's
// manifest-driven app shell.
//
// Post chain-C cutover + post chain-E custom-Import drop: the page-
// component registry is empty (all 23 manifest pages use a standard
// nc-vue type — CnIndexPage / CnDetailPage / CnLogsPage /
// CnDashboardPage / CnSettingsPage — and resolve their CRUD against
// OR's `/api/objects/openconnector/{schema}/*` routes). Bulk import is
// now per-schema via CnIndexPage's built-in CnMassImportDialog action
// (Actions → Import on each index page) — the chain-A custom Import
// page was deleted because its backend `POST /api/import` endpoint had
// already been removed in chain-C, leaving the Vue page broken anyway.
//
// What lives here today:
//   1. Row-action handlers — small function entries called by
//      CnIndexPage when the user clicks a row Actions item whose
//      manifest `handler:` field matches one of the names below.
//      See src/handlers/actionHandlers.js for the implementations.
//   2. (future) Custom **widgets** for the genuinely-custom interactive
//      surfaces: MappingEditorWidget (drag-drop transformation),
//      RuleConditionsWidget (visual condition builder), CronBuilderWidget
//      (job-interval editor), EventSubscriptionsManagerWidget,
//      SourceTesterWidget (connection-test result panel), JobRunnerWidget
//      (run-job modal poll). These will register as v2 widget slots on
//      standard-type pages, NOT as entire custom pages.
//
// Resolution order at runtime:
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …) — WIN for 23 pages
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) — escape hatch for handlers + future widgets

import {
	testSourceHandler,
	runJobHandler,
	testJobHandler,
	runSynchronizationHandler,
	testSynchronizationHandler,
	testMappingModalHandler,
	addEndpointRuleHandler,
} from './handlers/actionHandlers.js'
import JobFormFields from './modals/v2/JobFormFields.vue'
import MappingDetailPage from './views/wrappers/MappingDetailPage.vue'
import RuleDetailPage from './views/Rule/RuleDetailPage.vue'
import SynchronizationDetailPage from './views/Synchronization/SynchronizationDetailPage.vue'

export default {
	// Row-action handlers — referenced by manifest `config.actions[].handler` strings.
	testSourceHandler,
	runJobHandler,
	testJobHandler,
	runSynchronizationHandler,
	testSynchronizationHandler,
	// Modal-opening row-action handlers — emit on the shared modal bus,
	// the App.vue-mounted ModalHost picks up and renders the modal.
	testMappingModalHandler,
	addEndpointRuleHandler,

	// Slot-override components — referenced by manifest `pages[].slots`
	// keys. The Jobs page wires `form-fields` to JobFormFields so the
	// CnFormDialog inner content renders a Synchronization picker when
	// `jobClass === OCA\OpenConnector\Action\SynchronizationAction`
	// (per #847). Open follow-up upstream: CnFormDialog has no native
	// per-field `condition`/`visibleWhen` prop — tracked as a ncv issue.
	JobFormFields,

	// Custom-page components — referenced by manifest `pages[].component`
	// when `pages[].type === 'custom'`. The 3 bespoke editors below
	// (Mapping #832, Rule #833, Synchronization #834) wrap CnDetailPage
	// with surfaces too rich for schema-driven detail pages — see each
	// component's JSDoc for the per-section rationale.
	MappingDetailPage,
	RuleDetailPage,
	SynchronizationDetailPage,
}
