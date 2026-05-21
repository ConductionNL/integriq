// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component + custom-handler registry for openconnector's
// manifest-driven app shell.
//
// Post chain-C cutover: the 16 per-schema index/detail/log views previously
// registered here have been deleted in favour of nc-vue's built-in
// CnIndexPage / CnDetailPage / CnLogsPage / CnDashboardPage / CnSettingsPage
// types, configured declaratively via src/manifest.json. The 23 of 24
// manifest entries now use a standard type and resolve their CRUD against
// OR's `/api/objects/openconnector/{schema}/*` routes.
//
// What's left here:
//   1. ImportIndex — the multi-step file-upload + dry-run preview UX
//      exceeds nc-vue v2 form/wizard capability; revisit when CnWizardPage
//      ships. The Import manifest page carries `type: "custom"` + `_note`
//      documenting this gap.
//      (To be removed once #829 lands; the chain-C `POST /api/import`
//      backend endpoint was already deleted, so the page is broken anyway.)
//   2. Row-action handlers — small function entries called by
//      CnIndexPage when the user clicks a row Actions item whose
//      manifest `handler:` field matches one of the names below.
//      See src/handlers/actionHandlers.js for the implementations.
//   3. (future) Custom **widgets** for the genuinely-custom interactive
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
//   3. customComponents (this file) — escape hatch for Import + handlers + future widgets

import ImportIndex from './views/Import/ImportIndex.vue'
import {
	testSourceHandler,
	runJobHandler,
	testJobHandler,
	runSynchronizationHandler,
	testSynchronizationHandler,
} from './handlers/actionHandlers.js'

export default {
	// Multi-step file-upload + dry-run preview UX (manifest page id: "Import"; type: "custom").
	ImportIndex,
	// Row-action handlers — referenced by manifest `config.actions[].handler` strings.
	testSourceHandler,
	runJobHandler,
	testJobHandler,
	runSynchronizationHandler,
	testSynchronizationHandler,
}
