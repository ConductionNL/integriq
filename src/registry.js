// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for openconnector's manifest-driven app shell.
//
// Post chain-C cutover + post chain-E custom-Import drop: the registry is
// now empty. All 23 of 23 manifest pages use a standard nc-vue type
// (CnIndexPage / CnDetailPage / CnLogsPage / CnDashboardPage / CnSettingsPage)
// and resolve their CRUD against OR's `/api/objects/openconnector/{schema}/*`
// routes. Bulk import is now per-schema via CnIndexPage's built-in
// CnMassImportDialog action (Actions → Import on each index page) —
// the chain-E custom Import page was deleted because its backend
// `POST /api/import` endpoint had already been removed in chain-C,
// leaving the Vue page broken anyway.
//
// (Future) Custom **widgets** for the genuinely-custom interactive
// surfaces — MappingEditorWidget (drag-drop transformation),
// RuleConditionsWidget (visual condition builder), CronBuilderWidget
// (job-interval editor), EventSubscriptionsManagerWidget, SourceTesterWidget
// (connection-test result panel), JobRunnerWidget (run-job modal poll) —
// will register here as v2 widget slots on standard-type pages, NOT as
// entire custom pages.

export default {}
