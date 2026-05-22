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
} from './modalBus.js'

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
