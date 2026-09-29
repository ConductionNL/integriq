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
		const { data } = await axios.post(
			generateUrl(
				`/apps/integriq/api/synchronizations/${encodeURIComponent(synchronizationId)}/run`,
			),
			{ triggeredBy: 'rerun' },
		)
		const runId = (data && data.runId) || null
		showSuccess(
			t(
				'integriq',
				'The pull ran again. Select this notice to open the new run.',
			),
			{
				onClick: () => openRun(runId),
			},
		)
		return runId
	} catch (error) {
		const reason = error?.response?.data?.error || error?.message || ''
		showError(t('integriq', 'The pull did not run again: {reason}', { reason }))
		return null
	}
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
	const location = {
		name: 'SynchronizationRuns',
		query: runId ? { run: runId } : {},
	}
	router.push(location).catch(() => {})
}
