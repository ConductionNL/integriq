// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Row-action handlers — wired to manifest `config.actions[].handler` names.
//
// Each handler is a plain function `({ actionId, item }) => Promise<void>` that
// CnIndexPage's `resolveHandler` calls when the user clicks the matching row
// action. Behaviour is kept lean: hit the backend endpoint, show a toast on
// success/error, let the user check the corresponding *Logs page for details.
//
// The legacy chain-A pre-refactor modals (RunJob.vue, TestJob.vue,
// RunSynchronization.vue, TestSynchronization.vue, TestMapping.vue,
// TestSource.vue) showed a richer in-modal run/result panel. Those have now
// all been reborn under src/modals/v2/, and the handlers that used to POST
// on their behalf open the modal instead — see the modal-opening section
// below. What remains fire-and-forget here is only what has no result worth
// rendering.
//
// Endpoints still called directly from this file, declared in appinfo/routes.php:
//   POST /api/flows/{id}/run                — flows#run
//
// Endpoints moved into modals (this file only opens them now):
//   POST /api/sources/test/{id}             — TestSourceModal
//   POST /api/jobs/{run,test}/{id}          — RunActionModal
//   POST /api/synchronizations/{id}/{run,test} — RunActionModal

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	modalBus,
	EVENT_OPEN_TEST_MAPPING,
	EVENT_OPEN_TEST_SOURCE,
	EVENT_OPEN_ADD_ENDPOINT_RULE,
	EVENT_OPEN_RUN_ACTION,
	EVENT_OPEN_SUBSCRIPTION_SIGNING,
	EVENT_OPEN_CONFIGURATION_IMPORT,
	EVENT_OPEN_CONFIGURATION_EXPORT,
	EVENT_OPEN_PROMOTION,
} from './modalBus.js'
import { getRouter } from './routerRef.js'
import { rowId } from './rowId.js'
import { VIEW_LOGS_TARGETS, logsLocation } from './logTargets.js'

/**
 * Build the toast detail suffix from an axios error. Surfaces the server's
 * `message` when available so the user gets actionable feedback rather than
 * a bare "request failed".
 *
 * @param {unknown} err Axios error or anything throwable.
 * @return {string} Empty when nothing useful to show.
 */
function errorDetail(err) {
	const detail = err?.response?.data?.message || err?.message || ''
	return detail ? `: ${detail}` : ''
}

// Each handler keeps the t() argument a literal string so the
// translation extractor picks it up. A `makePostHandler` factory
// would lose that — at the cost of ~5 lines per handler, this stays
// extractable.

/**
 * Test a source's connection by POSTing to /api/sources/test/{id}.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export function testSourceHandler({ item }) {
	// Open the interactive Test-connection modal (method + endpoint + body input,
	// live request, full response panel) instead of the old fire-and-forget POST.
	modalBus.emit(EVENT_OPEN_TEST_SOURCE, { source: item })
}

/**
 * Trigger a flow run via POST /api/flows/{id}/run (visual-flow-orchestration
 * REQ-007d — the manual trigger surface, wired to the Flows index page's
 * row action here; the Flow detail page's own "Run" header action calls the
 * same endpoint directly).
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export async function runFlowHandler({ item }) {
	try {
		const response = await axios.post(generateUrl(`/apps/openconnector/api/flows/${rowId(item)}/run`))
		const status = response.data?.status || 'completed'
		if (status === 'failed' || status === 'stopped' || status === 'dead_letter') {
			showError(t('openconnector', 'Flow run ended with status: {status}', { status }))
			return
		}
		showSuccess(t('openconnector', 'Flow run triggered'))
	} catch (err) {
		showError(t('openconnector', 'Flow run failed') + errorDetail(err))
	}
}

// Modal-opening handlers — see src/modals/v2/ModalHost.vue. These do NOT
// hit the backend directly; they emit on the shared modalBus so the host
// component can mount the corresponding modal. The modal itself owns the
// API call (POST /api/mappings/test for #835, OR PATCH for #836).

/**
 * Open the run/test modal for a synchronization "Run now" row action.
 *
 * Was a fire-and-forget POST that discarded the returned run log for a toast.
 * The modal now owns the request, so it can gate it behind the `force` /
 * `forceDeletion` switches the endpoint accepts but nothing could set, and
 * render the object counters it returns. Name kept deliberately —
 * `testSourceHandler` set the precedent of a handler keeping its name when it
 * became a modal-opener, and the manifest/registry/spec references stay valid.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export function runSynchronizationHandler({ item }) {
	modalBus.emit(EVENT_OPEN_RUN_ACTION, { target: 'synchronization', mode: 'run', item })
}

/**
 * Open the run/test modal for a synchronization "Test (dry run)" row action.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export function testSynchronizationHandler({ item }) {
	modalBus.emit(EVENT_OPEN_RUN_ACTION, { target: 'synchronization', mode: 'test', item })
}

/**
 * Open the run/test modal for a job "Run now" row action.
 *
 * Besides exposing `forceRun`, the modal fixes an outright wrong report: when a
 * job is not yet due and force is off, `executeJob()` returns null and the
 * endpoint answers with literal JSON `null` (job-scheduling REQ-002) — and the
 * old toast still said "Job run triggered". The modal says nothing ran.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export function runJobHandler({ item }) {
	modalBus.emit(EVENT_OPEN_RUN_ACTION, { target: 'job', mode: 'run', item })
}

/**
 * Open the run/test modal for a job "Force run" row action.
 *
 * Not a dry run, despite what this handler's name and its old row-action label
 * implied: `JobsController::test()` calls the same `executeJob()` that `run()`
 * does with `forceRun` hardcoded true. The modal says so plainly and the
 * manifest label now reads "Force run (ignore schedule)".
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export function testJobHandler({ item }) {
	modalBus.emit(EVENT_OPEN_RUN_ACTION, { target: 'job', mode: 'test', item })
}

/**
 * Open the Test mapping modal for the clicked mapping row.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export function testMappingModalHandler({ item }) {
	modalBus.emit(EVENT_OPEN_TEST_MAPPING, { mapping: item })
}

/**
 * Open the Add-rule-to-endpoint modal for the clicked endpoint row.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export function addEndpointRuleHandler({ item }) {
	modalBus.emit(EVENT_OPEN_ADD_ENDPOINT_RULE, { endpoint: item })
}

/**
 * Open the webhook signing-secret manager for a subscription row.
 *
 * @param {{ item: object }} ctx Row-action context from CnIndexPage.
 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
 */
export function manageSigningHandler({ item }) {
	modalBus.emit(EVENT_OPEN_SUBSCRIPTION_SIGNING, { subscription: item })
}

/**
 * Open the configuration import dialog (connector-catalog-ui REQ-007/008):
 * upload an exported OAS document, preview the creates/updates/collisions
 * classification, acknowledge any unresolved references, then confirm.
 * Wired to the Catalog page's "Import configuration" header action.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything
 */
export function openConfigurationImportHandler() {
	modalBus.emit(EVENT_OPEN_CONFIGURATION_IMPORT, {})
}

/**
 * Open the configuration export dialog (connector-catalog-ui REQ-006):
 * pick a configuration group, download its redacted OAS document. Wired
 * to the Catalog page's "Export configuration" header action.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-006--export-a-configuration-from-the-ui
 */
export function openConfigurationExportHandler() {
	modalBus.emit(EVENT_OPEN_CONFIGURATION_EXPORT, {})
}

/**
 * Open the promote-configuration flow (environments-and-promotion): pick a
 * configuration group and a target environment, review the merged diff
 * preview (creates/updates/collisions/credentialRefsNeedingRebind), rebind
 * any flagged credentialRef placeholders, then confirm. Wired to the
 * Environments page's "Promote configuration" header action.
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003
 */
export function openPromotionHandler() {
	modalBus.emit(EVENT_OPEN_PROMOTION, {})
}

// Query-aware "View logs" navigation. See #837 + nc-vue#330.
//
// Each parent index page (Sources / Endpoints / Jobs / Synchronizations /
// CloudEvents) has a "View logs" row action that navigates to the
// corresponding *Logs page. Using `handler: "navigate"` lands on the
// UNFILTERED log list and forces the user to filter manually. This
// handler pushes the same route with `?<queryParam>=<rowId>` on the URL
// so the destination log page can pre-apply the filter.
//
// The destination route + query-param key are looked up by `actionId`
// rather than passed through manifest fields, because the nc-vue
// registry-handler signature (`{ actionId, item }`) does not forward the
// rest of the action object — adding fields like `queryParam` on the
// manifest requires nc-vue#330 to land first. Once that PR ships, this
// handler can be deleted and the manifest entries can go back to
// `handler: "navigate"` with a declarative `queryParam` field.
/**
 * Navigate from a parent index row to the corresponding logs page, scoped to
 * that parent where the log rows carry a field to scope by.
 *
 * The route + query pair comes from `logsLocation()`, the single builder the
 * run/test modal's "View full log" link also uses, so the two surfaces cannot
 * drift. A target whose `queryParam` is null resolves to the UNFILTERED page:
 * CnLogsPage applies every query entry as a property filter, so scoping on a
 * field no writer sets would land the user on an empty table.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export function viewLogsHandler({ actionId, item }) {
	if (!VIEW_LOGS_TARGETS[actionId]) {
		// eslint-disable-next-line no-console
		console.warn(`[openconnector] viewLogsHandler: unknown actionId "${actionId}"`)
		return
	}
	const router = getRouter()
	if (!router) {
		// eslint-disable-next-line no-console
		console.warn('[openconnector] viewLogsHandler: router not set; cannot navigate')
		return
	}
	const location = logsLocation(actionId, rowId(item))
	if (!location) {
		// Only reachable for a row with no id at all, and only on a SCOPED target
		// — an unfiltered one builds a location without reading the id.
		// eslint-disable-next-line no-console
		console.warn(`[openconnector] viewLogsHandler: no id on row for "${actionId}"`)
		return
	}
	router.push(location).catch((err) => {
		// vue-router throws NavigationDuplicated when pushing the same
		// route twice; swallow that specific case, surface anything else.
		if (err && err.name !== 'NavigationDuplicated') {
			// eslint-disable-next-line no-console
			console.warn('[openconnector] viewLogsHandler navigation failed', err)
		}
	})
}
