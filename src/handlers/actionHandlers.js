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

/**
 * Build a handler that POSTs to `url` and shows a toast on success/error.
 *
 * @param {(item: object) => string} buildUrl Resolves the target URL from the row.
 * @param {string} successMessage              i18n key (in the openconnector domain).
 * @param {string} errorMessage                i18n key (in the openconnector domain).
 * @return {(ctx: {actionId: string, item: object}) => Promise<void>}
 */
function makePostHandler(buildUrl, successMessage, errorMessage) {
	return async ({ item }) => {
		const url = generateUrl(buildUrl(item))
		try {
			await axios.post(url)
			showSuccess(t('openconnector', successMessage))
		} catch (err) {
			const detail = err?.response?.data?.message || err?.message || ''
			showError(t('openconnector', errorMessage) + (detail ? `: ${detail}` : ''))
		}
	}
}

export const testSourceHandler = makePostHandler(
	(item) => `/apps/openconnector/api/sources/test/${item.id || item.uuid}`,
	'Source connection test triggered',
	'Source connection test failed',
)

export const runJobHandler = makePostHandler(
	(item) => `/apps/openconnector/api/jobs/run/${item.id || item.uuid}`,
	'Job run triggered',
	'Job run failed',
)

export const testJobHandler = makePostHandler(
	(item) => `/apps/openconnector/api/jobs/test/${item.id || item.uuid}`,
	'Job test (dry run) triggered',
	'Job test failed',
)

export const runSynchronizationHandler = makePostHandler(
	(item) => `/apps/openconnector/api/synchronizations/${item.id || item.uuid}/run`,
	'Synchronization run triggered',
	'Synchronization run failed',
)

export const testSynchronizationHandler = makePostHandler(
	(item) => `/apps/openconnector/api/synchronizations/${item.id || item.uuid}/test`,
	'Synchronization test (dry run) triggered',
	'Synchronization test failed',
)
