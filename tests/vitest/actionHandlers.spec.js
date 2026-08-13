/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the manifest row-action handlers (src/handlers/actionHandlers.js):
 *   • the remaining POST handlers hit the correct endpoint and toast success/error;
 *   • viewLogsHandler maps actionId → destination route + query param;
 *   • the modal-opening handlers emit the right event on the shared bus.
 *
 * The four run/test handlers moved to the modal bus (REQ-SHELLUI-004): they used
 * to POST and toast, and now open RunActionModal, which owns the request. The
 * assertions here changed accordingly — what matters is the `{ target, mode }`
 * pair, since that pair selects the descriptor that decides the endpoint.
 *
 * @nextcloud/axios + @nextcloud/dialogs are mocked so we can assert the
 * request URL and which toast fired; @nextcloud/router + @nextcloud/l10n are
 * aliased to deterministic stubs in vitest.config.js.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'

const post = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...a) => post(...a) } }))

const showSuccess = vi.fn()
const showError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => showSuccess(...a),
	showError: (...a) => showError(...a),
}))

import {
	testSourceHandler,
	runJobHandler,
	testJobHandler,
	runSynchronizationHandler,
	testSynchronizationHandler,
	testMappingModalHandler,
	addEndpointRuleHandler,
	runFlowHandler,
	viewLogsHandler,
} from '../../src/handlers/actionHandlers.js'
import { setRouter } from '../../src/handlers/routerRef.js'
import {
	modalBus,
	EVENT_OPEN_TEST_MAPPING,
	EVENT_OPEN_TEST_SOURCE,
	EVENT_OPEN_ADD_ENDPOINT_RULE,
	EVENT_OPEN_RUN_ACTION,
} from '../../src/handlers/modalBus.js'

beforeEach(() => {
	post.mockReset()
	showSuccess.mockReset()
	showError.mockReset()
})

describe('POST action handlers — endpoint + success toast', () => {
	it('testSourceHandler opens the Test-connection modal (emits EVENT_OPEN_TEST_SOURCE), no POST', () => {
		const spy = vi.fn()
		// mitt (ADR-066): `.on`/`.off` replace the former Vue-2 `$on`/`$off`.
		modalBus.on(EVENT_OPEN_TEST_SOURCE, spy)
		const item = { id: 7, uuid: 'u-7', name: 'my-source' }
		testSourceHandler({ item })
		modalBus.off(EVENT_OPEN_TEST_SOURCE, spy)
		// The handler now hands the whole source to the modal (which resolves id/uuid and
		// runs the request interactively) rather than firing a blind POST + toast.
		expect(spy).toHaveBeenCalledTimes(1)
		expect(spy).toHaveBeenCalledWith({ source: item })
		expect(post).not.toHaveBeenCalled()
		expect(showSuccess).not.toHaveBeenCalled()
	})

	it('runFlowHandler posts to /api/flows/{id}/run', async () => {
		post.mockResolvedValueOnce({ data: { status: 'completed' } })
		await runFlowHandler({ item: { id: 3 } })
		expect(post).toHaveBeenCalledWith('/index.php/apps/openconnector/api/flows/3/run')
		expect(showSuccess).toHaveBeenCalledTimes(1)
	})

	it('runFlowHandler falls back to uuid when id is absent', async () => {
		post.mockResolvedValueOnce({ data: { status: 'completed' } })
		await runFlowHandler({ item: { uuid: 'abc' } })
		expect(post).toHaveBeenCalledWith('/index.php/apps/openconnector/api/flows/abc/run')
	})
})

describe('POST action handlers — error path', () => {
	it('shows an error toast (with server message) when the request rejects', async () => {
		post.mockRejectedValueOnce({ response: { data: { message: 'boom' } } })
		await runFlowHandler({ item: { id: 1 } })
		expect(showError).toHaveBeenCalledTimes(1)
		expect(showError.mock.calls[0][0]).toContain('boom')
		expect(showSuccess).not.toHaveBeenCalled()
	})

	it('tolerates an error with no structured detail', async () => {
		post.mockRejectedValueOnce(new Error('network'))
		await runFlowHandler({ item: { id: 1 } })
		expect(showError).toHaveBeenCalledTimes(1)
		expect(showError.mock.calls[0][0]).toContain('network')
	})
})

describe('run/test handlers — open the shared run modal instead of posting', () => {
	/**
	 * Capture one emission on the run-action bus event.
	 *
	 * @param {Function} run The handler invocation to observe.
	 * @return {object|undefined} The emitted payload.
	 */
	function captureRunAction(run) {
		const spy = vi.fn()
		modalBus.on(EVENT_OPEN_RUN_ACTION, spy)
		run()
		modalBus.off(EVENT_OPEN_RUN_ACTION, spy)
		expect(spy).toHaveBeenCalledTimes(1)
		return spy.mock.calls[0][0]
	}

	it.each([
		['runSynchronizationHandler', runSynchronizationHandler, 'synchronization', 'run'],
		['testSynchronizationHandler', testSynchronizationHandler, 'synchronization', 'test'],
		['runJobHandler', runJobHandler, 'job', 'run'],
		['testJobHandler', testJobHandler, 'job', 'test'],
	])('%s emits open-run-action for %s/%s', (_name, handler, target, mode) => {
		const item = { id: 9, name: 'row' }
		const payload = captureRunAction(() => handler({ item }))
		expect(payload).toEqual({ target, mode, item })
	})

	it('fires no request and no toast — the modal owns both', () => {
		runSynchronizationHandler({ item: { id: 9 } })
		testSynchronizationHandler({ item: { id: 9 } })
		runJobHandler({ item: { id: 3 } })
		testJobHandler({ item: { id: 3 } })
		expect(post).not.toHaveBeenCalled()
		expect(showSuccess).not.toHaveBeenCalled()
		expect(showError).not.toHaveBeenCalled()
	})

	it('passes the whole row through, so the modal can read syncMode for the force-deletion guard', () => {
		const item = { id: 9, name: 'row', syncMode: 'incremental' }
		const payload = captureRunAction(() => runSynchronizationHandler({ item }))
		expect(payload.item.syncMode).toBe('incremental')
	})
})

describe('modal-opening handlers', () => {
	it('testMappingModalHandler emits open-test-mapping with the mapping', () => {
		const spy = vi.fn()
		// mitt (ADR-066): `.on` replaces the former Vue-2 `$once`; the handler
		// receives the emit payload as its single argument. Detach after to keep
		// tests isolated.
		modalBus.on(EVENT_OPEN_TEST_MAPPING, spy)
		testMappingModalHandler({ item: { id: 'm1' } })
		modalBus.off(EVENT_OPEN_TEST_MAPPING, spy)
		expect(spy).toHaveBeenCalledWith({ mapping: { id: 'm1' } })
	})

	it('addEndpointRuleHandler emits open-add-endpoint-rule with the endpoint', () => {
		const spy = vi.fn()
		modalBus.on(EVENT_OPEN_ADD_ENDPOINT_RULE, spy)
		addEndpointRuleHandler({ item: { id: 'e1' } })
		modalBus.off(EVENT_OPEN_ADD_ENDPOINT_RULE, spy)
		expect(spy).toHaveBeenCalledWith({ endpoint: { id: 'e1' } })
	})
})

describe('viewLogsHandler — actionId → route + query', () => {
	it('pushes the mapped route with the parent id as a query param', () => {
		const push = vi.fn().mockResolvedValue()
		setRouter({ push })
		viewLogsHandler({ actionId: 'view-source-logs', item: { id: 42 } })
		expect(push).toHaveBeenCalledWith({ name: 'SourceLogs', query: { source: 42 } })
	})

	it('maps each filterable actionId to its destination route and field', () => {
		const cases = [
			// Each param must name a field the log rows are actually WRITTEN
			// with — `jobId` (JobService::saveJobLog), `endpoint`
			// (EndpointService::recordInboundCallLog) — or the destination page
			// applies a filter that matches nothing and renders empty.
			['view-endpoint-logs', 'EndpointLogs', 'endpoint'],
			['view-job-logs', 'JobLogs', 'jobId'],
			['view-synchronization-logs', 'SynchronizationLogs', 'synchronizationId'],
		]
		for (const [actionId, route, param] of cases) {
			const push = vi.fn().mockResolvedValue()
			setRouter({ push })
			viewLogsHandler({ actionId, item: { id: 5 } })
			expect(push).toHaveBeenCalledWith({ name: route, query: { [param]: 5 } })
		}
	})

	it('navigates UNFILTERED where the log rows carry no field to scope by', () => {
		// call_log declares no event property, so filtering on one would land
		// the user on a guaranteed-empty table — worse than showing everything.
		const push = vi.fn().mockResolvedValue()
		setRouter({ push })
		viewLogsHandler({ actionId: 'view-cloud-event-logs', item: { id: 5 } })
		expect(push).toHaveBeenCalledWith({ name: 'CloudEventLogs' })
	})

	it('reaches the unfiltered page even from a row that carries no id', () => {
		// An unfiltered target never reads the id, so requiring one gated the
		// navigation on a value it had no use for.
		const push = vi.fn().mockResolvedValue()
		setRouter({ push })
		viewLogsHandler({ actionId: 'view-cloud-event-logs', item: {} })
		expect(push).toHaveBeenCalledWith({ name: 'CloudEventLogs' })
	})

	it('still no-ops on a SCOPED target when the row carries no id', () => {
		// The missing-id guard has to survive being moved past the unfiltered
		// branch — a scoped target with no id would filter on `undefined`.
		const push = vi.fn()
		setRouter({ push })
		viewLogsHandler({ actionId: 'view-job-logs', item: {} })
		expect(push).not.toHaveBeenCalled()
	})

	it('no-ops on an unknown actionId', () => {
		const push = vi.fn()
		setRouter({ push })
		viewLogsHandler({ actionId: 'view-unknown-logs', item: { id: 1 } })
		expect(push).not.toHaveBeenCalled()
	})

	it('no-ops (no throw) when the router was never set', () => {
		setRouter(null)
		expect(() => viewLogsHandler({ actionId: 'view-job-logs', item: { id: 1 } })).not.toThrow()
	})
})
