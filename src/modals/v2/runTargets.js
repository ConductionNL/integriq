// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Run-action descriptors — everything RunActionModal.vue needs to know about a
// specific (entity, mode) pair, so the modal itself contains no knowledge of
// synchronizations or jobs. Adding a third runnable entity means adding a
// descriptor here and nothing else.
//
// A descriptor owns four concerns:
//
//   1. Chrome        — title, optional intro, run-button label.
//   2. Options       — the switches shown before the run fires, incl. their
//                      explanatory note-cards and when they must be disabled.
//   3. Request       — which URL and body to POST, resolved at fire time from
//                      the switch values.
//   4. Result        — how to turn the returned payload into a status banner and
//                      a list of render sections, plus where "View full log"
//                      goes.
//
// Why `request()` may re-route: `synchronization.test` / `synchronization.run`
// and `job.test` / `job.run` are four SEPARATE actionAuth actions
// (SynchronizationsController::test/run and JobsController::test/run each call
// `requireAction()` with their own name). Turning on "Test mode" inside the Run
// modal therefore switches to the /test endpoint rather than POSTing
// `{ test: true }` to /run — otherwise a user granted test-but-not-run gets a
// 403 for an operation they are allowed to perform.
//
// Why sections are data, not components: the sync counter grid and the job stack
// trace are different enough to want separate markup but far too small to want
// separate SFCs. `sections()` returns `{ title, kind, value }` records and the
// modal renders each `kind` generically.

import { translate as t } from '@nextcloud/l10n'
// Same table the "View logs" row action resolves against — see logTargets.js.
import { logsLocation } from '../../handlers/logTargets.js'
// Shared with handlers/actionHandlers.js — the two copies of this had already
// drifted apart. See handlers/rowId.js.
import { rowId } from '../../handlers/rowId.js'

/**
 * Count the real entries of a uuid list.
 *
 * `SynchronizationService` pushes `$contractUuid` / `$logUuid` unconditionally
 * and both stay null when the contract or log carries no uuid. Since
 * `SynchronizationLogService::normaliseResultReferences()` those blanks are
 * compacted out of both the persisted row and the response, so a current
 * payload holds no gaps. The filter stays because this modal also renders
 * run-logs written before that change, which are still sparse on disk.
 *
 * @param {Array|undefined} list The uuid list from `result.contracts` / `result.logs`.
 * @return {number} How many real uuids it holds.
 */
function countUuids(list) {
	if (Array.isArray(list) === false) {
		return 0
	}

	return list.filter(
		(entry) => entry !== null && entry !== undefined && entry !== '',
	).length
}

/**
 * Format a millisecond duration for display.
 *
 * @param {number|null|undefined} ms The duration.
 * @return {string} Formatted duration, or an em dash when unknown.
 */
function formatMs(ms) {
	if (ms === null || ms === undefined || ms === '') {
		return '—'
	}

	return `${Math.round(Number(ms))} ${t('integriq', 'ms')}`
}

/**
 * Render a boolean as a localised Yes/No.
 *
 * @param {boolean} value The flag.
 * @return {string} Localised label.
 */
function yesNo(value) {
	if (value === true) {
		return t('integriq', 'Yes')
	}

	return t('integriq', 'No')
}

/**
 * The six object counters a synchronization run reports, in the order the
 * engine itself initialises them (SynchronizationService::synchronize()).
 *
 * Labels are literal `t()` calls rather than a mapped list so the l10n
 * extractor can see them — same constraint as the row-action handlers.
 *
 * @param {object} objects The `result.objects` sub-object.
 * @return {Array<{label: string, value: number}>} Counter cells.
 */
function objectCounters(objects) {
	const counts = objects || {}

	const labels = [
		['found', t('integriq', 'Found')],
		['skipped', t('integriq', 'Skipped')],
		['created', t('integriq', 'Created')],
		['updated', t('integriq', 'Updated')],
		['deleted', t('integriq', 'Deleted')],
		['invalid', t('integriq', 'Invalid')],
	]

	return labels.map(([key, label]) => ({ label, value: counts[key] ?? 0 }))
}

/**
 * The `force` switch, shared by both synchronization descriptors.
 *
 * @return {object} An options entry.
 */
function forceOption() {
	return {
		key: 'force',
		label: t('integriq', 'Force'),
		note: t(
			'integriq',
			'Update the contract even when the engine detects no change. Useful after editing a mapping, when the source data itself is unchanged.',
		),
		noteType: 'info',
	}
}

/**
 * Status derivation for a synchronization run log, shared by both modes.
 *
 * The engine sets `message` to 'Success' on a clean finish and to
 * 'pending_approval' when a HITL gate short-circuited the run before any writes
 * (synchronization-engine REQ-015) — that is neither a success nor a failure and
 * must not be reported as either.
 *
 * @param {object|null} payload The returned run log.
 * @return {{type: string, text: string}} Banner type and text.
 */
function synchronizationStatus(payload) {
	if (payload === null || payload === undefined) {
		return {
			type: 'warning',
			text: t('integriq', 'The run returned no result.'),
		}
	}

	return synchronizationMessageStatus(payload.message || '')
}

/**
 * The banner for a run-log message: a known outcome, or the message itself as
 * an error.
 *
 * @param {string} message The run-log message.
 * @return {{type: string, text: string}} Banner type and text.
 */
function synchronizationMessageStatus(message) {
	const known = synchronizationOutcomes()
	if (Object.hasOwn(known, message)) {
		return known[message]
	}

	return {
		type: 'error',
		text: message || t('integriq', 'The synchronization did not complete.'),
	}
}

/**
 * The banners for the run-log messages that are not a failure, keyed by message.
 *
 * @return {{[message: string]: {type: string, text: string}}} Banner per message.
 */
function synchronizationOutcomes() {
	return {
		pending_approval: {
			type: 'warning',
			text: t(
				'integriq',
				'The run is waiting for approval and made no changes. Approve the request to let it continue.',
			),
		},
		Success: {
			type: 'success',
			text: t('integriq', 'The synchronization completed successfully.'),
		},
	}
}

/**
 * Explain a tripped deletion guard in the user's terms.
 *
 * `result.objects.deletionGuard` is `{ guarded, reason, ratio, threshold,
 * candidateCount, totalContracts }` — null when the cleanup pass never ran (a
 * dry run skips it entirely), `guarded: false` when it ran unimpeded.
 *
 * @param {object|null} guard The deletionGuard sub-object.
 * @return {{text: string, rows: Array<object>}|null} Explanation, or null when nothing was guarded.
 */
function deletionGuardNote(guard) {
	if (!guard || guard.guarded !== true) {
		return null
	}

	const rows =
		guard.reason === 'ratio_threshold_exceeded' ? ratioGuardRows(guard) : []

	return { text: deletionGuardText(guard.reason), rows }
}

/**
 * The explanation for one deletion-guard reason.
 *
 * @param {string|undefined} reason The `deletionGuard.reason` value.
 * @return {string} Localised explanation.
 */
function deletionGuardText(reason) {
	const texts = {
		incremental_mode: t(
			'integriq',
			'No objects were deleted: deletion detection is switched off entirely while this synchronization is in incremental mode. Force deletion cannot override that — set the sync mode to full first.',
		),
		fetch_incomplete: t(
			'integriq',
			'No objects were deleted: the fetch from the source did not complete, so the engine could not tell a genuinely removed record from one it simply never saw.',
		),
		ratio_threshold_exceeded: t(
			'integriq',
			'No objects were deleted: the run would have removed an unusually large share of the existing objects, so the deletion-ratio guard stopped it. Check the numbers below — if the deletions are correct, run again with force deletion.',
		),
	}
	if (Object.hasOwn(texts, reason)) {
		return texts[reason]
	}

	return t(
		'integriq',
		'No objects were deleted: a guard stopped the cleanup pass.',
	)
}

/**
 * Format a 0..1 ratio as a percentage with one decimal.
 *
 * @param {number|string} ratio The ratio.
 * @return {string} The percentage.
 */
function percent(ratio) {
	return `${Math.round(Number(ratio) * 1000) / 10}%`
}

/**
 * The numbers behind a tripped deletion-ratio guard, one row per known value.
 *
 * @param {object} guard The deletionGuard sub-object.
 * @return {Array<{label: string, value: string}>} Rows for the values that are set.
 */
function ratioGuardRows(guard) {
	const rows = [
		[
			'candidateCount',
			t('integriq', 'Would have deleted'),
			() => `${guard.candidateCount} / ${guard.totalContracts ?? '?'}`,
		],
		[
			'ratio',
			t('integriq', 'Share of existing objects'),
			() => percent(guard.ratio),
		],
		[
			'threshold',
			t('integriq', 'Guard threshold'),
			() => percent(guard.threshold),
		],
	]

	return rows
		.filter(([key]) => guard[key] !== null && guard[key] !== undefined)
		.map(([, label, value]) => ({ label, value: value() }))
}

/**
 * Section list for a synchronization run log, shared by both modes.
 *
 * @param {object|null} payload The returned run log.
 * @return {Array<object>} Render sections.
 */
function synchronizationSections(payload) {
	if (payload === null || payload === undefined) {
		return []
	}

	const result = payload.result || {}
	const guard = deletionGuardNote(result.objects?.deletionGuard)

	// Ahead of the counters: `deleted: 0` next to a large `found` looks like a
	// clean no-op, and the reason it is not one is the whole point.
	const sections = guard === null ? [] : [deletionGuardSection(guard)]

	return sections.concat([
		{
			id: 'objects',
			title: t('integriq', 'Objects'),
			kind: 'counters',
			value: objectCounters(result.objects),
		},
		{
			id: 'run',
			title: t('integriq', 'Run'),
			kind: 'meta',
			value: synchronizationRunMeta(payload),
		},
	])
}

/**
 * The note section for a tripped deletion guard.
 *
 * @param {{text: string, rows: Array<object>}} guard The guard explanation.
 * @return {object} A render section.
 */
function deletionGuardSection(guard) {
	return {
		id: 'deletionGuard',
		title: t('integriq', 'Deletion guarded'),
		kind: 'note',
		noteType: 'warning',
		value: guard.text,
		rows: guard.rows,
	}
}

/**
 * The meta rows of a synchronization run log.
 *
 * @param {object} payload The returned run log.
 * @return {Array<{label: string, value: string}>} Meta rows.
 */
function synchronizationRunMeta(payload) {
	const result = payload.result || {}

	return [
		{
			label: t('integriq', 'Execution time'),
			value: formatMs(payload.executionTime),
		},
		{ label: t('integriq', 'Direction'), value: result.type || '—' },
		{ label: t('integriq', 'Test mode'), value: yesNo(payload.test === true) },
		{ label: t('integriq', 'Forced'), value: yesNo(payload.force === true) },
		{
			label: t('integriq', 'Contracts'),
			value: String(countUuids(result.contracts)),
		},
		{
			label: t('integriq', 'Contract logs'),
			value: String(countUuids(result.logs)),
		},
	]
}

/**
 * Where "View full log" goes for a synchronization.
 *
 * Built from the same `VIEW_LOGS_TARGETS` entry the "View logs" row action
 * resolves, so the modal link and the row action cannot drift onto different
 * pages. Test runs are persisted too — `synchronize()` finalises the log via
 * `SynchronizationLogService::update()` before returning regardless of `isTest`
 * — so this link is valid for a dry run as well.
 *
 * @param {object} item The synchronization row.
 * @return {object|null} A router location, or null without an id.
 */
function synchronizationLogsLink(item) {
	return logsLocation('view-synchronization-logs', rowId(item))
}

/**
 * Status derivation for a job log, shared by both modes.
 *
 * A literal `null` body is the documented "not due and not forced" outcome
 * (job-scheduling REQ-002: *"the response body is literal null"*). The old toast
 * reported that as a successful trigger; it is the one case this modal most
 * needs to get right.
 *
 * @param {object|null} payload The returned job log.
 * @return {{type: string, text: string}} Banner type and text.
 */
function jobStatus(payload) {
	if (payload === null || payload === undefined) {
		return {
			type: 'warning',
			text: t(
				'integriq',
				'Nothing was executed: the job is not due to run yet and force run was off.',
			),
		}
	}

	const [type, fallback] = jobLevelOutcome(payload.level)

	return { type, text: payload.message || fallback }
}

/**
 * The banner type and fallback text for a job-log level, case-insensitive.
 *
 * @param {string|undefined} level The job-log level.
 * @return {Array<string>} The banner type and the text used when the log has no message.
 */
function jobLevelOutcome(level) {
	const key = String(level || '').toUpperCase()
	const levels = {
		ERROR: ['error', t('integriq', 'The job failed.')],
		WARNING: ['warning', t('integriq', 'The job finished with a warning.')],
	}

	return Object.hasOwn(levels, key)
		? levels[key]
		: ['success', t('integriq', 'The job ran successfully.')]
}

/**
 * Section list for a job log, shared by both modes.
 *
 * `stackTrace` is stored as a `frame_0…frame_n` keyed object rather than a list
 * — JobService::saveJobLog() re-keys it that way so it passes the job_log
 * schema's 'object or null' type — so it is flattened back into an ordered list
 * for display.
 *
 * @param {object|null} payload The returned job log.
 * @return {Array<object>} Render sections.
 */
function jobSections(payload) {
	if (payload === null || payload === undefined) {
		return []
	}

	const sections = [
		{
			id: 'run',
			title: t('integriq', 'Run'),
			kind: 'meta',
			value: jobRunMeta(payload),
		},
	]

	const frames = Object.values(payload.stackTrace || {})
	if (frames.length > 0) {
		sections.push({
			id: 'stackTrace',
			title: t('integriq', 'Stack trace'),
			kind: 'list',
			value: frames.map((frame) => String(frame)),
		})
	}

	return sections
}

/**
 * The meta rows of a job log.
 *
 * @param {object} payload The returned job log.
 * @return {Array<{label: string, value: string}>} Meta rows.
 */
function jobRunMeta(payload) {
	return [
		{ label: t('integriq', 'Level'), value: payload.level || '—' },
		{
			label: t('integriq', 'Execution time'),
			value: formatMs(payload.executionTime),
		},
		{ label: t('integriq', 'Job class'), value: payload.jobClass || '—' },
		{ label: t('integriq', 'Last run'), value: payload.lastRun || '—' },
		{ label: t('integriq', 'Next run'), value: payload.nextRun || '—' },
	]
}

/**
 * Where "View full log" goes for a job.
 *
 * @param {object} item The job row.
 * @return {object|null} A router location, or null without an id.
 */
function jobLogsLink(item) {
	return logsLocation('view-job-logs', rowId(item))
}

/**
 * How a synchronization run's result is read, shared by both modes.
 *
 * @return {object} The status, sections and logsLink fields of a descriptor.
 */
function synchronizationResult() {
	return {
		status: synchronizationStatus,
		sections: synchronizationSections,
		logsLink: synchronizationLogsLink,
	}
}

/**
 * How a job run's result is read, shared by both modes.
 *
 * @return {object} The status, sections and logsLink fields of a descriptor.
 */
function jobResult() {
	return {
		status: jobStatus,
		sections: jobSections,
		logsLink: jobLogsLink,
	}
}

/**
 * The `test` switch of the synchronization Run modal.
 *
 * @return {object} An options entry.
 */
function testModeOption() {
	return {
		key: 'test',
		label: t('integriq', 'Test mode'),
		note: t(
			'integriq',
			'Run every step of the synchronization without saving anything: no contract is written and the target system is left untouched. Use it to verify a mapping or condition before a real run.',
		),
		noteType: 'info',
	}
}

/**
 * The `forceDeletion` switch of the synchronization Run modal.
 *
 * @return {object} An options entry.
 */
function forceDeletionOption() {
	return {
		key: 'forceDeletion',
		label: t('integriq', 'Force deletion'),
		note: t(
			'integriq',
			'Override the deletion-ratio guard, which aborts a cleanup pass that would delete an unusually large share of the existing objects. Only use this when you already know the deletions are correct.',
		),
		noteType: 'warning',
		// REQ-018 hard-blocks deletion while syncMode is 'incremental', and
		// forceDeletion explicitly cannot override that check — it only
		// overrides the ratio guard. Absent syncMode means 'full' (the
		// engine's own default), so only an explicit 'incremental' disables.
		disabledWhen: (item) => item?.syncMode === 'incremental',
		disabledNote: t(
			'integriq',
			'Not available while this synchronization is in incremental mode: deletion detection is switched off entirely and force deletion cannot override that. Set the sync mode to full first.',
		),
		// Run-only: the controller documents forceDeletion as not applicable
		// to test runs, which make no deletions to guard in the first place.
		hiddenWhen: (values) => values?.test === true,
	}
}

/**
 * The request of the synchronization Run modal.
 *
 * @param {object} item   The synchronization row.
 * @param {object} values The switch values.
 * @return {{url: string, body: object}} The request to POST.
 */
function synchronizationRunRequest(item, values) {
	const id = rowId(item)

	// Re-route rather than pass `test: true` — see the module docblock.
	if (values.test === true) {
		return {
			url: `/apps/integriq/api/synchronizations/${id}/test`,
			body: { force: values.force === true },
		}
	}

	return {
		url: `/apps/integriq/api/synchronizations/${id}/run`,
		body: {
			test: false,
			force: values.force === true,
			forceDeletion: values.forceDeletion === true,
		},
	}
}

/**
 * The retry offer of the synchronization Run modal.
 *
 * The one case where the run tells us exactly which switch would have changed
 * the outcome, so the modal can offer it directly rather than making the user
 * work out that `forceDeletion` is what the guard message is referring to.
 *
 * @param {object|null} payload The returned run log.
 * @return {{label: string, values: object}|null} The retry offer, or null.
 */
function forceDeletionRetry(payload) {
	const guard = payload?.result?.objects?.deletionGuard || null
	if (guard?.guarded !== true || guard.reason !== 'ratio_threshold_exceeded') {
		return null
	}

	return {
		label: t('integriq', 'Run again with force deletion'),
		values: { forceDeletion: true },
	}
}

/**
 * The `synchronization/run` descriptor.
 *
 * @return {object} The descriptor.
 */
function synchronizationRunDescriptor() {
	return {
		title: t('integriq', 'Run synchronization'),
		runLabel: t('integriq', 'Run'),
		options: [testModeOption(), forceOption(), forceDeletionOption()],
		request: synchronizationRunRequest,
		...synchronizationResult(),
		retry: forceDeletionRetry,
	}
}

/**
 * The `synchronization/test` descriptor.
 *
 * @return {object} The descriptor.
 */
function synchronizationTestDescriptor() {
	return {
		title: t('integriq', 'Test synchronization (dry run)'),
		runLabel: t('integriq', 'Run test'),
		intro: t(
			'integriq',
			'A dry run executes all the synchronization logic but saves nothing: no contract is written and the target system is not touched.',
		),
		options: [forceOption()],
		request: (item, values) => ({
			url: `/apps/integriq/api/synchronizations/${rowId(item)}/test`,
			body: { force: values.force === true },
		}),
		...synchronizationResult(),
	}
}

/**
 * The `job/run` descriptor.
 *
 * @return {object} The descriptor.
 */
function jobRunDescriptor() {
	const forceRun = {
		key: 'forceRun',
		label: t('integriq', 'Force run'),
		note: t(
			'integriq',
			'Ignore both the schedule and the enabled flag, and execute the job right now. Without it, a job that is disabled or not yet due does nothing at all.',
		),
		noteType: 'info',
	}

	return {
		title: t('integriq', 'Run job'),
		runLabel: t('integriq', 'Run'),
		options: [forceRun],
		request: (item, values) => ({
			url: `/apps/integriq/api/jobs/run/${rowId(item)}`,
			body: { forceRun: values.forceRun === true },
		}),
		...jobResult(),
	}
}

/**
 * The `job/test` descriptor.
 *
 * Deliberately NOT called a dry run. JobsController::test() calls the same
 * executeJob() that run() does with forceRun hardcoded to true
 * (job-scheduling REQ-002: *"MUST ALWAYS pass forceRun: true"*) — the job
 * executes for real. There is no dry-run mode for jobs anywhere in the
 * engine, so neither this modal nor the row-action label may claim one.
 *
 * @return {object} The descriptor.
 */
function jobTestDescriptor() {
	const forceRun = {
		key: 'forceRun',
		label: t('integriq', 'Force run'),
		note: t(
			'integriq',
			'Always on for this action: the endpoint ignores the schedule and the enabled flag by definition.',
		),
		noteType: 'info',
		locked: true,
	}

	return {
		title: t('integriq', 'Force run job'),
		runLabel: t('integriq', 'Force run'),
		intro: t(
			'integriq',
			'This executes the job for real — it is not a dry run. The only difference from a normal run is that the schedule and the enabled flag are ignored.',
		),
		options: [forceRun],
		request: (item) => ({
			url: `/apps/integriq/api/jobs/test/${rowId(item)}`,
			body: {},
		}),
		...jobResult(),
	}
}

/**
 * Build the descriptor table, keyed `<target>/<mode>`.
 *
 * Built on demand rather than held as a module constant so every `t()` call runs
 * after the l10n bundle has loaded — a module-level table would freeze whatever
 * translations existed at import time, which for a module reachable from the app
 * shell is "none".
 *
 * @return {{[key: string]: object}} The descriptor table.
 */
function buildDescriptors() {
	return {
		'synchronization/run': synchronizationRunDescriptor(),
		'synchronization/test': synchronizationTestDescriptor(),
		'job/run': jobRunDescriptor(),
		'job/test': jobTestDescriptor(),
	}
}

/**
 * Look up the descriptor for a run-action bus payload.
 *
 * @param {string} target The entity, `synchronization` or `job`.
 * @param {string} mode   The action, `run` or `test`.
 * @return {object|null} The descriptor, or null when the pair is unknown.
 */
export function getRunDescriptor(target, mode) {
	return buildDescriptors()[`${target}/${mode}`] || null
}

/**
 * Seed the switch values for a descriptor: every key false, except the locked
 * ones which are on by definition.
 *
 * @param {object|null} descriptor The descriptor to seed for.
 * @return {{[key: string]: boolean}} Initial switch values.
 */
export function initialOptionValues(descriptor) {
	const values = {}

	for (const option of descriptor?.options || []) {
		values[option.key] = option.locked === true
	}

	return values
}

/**
 * The options a descriptor should currently render, after applying each option's
 * `hiddenWhen` against the live switch values.
 *
 * @param {object|null} descriptor The descriptor.
 * @param {object}      values     The current switch values.
 * @return {Array<object>} The visible options.
 */
export function visibleOptions(descriptor, values) {
	return (descriptor?.options || []).filter((option) => {
		if (typeof option.hiddenWhen !== 'function') {
			return true
		}

		return option.hiddenWhen(values) === false
	})
}

export { countUuids, formatMs, rowId }
