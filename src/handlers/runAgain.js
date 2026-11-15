// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getRouter } from './routerRef.js'

/**
 * Start a failed run's synchronization again, with no dialog.
 *
 * "Run now" opens a dialog with options; a failed pull restarted on a Monday
 * morning needs none, it is the same run again (design D3). The server records
 * the new run with `triggeredBy: rerun` and answers with its id, and the notice
 * opens the runs page on it.
 *
 * @param {{ synchronizationId?: string }} run The failed run record.
 * @return {Promise<string|null>} The new run's id, or null when nothing started.
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-failed-pull-restarts-with-one-click-req-crun-003
 */
export async function runAgain(run) {
	const synchronizationId = run && run.synchronizationId
	if (!synchronizationId) {
		showError(
			t(
				'integriq',
				'This run names no synchronization, so it cannot run again.',
			),
		)
		return null
	}

	try {
		const { data } = await axios.post(rerunUrl(synchronizationId), {
			triggeredBy: 'rerun',
		})
		return announceRerun(data)
	} catch (error) {
		showError(rerunFailureText(error))
		return null
	}
}

/**
 * The run endpoint of a synchronization.
 *
 * @param {string} synchronizationId The synchronization to run.
 * @return {string} The URL.
 */
function rerunUrl(synchronizationId) {
	return generateUrl(
		`/apps/integriq/api/synchronizations/${encodeURIComponent(synchronizationId)}/run`,
	)
}

/**
 * Tell the user the pull ran again; selecting the notice opens the new run.
 *
 * @param {object|undefined} data The run response.
 * @return {string|null} The new run's id, or null when the response names none.
 */
function announceRerun(data) {
	const runId = (data && data.runId) || null
	showSuccess(
		t('integriq', 'The pull ran again. Select this notice to open the new run.'),
		{
			onClick: () => openRun(runId),
		},
	)
	return runId
}

/**
 * The notice after a failed rerun, with the server's or the transport's reason.
 *
 * @param {object} error The rejection of the run request.
 * @return {string} The notice text.
 */
function rerunFailureText(error) {
	const reason = error?.response?.data?.error || error?.message || ''
	return t('integriq', 'The pull did not run again: {reason}', { reason })
}

/**
 * Open the runs page on one run.
 *
 * @param {string|null} runId The run to open.
 * @return {void}
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-failed-pull-restarts-with-one-click-req-crun-003
 */
export function openRun(runId) {
	const router = getRouter()
	if (!router) {
		return
	}
	// The runs page sends every plain query key to OpenRegister as a filter.
	// OpenRegister reads `uuid` as the record's own id and ignores a key the
	// schema lacks, so `?run=` opened the page on every run.
	const location = {
		name: 'SynchronizationRuns',
		query: runId ? { uuid: runId } : {},
	}
	router.push(location).catch(() => {})
}
