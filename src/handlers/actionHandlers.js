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
// TestSource.vue) showed a richer in-modal run/result panel. That UX is
// preserved in git history under src/modals/ for the future bespoke
// extraction PR series (see src/modals/README.md); this thin handler set
// restores the basic ability to trigger those backend actions from the UI
// while the richer modals are reborn.
//
// All endpoints are stable post chain-C and declared in appinfo/routes.php:
//   POST /api/sources/test/{id}             — sources#test
//   POST /api/jobs/run/{id}                 — jobs#run
//   POST /api/jobs/test/{id}                — jobs#test
//   POST /api/synchronizations/{id}/run     — synchronizations#run
//   POST /api/synchronizations/{id}/test    — synchronizations#test

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	modalBus,
	EVENT_OPEN_TEST_MAPPING,
	EVENT_OPEN_ADD_ENDPOINT_RULE,
	EVENT_OPEN_SUBSCRIPTION_SIGNING,
	EVENT_OPEN_CONFIGURATION_IMPORT,
	EVENT_OPEN_CONFIGURATION_EXPORT,
	EVENT_OPEN_PROMOTION,
} from './modalBus.js'
import { getRouter } from './routerRef.js'

/**
 * Extract a stable id from a row. OR returns rows with `id` set; legacy
 * call-sites sometimes only carry `uuid`. Prefer `id`, fall back to `uuid`.
 *
 * @param {object} item Row payload from the index page.
 * @return {string|number}
 */
function rowId(item) {
	return item.id || item.uuid
}

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
export async function testSourceHandler({ item }) {
	try {
		await axios.post(generateUrl(`/apps/openconnector/api/sources/test/${rowId(item)}`))
		showSuccess(t('openconnector', 'Source connection test triggered'))
	} catch (err) {
		showError(t('openconnector', 'Source connection test failed') + errorDetail(err))
	}
}

/**
 * Trigger a job to run now via POST /api/jobs/run/{id}.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export async function runJobHandler({ item }) {
	try {
		await axios.post(generateUrl(`/apps/openconnector/api/jobs/run/${rowId(item)}`))
		showSuccess(t('openconnector', 'Job run triggered'))
	} catch (err) {
		showError(t('openconnector', 'Job run failed') + errorDetail(err))
	}
}

/**
 * Test a job (dry run) via POST /api/jobs/test/{id}.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export async function testJobHandler({ item }) {
	try {
		await axios.post(generateUrl(`/apps/openconnector/api/jobs/test/${rowId(item)}`))
		showSuccess(t('openconnector', 'Job test (dry run) triggered'))
	} catch (err) {
		showError(t('openconnector', 'Job test failed') + errorDetail(err))
	}
}

/**
 * Trigger a synchronization run via POST /api/synchronizations/{id}/run.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export async function runSynchronizationHandler({ item }) {
	try {
		await axios.post(generateUrl(`/apps/openconnector/api/synchronizations/${rowId(item)}/run`))
		showSuccess(t('openconnector', 'Synchronization run triggered'))
	} catch (err) {
		showError(t('openconnector', 'Synchronization run failed') + errorDetail(err))
	}
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

/**
 * Test a synchronization (dry run) via POST /api/synchronizations/{id}/test.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export async function testSynchronizationHandler({ item }) {
	try {
		await axios.post(generateUrl(`/apps/openconnector/api/synchronizations/${rowId(item)}/test`))
		showSuccess(t('openconnector', 'Synchronization test (dry run) triggered'))
	} catch (err) {
		showError(t('openconnector', 'Synchronization test failed') + errorDetail(err))
	}
}

// Modal-opening handlers — see src/modals/v2/ModalHost.vue. These do NOT
// hit the backend directly; they emit on the shared modalBus so the host
// component can mount the corresponding modal. The modal itself owns the
// API call (POST /api/mappings/test for #835, OR PATCH for #836).

/**
 * Open the Test mapping modal for the clicked mapping row.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export function testMappingModalHandler({ item }) {
	modalBus.$emit(EVENT_OPEN_TEST_MAPPING, { mapping: item })
}

/**
 * Open the Add-rule-to-endpoint modal for the clicked endpoint row.
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export function addEndpointRuleHandler({ item }) {
	modalBus.$emit(EVENT_OPEN_ADD_ENDPOINT_RULE, { endpoint: item })
}

/**
 * Open the webhook signing-secret manager for a subscription row.
 *
 * @param {{ item: object }} ctx Row-action context from CnIndexPage.
 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-5
 */
export function manageSigningHandler({ item }) {
	modalBus.$emit(EVENT_OPEN_SUBSCRIPTION_SIGNING, { subscription: item })
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
	modalBus.$emit(EVENT_OPEN_CONFIGURATION_IMPORT, {})
}

/**
 * Open the configuration export dialog (connector-catalog-ui REQ-006):
 * pick a configuration group, download its redacted OAS document. Wired
 * to the Catalog page's "Export configuration" header action.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-006--export-a-configuration-from-the-ui
 */
export function openConfigurationExportHandler() {
	modalBus.$emit(EVENT_OPEN_CONFIGURATION_EXPORT, {})
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
	modalBus.$emit(EVENT_OPEN_PROMOTION, {})
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
const VIEW_LOGS_TARGETS = {
	'view-source-logs': { route: 'SourceLogs', queryParam: 'source' },
	'view-endpoint-logs': { route: 'EndpointLogs', queryParam: 'endpoint' },
	'view-job-logs': { route: 'JobLogs', queryParam: 'job' },
	'view-synchronization-logs': { route: 'SynchronizationLogs', queryParam: 'synchronization' },
	'view-cloud-event-logs': { route: 'CloudEventLogs', queryParam: 'event' },
}

/**
 * Navigate from a parent index row to the corresponding logs page with
 * the parent id pre-filled as a URL query param.
 *
 * Resolves the destination route + query-param key from
 * `VIEW_LOGS_TARGETS` keyed by `actionId`. Falls back to the unfiltered
 * route when the action id is unknown (defensive — keeps the existing
 * "go to logs" UX rather than dead-clicking).
 *
 * @param {{ actionId: string, item: object }} ctx Row-action context from CnIndexPage.
 */
export function viewLogsHandler({ actionId, item }) {
	const target = VIEW_LOGS_TARGETS[actionId]
	if (!target) {
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
	router.push({
		name: target.route,
		query: { [target.queryParam]: rowId(item) },
	}).catch((err) => {
		// vue-router throws NavigationDuplicated when pushing the same
		// route twice; swallow that specific case, surface anything else.
		if (err && err.name !== 'NavigationDuplicated') {
			// eslint-disable-next-line no-console
			console.warn('[openconnector] viewLogsHandler navigation failed', err)
		}
	})
}

/**
 * Create a blank mapping and open it in the bespoke MappingDetail editor.
 *
 * Wired to the Mappings index Add button (`@add`) via the MappingsPageRenderer
 * wrapper in main.js. Per the "route to detail page" decision, the Add button
 * no longer opens the simple name/description form dialog: it POSTs a draft
 * mapping to the OpenRegister objects API, then routes to the rich 3-tab
 * editor so a new mapping is configured in the same place an existing one is.
 *
 * @return {Promise<void>}
 */
export async function createMappingAndOpen() {
	const router = getRouter()
	if (!router) {
		// eslint-disable-next-line no-console
		console.warn('[openconnector] createMappingAndOpen: router not set; cannot navigate')
		return
	}
	try {
		const url = generateUrl('/apps/openregister/api/objects/openconnector/mapping')
		const { data } = await axios.post(url, { name: t('openconnector', 'New mapping') })
		const id = data?.id || data?.uuid || data?.['@self']?.id
		if (!id) {
			showError(t('openconnector', 'Could not open the new mapping'))
			return
		}
		router.push({ name: 'MappingDetail', params: { id: String(id) } }).catch((err) => {
			if (err && err.name !== 'NavigationDuplicated') {
				showError(t('openconnector', 'Mapping created but could not be opened'))
			}
		})
	} catch (err) {
		showError(t('openconnector', 'Could not create mapping') + errorDetail(err))
	}
}
