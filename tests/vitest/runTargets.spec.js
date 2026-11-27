/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the run-action descriptors (src/modals/v2/runTargets.js) — the
 * data behind the shared run/test modal (REQ-SHELLUI-004).
 *
 * The two behaviours worth pinning are the ones that are easy to regress and
 * expensive to get wrong:
 *
 *   1. `request()` endpoint routing. Turning on "Test mode" inside the Run modal
 *      must switch to the /test endpoint rather than POST `{ test: true }` to
 *      /run, because those are two separate actionAuth actions and a
 *      test-but-not-run user would otherwise get a 403.
 *   2. `status()` classification, especially the outcomes that are neither a
 *      plain success nor an HTTP error: a job that was not due (literal `null`
 *      body) and a synchronization halted for approval.
 *
 * @nextcloud/l10n is aliased to a deterministic stub in vitest.config.js.
 */

import { describe, expect, it } from 'vitest'
import {
	countUuids,
	getRunDescriptor,
	initialOptionValues,
	visibleOptions,
} from '../../src/modals/v2/runTargets.js'

describe('getRunDescriptor', () => {
	it.each([
		['synchronization', 'run'],
		['synchronization', 'test'],
		['job', 'run'],
		['job', 'test'],
		['flow', 'run'],
	])('resolves %s/%s', (target, mode) => {
		expect(getRunDescriptor(target, mode)).not.toBeNull()
	})

	it('returns null for an unknown pair rather than throwing', () => {
		expect(getRunDescriptor('mapping', 'run')).toBeNull()
		expect(getRunDescriptor('job', 'explode')).toBeNull()
	})
})

describe('synchronization/run — request routing', () => {
	const descriptor = getRunDescriptor('synchronization', 'run')

	it('posts to /run with the force flags when test mode is off', () => {
		const { url, body } = descriptor.request(
			{ id: 9 },
			{ test: false, force: true, forceDeletion: true },
		)
		expect(url).toBe('/apps/integriq/api/synchronizations/9/run')
		expect(body).toEqual({ test: false, force: true, forceDeletion: true })
	})

	it('re-routes to /test when test mode is on, so the test permission applies', () => {
		const { url, body } = descriptor.request(
			{ id: 9 },
			{ test: true, force: true },
		)
		expect(url).toBe('/apps/integriq/api/synchronizations/9/test')
		// forceDeletion is not carried over — it is run-only.
		expect(body).toEqual({ force: true })
	})

	it('falls back to uuid when the row has no id', () => {
		const { url } = descriptor.request({ uuid: 'abc' }, { test: false })
		expect(url).toBe('/apps/integriq/api/synchronizations/abc/run')
	})

	it('sends explicit booleans rather than undefined for unset switches', () => {
		const { body } = descriptor.request({ id: 9 }, {})
		expect(body).toEqual({ test: false, force: false, forceDeletion: false })
	})
})

describe('synchronization/run — the force-deletion switch', () => {
	const descriptor = getRunDescriptor('synchronization', 'run')
	const forceDeletion = descriptor.options.find(
		(option) => option.key === 'forceDeletion',
	)

	it('is disabled on an incremental synchronization, where it provably does nothing', () => {
		// REQ-018 hard-blocks deletion while syncMode is incremental and
		// forceDeletion only overrides the ratio guard, not that block.
		expect(forceDeletion.disabledWhen({ syncMode: 'incremental' })).toBe(true)
	})

	it('is enabled on a full synchronization, and when syncMode is absent', () => {
		expect(forceDeletion.disabledWhen({ syncMode: 'full' })).toBe(false)
		expect(forceDeletion.disabledWhen({})).toBe(false)
	})

	it('is hidden entirely once test mode is on', () => {
		const keys = visibleOptions(descriptor, { test: true }).map(
			(option) => option.key,
		)
		expect(keys).not.toContain('forceDeletion')
		expect(
			visibleOptions(descriptor, { test: false }).map((o) => o.key),
		).toContain('forceDeletion')
	})
})

describe('job descriptors — request routing and locked force', () => {
	it('job/run passes forceRun through', () => {
		const { url, body } = getRunDescriptor('job', 'run').request(
			{ id: 3 },
			{ forceRun: true },
		)
		expect(url).toBe('/apps/integriq/api/jobs/run/3')
		expect(body).toEqual({ forceRun: true })
	})

	it('job/test hits the test endpoint, which forces the run server-side', () => {
		const { url } = getRunDescriptor('job', 'test').request({ id: 3 }, {})
		expect(url).toBe('/apps/integriq/api/jobs/test/3')
	})

	it('job/test seeds its locked force switch on', () => {
		expect(initialOptionValues(getRunDescriptor('job', 'test'))).toEqual({
			forceRun: true,
		})
	})

	it('job/run seeds its force switch off', () => {
		expect(initialOptionValues(getRunDescriptor('job', 'run'))).toEqual({
			forceRun: false,
		})
	})
})

describe('flow/run — request routing', () => {
	const descriptor = getRunDescriptor('flow', 'run')

	it('posts to the existing FlowsController::run() endpoint', () => {
		const { url, body } = descriptor.request({ id: 'f-1' }, {})
		expect(url).toBe('/apps/integriq/api/flows/f-1/run')
		expect(body).toEqual({})
	})

	it('declares no options: a manual flow run has nothing to gate', () => {
		expect(descriptor.options).toEqual([])
		expect(initialOptionValues(descriptor)).toEqual({})
	})
})

describe('flow status classification', () => {
	const { status } = getRunDescriptor('flow', 'run')

	it('reports completed as success', () => {
		expect(status({ status: 'completed' })).toEqual({
			type: 'success',
			text: 'The flow completed successfully.',
		})
	})

	it('reports suspended (mid-flight approval wait) as a warning, not a failure', () => {
		expect(status({ status: 'suspended' })).toEqual({
			type: 'warning',
			text: 'The flow is waiting on an approval step and has not finished yet.',
		})
	})

	it('reports failed/stopped/dead_letter as a generic error', () => {
		// The flow_run itself never carries an `error` field — only a
		// `flow_run_log` entry does (FlowRunnerService::appendLog()) — so there
		// is nothing on `payload` to prefer here. "View full log" is how the
		// user gets the per-step detail.
		expect(status({ status: 'failed' })).toEqual({
			type: 'error',
			text: 'The flow did not complete.',
		})
		expect(status({ status: 'stopped' })).toEqual({
			type: 'error',
			text: 'The flow did not complete.',
		})
		expect(status({ status: 'dead_letter' })).toEqual({
			type: 'error',
			text: 'The flow did not complete.',
		})
	})

	it('reports a missing payload as a warning', () => {
		expect(status(null)).toEqual({
			type: 'warning',
			text: 'The run returned no result.',
		})
	})
})

describe('flow sections', () => {
	const { sections } = getRunDescriptor('flow', 'run')

	it('renders status, trigger source, started and finished from the real flow_run fields', () => {
		const run = sections({
			status: 'completed',
			triggerSource: 'manual',
			startedAt: '2026-11-01T10:00:00+00:00',
			finishedAt: '2026-11-01T10:00:05+00:00',
		}).find((section) => section.id === 'run')

		expect(run.value).toEqual([
			{ label: 'Status', value: 'completed' },
			{ label: 'Trigger source', value: 'manual' },
			{ label: 'Started', value: '2026-11-01T10:00:00+00:00' },
			{ label: 'Finished', value: '2026-11-01T10:00:05+00:00' },
		])
	})

	it('returns nothing for a null payload', () => {
		expect(sections(null)).toEqual([])
	})
})

describe('flow payloadFromError — recovering a 500 flow_run body', () => {
	const { payloadFromError } = getRunDescriptor('flow', 'run')

	it('recovers the flow_run when the error body is one', () => {
		const err = { response: { data: { status: 'failed', triggerSource: 'manual' } } }
		expect(payloadFromError(err)).toEqual({ status: 'failed', triggerSource: 'manual' })
	})

	it('returns null for a body with no status, so the generic error message is used', () => {
		expect(payloadFromError({ response: { data: { message: 'boom' } } })).toBeNull()
		expect(payloadFromError({ response: { data: null } })).toBeNull()
		expect(payloadFromError({})).toBeNull()
	})
})

describe('job status classification', () => {
	const { status } = getRunDescriptor('job', 'run')

	it('reports a literal null body as "nothing ran", not as success', () => {
		// job-scheduling REQ-002: a job that is not due and not forced answers
		// with literal JSON null. The old toast called that a successful trigger.
		expect(status(null).type).toBe('warning')
		expect(status(null).text).toMatch(/Nothing was executed/)
	})

	it.each([
		['INFO', 'success'],
		['WARNING', 'warning'],
		['ERROR', 'error'],
	])('maps level %s to %s', (level, expected) => {
		expect(status({ level, message: 'm' }).type).toBe(expected)
	})

	it('treats an absent level as success and prefers the log message', () => {
		expect(status({ message: 'Job finished' })).toEqual({
			type: 'success',
			text: 'Job finished',
		})
	})
})

describe('synchronization status classification', () => {
	const { status } = getRunDescriptor('synchronization', 'run')

	it('maps the engine\'s "Success" sentinel to success', () => {
		expect(status({ message: 'Success' }).type).toBe('success')
	})

	it('maps a HITL-gated run to warning, not success or failure', () => {
		// REQ-015: the run short-circuited before any writes awaiting approval.
		expect(status({ message: 'pending_approval' }).type).toBe('warning')
	})

	it('surfaces any other message as an error', () => {
		expect(status({ message: 'Source unreachable' })).toEqual({
			type: 'error',
			text: 'Source unreachable',
		})
	})
})

describe('synchronization sections', () => {
	const { sections } = getRunDescriptor('synchronization', 'run')

	it('renders the six object counters in engine order', () => {
		const payload = {
			message: 'Success',
			result: {
				objects: {
					found: 120,
					skipped: 105,
					created: 4,
					updated: 11,
					deleted: 0,
					invalid: 2,
				},
			},
		}
		const counters = sections(payload).find(
			(section) => section.id === 'objects',
		)
		expect(counters.value.map((cell) => cell.value)).toEqual([
			120, 105, 4, 11, 0, 2,
		])
	})

	it('defaults missing counters to zero rather than rendering undefined', () => {
		const counters = sections({ result: {} }).find(
			(section) => section.id === 'objects',
		)
		expect(counters.value.every((cell) => cell.value === 0)).toBe(true)
	})

	it('returns nothing for a null payload', () => {
		expect(sections(null)).toEqual([])
	})
})

describe('deletion guard reporting', () => {
	const descriptor = getRunDescriptor('synchronization', 'run')

	/**
	 * Build a run-log payload carrying a deletionGuard sub-object.
	 *
	 * @param {object|null} guard The guard payload.
	 * @return {object} A run log.
	 */
	function withGuard(guard) {
		return {
			message: 'Success',
			result: { objects: { found: 100, deleted: 0, deletionGuard: guard } },
		}
	}

	it('says nothing when the cleanup pass ran unimpeded', () => {
		const ids = descriptor
			.sections(withGuard({ guarded: false, reason: null }))
			.map((s) => s.id)
		expect(ids).not.toContain('deletionGuard')
	})

	it('says nothing when the cleanup pass never ran at all', () => {
		// A dry run skips deletion entirely, so the guard is null rather than
		// `guarded: false` — neither is something to warn about.
		expect(descriptor.sections(withGuard(null)).map((s) => s.id)).not.toContain(
			'deletionGuard',
		)
	})

	it('explains a tripped ratio guard ahead of the counters, with its figures', () => {
		const sections = descriptor.sections(
			withGuard({
				guarded: true,
				reason: 'ratio_threshold_exceeded',
				ratio: 0.15,
				threshold: 0.1,
				candidateCount: 15,
				totalContracts: 100,
			}),
		)

		// First, because `deleted: 0` beside a large `found` otherwise reads as
		// a clean no-op.
		expect(sections[0].id).toBe('deletionGuard')
		expect(sections[0].noteType).toBe('warning')
		expect(sections[0].rows.map((r) => r.value)).toEqual([
			'15 / 100',
			'15%',
			'10%',
		])
	})

	it.each([
		['incremental_mode', /incremental mode/],
		['fetch_incomplete', /did not complete/],
	])('explains the %s guard in its own terms', (reason, matcher) => {
		const section = descriptor
			.sections(withGuard({ guarded: true, reason }))
			.find((s) => s.id === 'deletionGuard')
		expect(section.value).toMatch(matcher)
		expect(section.rows).toEqual([])
	})
})

describe('deletion guard retry hint', () => {
	const descriptor = getRunDescriptor('synchronization', 'run')

	it('offers a force-deletion re-run when the ratio guard tripped', () => {
		const retry = descriptor.retry({
			result: {
				objects: {
					deletionGuard: {
						guarded: true,
						reason: 'ratio_threshold_exceeded',
					},
				},
			},
		})
		expect(retry.values).toEqual({ forceDeletion: true })
	})

	it.each([
		// forceDeletion cannot override either of these, so offering it would lie.
		['incremental_mode'],
		['fetch_incomplete'],
	])(
		'offers nothing for the %s guard, which forceDeletion cannot override',
		(reason) => {
			expect(
				descriptor.retry({
					result: {
						objects: { deletionGuard: { guarded: true, reason } },
					},
				}),
			).toBeNull()
		},
	)

	it('offers nothing for an unguarded or absent run', () => {
		expect(descriptor.retry({ result: { objects: {} } })).toBeNull()
		expect(descriptor.retry(null)).toBeNull()
	})
})

describe('countUuids', () => {
	it('ignores the null entries the engine pushes for uuid-less contracts', () => {
		expect(countUuids(['a', null, 'b', undefined, ''])).toBe(2)
	})

	it('tolerates a missing list', () => {
		expect(countUuids(undefined)).toBe(0)
	})
})

describe('job sections', () => {
	const { sections } = getRunDescriptor('job', 'test')

	it('flattens the frame_N-keyed stack trace back into an ordered list', () => {
		// JobService::saveJobLog() re-keys the frame list into an object so it
		// passes the job_log schema's 'object or null' type.
		const result = sections({
			level: 'ERROR',
			stackTrace: { frame_0: 'first', frame_1: 'second' },
		})
		const trace = result.find((section) => section.id === 'stackTrace')
		expect(trace.value).toEqual(['first', 'second'])
	})

	it('omits the stack-trace section when there is none', () => {
		const result = sections({ level: 'INFO', stackTrace: null })
		expect(result.some((section) => section.id === 'stackTrace')).toBe(false)
	})
})

describe('logsLink', () => {
	it('matches the route + query pair the "View logs" row action uses', () => {
		expect(
			getRunDescriptor('synchronization', 'test').logsLink({ id: 9 }),
		).toEqual({ name: 'SynchronizationLogs', query: { synchronizationId: 9 } })
		expect(getRunDescriptor('job', 'run').logsLink({ id: 3 })).toEqual({
			name: 'JobLogs',
			query: { jobId: 3 },
		})
		expect(getRunDescriptor('flow', 'run').logsLink({ id: 'f-1' })).toEqual({
			name: 'FlowRuns',
			query: { flowId: 'f-1' },
		})
	})

	it('returns null for a row with no id, so the modal hides the link', () => {
		expect(getRunDescriptor('job', 'run').logsLink({})).toBeNull()
	})
})
