/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The action handlers the existing actionHandlers spec leaves out: the
 * directory-run, signing and header-action openers (which bus event, which
 * payload), the one-click rerun delegating to runAgain, the link-source
 * `?app=` pre-filter, how viewLogsHandler treats a rejected navigation, and
 * the two guards of logsLocation / openRun with no target or no router.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...a) => post(...a) } }))
const showSuccess = vi.fn()
const showError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => showSuccess(...a),
	showError: (...a) => showError(...a),
}))

const {
	manageSigningHandler,
	openConfigurationExportHandler,
	openConfigurationImportHandler,
	openGatewayCatalogueHandler,
	openLinkSourceHandler,
	openPromotionHandler,
	openRegistryLookupHandler,
	previewDirectorySyncHandler,
	rerunFailedRunHandler,
	runDirectorySyncHandler,
	viewLogsHandler,
} = await import('../../src/handlers/actionHandlers.js')
const { logsLocation } = await import('../../src/handlers/logTargets.js')
const bus = await import('../../src/handlers/modalBus.js')
const { setRouter } = await import('../../src/handlers/routerRef.js')
const { openRun } = await import('../../src/handlers/runAgain.js')

/**
 * Record every event the bus emits while `fn` runs.
 *
 * @param {Function} fn the code under test
 * @return {Array<[string, object]>} event name and payload pairs
 */
function emitted(fn) {
	const seen = []
	const listener = (type, payload) => seen.push([type, payload])
	bus.modalBus.on('*', listener)
	try {
		fn()
	} finally {
		bus.modalBus.off('*', listener)
	}
	return seen
}

/**
 * A router stub whose current route carries the given query.
 *
 * @param {object} query the current query
 * @param {Function} push the push implementation
 * @return {object} the router
 */
function routerWith(query = {}, push = vi.fn(() => Promise.resolve())) {
	return { currentRoute: { value: { query } }, push }
}

beforeEach(() => {
	post.mockReset()
	showSuccess.mockReset()
	showError.mockReset()
})

afterEach(() => {
	setRouter(null)
	vi.restoreAllMocks()
})

describe('row-action openers', () => {
	const row = { id: 's1', title: 'AD' }

	it('opens the directory run in run mode', () => {
		expect(emitted(() => runDirectorySyncHandler({ actionId: 'x', item: row }))).toEqual([
			[bus.EVENT_OPEN_DIRECTORY_RUN, { source: row, mode: 'run' }],
		])
	})

	it('opens the directory run in preview mode', () => {
		expect(emitted(() => previewDirectorySyncHandler({ actionId: 'x', item: row }))).toEqual([
			[bus.EVENT_OPEN_DIRECTORY_RUN, { source: row, mode: 'preview' }],
		])
	})

	it('opens the signing manager on the subscription', () => {
		expect(emitted(() => manageSigningHandler({ item: row }))).toEqual([
			[bus.EVENT_OPEN_SUBSCRIPTION_SIGNING, { subscription: row }],
		])
	})
})

describe('header-action openers', () => {
	it.each([
		['import', openConfigurationImportHandler, 'EVENT_OPEN_CONFIGURATION_IMPORT'],
		['export', openConfigurationExportHandler, 'EVENT_OPEN_CONFIGURATION_EXPORT'],
		['promotion', openPromotionHandler, 'EVENT_OPEN_PROMOTION'],
		['registry lookup', openRegistryLookupHandler, 'EVENT_OPEN_REGISTRY_LOOKUP'],
		['gateway catalogue', openGatewayCatalogueHandler, 'EVENT_OPEN_GATEWAY_CATALOGUE'],
	])('%s emits its own event with an empty payload', (_label, handler, eventName) => {
		expect(bus[eventName]).toEqual(expect.any(String))
		expect(emitted(() => handler())).toEqual([[bus[eventName], {}]])
	})

	it('gives every opener a distinct event', () => {
		const names = [
			bus.EVENT_OPEN_CONFIGURATION_IMPORT,
			bus.EVENT_OPEN_CONFIGURATION_EXPORT,
			bus.EVENT_OPEN_PROMOTION,
			bus.EVENT_OPEN_REGISTRY_LOOKUP,
			bus.EVENT_OPEN_GATEWAY_CATALOGUE,
			bus.EVENT_OPEN_LINK_SOURCE,
			bus.EVENT_OPEN_DIRECTORY_RUN,
			bus.EVENT_OPEN_SUBSCRIPTION_SIGNING,
		]
		expect(new Set(names).size).toBe(names.length)
	})
})

describe('openLinkSourceHandler', () => {
	it('pre-filters by the ?app= query', () => {
		setRouter(routerWith({ app: 'pipelinq' }))
		expect(emitted(() => openLinkSourceHandler())).toEqual([
			[bus.EVENT_OPEN_LINK_SOURCE, { app: 'pipelinq' }],
		])
	})

	it('ignores a repeated ?app= query that arrives as an array', () => {
		setRouter(routerWith({ app: ['a', 'b'] }))
		expect(emitted(() => openLinkSourceHandler())).toEqual([
			[bus.EVENT_OPEN_LINK_SOURCE, { app: '' }],
		])
	})

	it('opens unfiltered without a query or a router', () => {
		setRouter(routerWith({}))
		expect(emitted(() => openLinkSourceHandler())[0][1]).toEqual({ app: '' })
		setRouter(null)
		expect(emitted(() => openLinkSourceHandler())[0][1]).toEqual({ app: '' })
	})
})

describe('rerunFailedRunHandler', () => {
	it('posts the failed run again and resolves with the new run id', async () => {
		post.mockResolvedValue({ data: { runId: 'r2' } })
		await expect(
			rerunFailedRunHandler({ actionId: 'rerun', item: { synchronizationId: 'sync 1' } }),
		).resolves.toBe('r2')
		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][0]).toContain('/api/synchronizations/sync%201/run')
		expect(post.mock.calls[0][1]).toEqual({ triggeredBy: 'rerun' })
		expect(showSuccess).toHaveBeenCalledTimes(1)
	})

	it('posts nothing for a run without a synchronization', async () => {
		await expect(rerunFailedRunHandler({ item: {} })).resolves.toBeNull()
		expect(post).not.toHaveBeenCalled()
		expect(showError).toHaveBeenCalledTimes(1)
	})
})

describe('viewLogsHandler navigation failures', () => {
	const ctx = { actionId: 'view-job-logs', item: { id: 'j1' } }

	it('swallows a duplicated navigation silently', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const failure = Object.assign(new Error('dup'), { name: 'NavigationDuplicated' })
		const push = vi.fn(() => Promise.reject(failure))
		setRouter(routerWith({}, push))
		viewLogsHandler(ctx)
		await Promise.resolve()
		await Promise.resolve()
		expect(push).toHaveBeenCalledWith({ name: 'JobLogs', query: { jobId: 'j1' } })
		expect(warn).not.toHaveBeenCalled()
	})

	it('warns about any other failed navigation', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const failure = new Error('aborted')
		setRouter(routerWith({}, vi.fn(() => Promise.reject(failure))))
		viewLogsHandler(ctx)
		await Promise.resolve()
		await Promise.resolve()
		expect(warn).toHaveBeenCalledWith('[integriq] viewLogsHandler navigation failed', failure)
	})

	it('stays quiet on a rejection without an error', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		setRouter(routerWith({}, vi.fn(() => Promise.reject(undefined))))
		viewLogsHandler(ctx)
		await Promise.resolve()
		await Promise.resolve()
		expect(warn).not.toHaveBeenCalled()
	})
})

describe('guards', () => {
	it('logsLocation builds nothing for an unknown action', () => {
		expect(logsLocation('view-nothing', 'x')).toBeNull()
	})

	it('openRun does nothing without a router', () => {
		setRouter(null)
		expect(openRun('r1')).toBeUndefined()
	})

	it('openRun opens the runs page on the run, or on all runs without one', () => {
		const push = vi.fn(() => Promise.resolve())
		setRouter(routerWith({}, push))
		openRun('r1')
		openRun(null)
		expect(push.mock.calls).toEqual([
			[{ name: 'SynchronizationRuns', query: { uuid: 'r1' } }],
			[{ name: 'SynchronizationRuns', query: {} }],
		])
	})
})
