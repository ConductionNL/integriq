/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the manifest row-action handlers (src/handlers/actionHandlers.js):
 *   • the POST handlers hit the correct endpoint and toast success/error;
 *   • viewLogsHandler maps actionId → destination route + query param;
 *   • the modal-opening handlers emit the right event on the shared bus.
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
	viewLogsHandler,
} from '../../src/handlers/actionHandlers.js'
import { setRouter } from '../../src/handlers/routerRef.js'
import {
	modalBus,
	EVENT_OPEN_TEST_MAPPING,
	EVENT_OPEN_ADD_ENDPOINT_RULE,
} from '../../src/handlers/modalBus.js'

beforeEach(() => {
	post.mockReset()
	showSuccess.mockReset()
	showError.mockReset()
})

describe('POST action handlers — endpoint + success toast', () => {
	it('testSourceHandler posts to /api/sources/test/{id} (prefers id over uuid)', async () => {
		post.mockResolvedValueOnce({})
		await testSourceHandler({ item: { id: 7, uuid: 'u-7' } })
		expect(post).toHaveBeenCalledWith('/index.php/apps/openconnector/api/sources/test/7')
		expect(showSuccess).toHaveBeenCalledTimes(1)
		expect(showError).not.toHaveBeenCalled()
	})

	it('runJobHandler posts to /api/jobs/run/{id}', async () => {
		post.mockResolvedValueOnce({})
		await runJobHandler({ item: { id: 3 } })
		expect(post).toHaveBeenCalledWith('/index.php/apps/openconnector/api/jobs/run/3')
		expect(showSuccess).toHaveBeenCalledTimes(1)
	})

	it('testJobHandler posts to /api/jobs/test/{id}', async () => {
		post.mockResolvedValueOnce({})
		await testJobHandler({ item: { id: 3 } })
		expect(post).toHaveBeenCalledWith('/index.php/apps/openconnector/api/jobs/test/3')
	})

	it('runSynchronizationHandler posts to /api/synchronizations/{id}/run', async () => {
		post.mockResolvedValueOnce({})
		await runSynchronizationHandler({ item: { id: 9 } })
		expect(post).toHaveBeenCalledWith('/index.php/apps/openconnector/api/synchronizations/9/run')
	})

	it('testSynchronizationHandler posts to /api/synchronizations/{id}/test', async () => {
		post.mockResolvedValueOnce({})
		await testSynchronizationHandler({ item: { id: 9 } })
		expect(post).toHaveBeenCalledWith('/index.php/apps/openconnector/api/synchronizations/9/test')
	})

	it('falls back to uuid when id is absent', async () => {
		post.mockResolvedValueOnce({})
		await testSourceHandler({ item: { uuid: 'abc' } })
		expect(post).toHaveBeenCalledWith('/index.php/apps/openconnector/api/sources/test/abc')
	})
})

describe('POST action handlers — error path', () => {
	it('shows an error toast (with server message) when the request rejects', async () => {
		post.mockRejectedValueOnce({ response: { data: { message: 'boom' } } })
		await runJobHandler({ item: { id: 1 } })
		expect(showError).toHaveBeenCalledTimes(1)
		expect(showError.mock.calls[0][0]).toContain('boom')
		expect(showSuccess).not.toHaveBeenCalled()
	})

	it('tolerates an error with no structured detail', async () => {
		post.mockRejectedValueOnce(new Error('network'))
		await testJobHandler({ item: { id: 1 } })
		expect(showError).toHaveBeenCalledTimes(1)
		expect(showError.mock.calls[0][0]).toContain('network')
	})
})

describe('modal-opening handlers', () => {
	it('testMappingModalHandler emits open-test-mapping with the mapping', () => {
		const spy = vi.fn()
		modalBus.$once(EVENT_OPEN_TEST_MAPPING, spy)
		testMappingModalHandler({ item: { id: 'm1' } })
		expect(spy).toHaveBeenCalledWith({ mapping: { id: 'm1' } })
	})

	it('addEndpointRuleHandler emits open-add-endpoint-rule with the endpoint', () => {
		const spy = vi.fn()
		modalBus.$once(EVENT_OPEN_ADD_ENDPOINT_RULE, spy)
		addEndpointRuleHandler({ item: { id: 'e1' } })
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

	it('maps each known actionId to its destination route', () => {
		const cases = [
			['view-endpoint-logs', 'EndpointLogs', 'endpoint'],
			['view-job-logs', 'JobLogs', 'job'],
			['view-synchronization-logs', 'SynchronizationLogs', 'synchronization'],
			['view-cloud-event-logs', 'CloudEventLogs', 'event'],
		]
		for (const [actionId, route, param] of cases) {
			const push = vi.fn().mockResolvedValue()
			setRouter({ push })
			viewLogsHandler({ actionId, item: { id: 5 } })
			expect(push).toHaveBeenCalledWith({ name: route, query: { [param]: 5 } })
		}
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
