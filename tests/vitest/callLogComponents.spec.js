/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The call-log slot components (src/components/callLog/): finding and
 * refreshing the surrounding CnLogsPage, the toolbar's refresh, and which
 * rows offer a replay. Components run on a plain context built from their
 * data(), computed and methods; the modals and the HTTP client are mocked.
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
 */
import { describe, expect, it, vi } from 'vitest'
import { refreshLogPage } from '@/components/callLog/refreshLogPage.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: {},
	NcActions: {},
	NcButton: {},
}))
vi.mock('@/modals/CallLog/CallBulkReplayModal.vue', () => ({ default: {} }))
vi.mock('@/modals/CallLog/CallFireModal.vue', () => ({ default: {} }))
vi.mock('@/modals/CallLog/CallReplayModal.vue', () => ({ default: {} }))

const actions = (await import('@/components/callLog/CallLogActions.vue')).default
const rowActions = (await import('@/components/callLog/CallLogRowActions.vue'))
	.default

/**
 * A plain context with a component's props, data, computed getters and methods.
 *
 * @param {object} component The component definition.
 * @param {object} props The props.
 * @return {object} The context.
 */
function context(component, props = {}) {
	const ctx = { ...props }
	Object.assign(ctx, component.data?.call(ctx) ?? {})
	for (const [name, fn] of Object.entries(component.methods ?? {})) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, getter] of Object.entries(component.computed ?? {})) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	return ctx
}

/**
 * A CnLogsPage stand-in with a refresh spy.
 *
 * @param {object} parent Its own parent.
 * @return {object} The page.
 */
function logsPage(parent = null) {
	return { $options: { name: 'CnLogsPage' }, refresh: vi.fn(), $parent: parent }
}

describe('refreshLogPage', () => {
	it('refreshes the nearest log page up the parent chain', () => {
		const outer = logsPage()
		const page = logsPage(outer)
		const table = { $options: { name: 'CnDataTable' }, $parent: page }
		expect(refreshLogPage({ $parent: { $parent: table } })).toBe(true)
		expect(page.refresh).toHaveBeenCalledTimes(1)
		expect(outer.refresh).not.toHaveBeenCalled()
	})

	it('skips a log page without a refresh function', () => {
		const page = logsPage()
		const broken = { $options: { name: 'CnLogsPage' }, $parent: page }
		expect(refreshLogPage({ $parent: broken })).toBe(true)
		expect(page.refresh).toHaveBeenCalledTimes(1)
	})

	it('skips a component of another name that has a refresh function', () => {
		const other = {
			$options: { name: 'Other' },
			refresh: vi.fn(),
			$parent: null,
		}
		expect(refreshLogPage({ $parent: other })).toBe(false)
		expect(other.refresh).not.toHaveBeenCalled()
	})

	it('returns false without any page or instance', () => {
		expect(refreshLogPage({ $parent: { $parent: null } })).toBe(false)
		expect(refreshLogPage(null)).toBe(false)
		expect(refreshLogPage(undefined)).toBe(false)
	})
})

describe('CallLogActions', () => {
	it('starts with both modals closed', () => {
		const ctx = context(actions)
		expect(ctx.bulkOpen).toBe(false)
		expect(ctx.fireOpen).toBe(false)
	})

	it('refreshes the surrounding log page', () => {
		const page = logsPage()
		const ctx = context(actions)
		ctx.$parent = page
		ctx.refresh()
		expect(page.refresh).toHaveBeenCalledTimes(1)
	})
})

describe('CallLogRowActions', () => {
	it.each([
		[{ '@self': { id: 'a-1' }, id: 'x' }, 'a-1'],
		[{ id: 7 }, '7'],
		[{ uuid: 'u-1' }, 'u-1'],
		[{}, ''],
	])('reads the call id of %j', (row, id) => {
		expect(context(rowActions, { row }).id).toBe(id)
	})

	it('offers a replay for an outbound call with an id', () => {
		expect(
			context(rowActions, { row: { id: 'c-1', direction: 'outbound' } })
				.replayable,
		).toBe(true)
		expect(context(rowActions, { row: { id: 'c-1' } }).replayable).toBe(true)
	})

	it('offers no replay for an inbound call', () => {
		expect(
			context(rowActions, { row: { id: 'c-1', direction: 'inbound' } })
				.replayable,
		).toBe(false)
	})

	it('offers no replay for a row without an id', () => {
		expect(
			context(rowActions, { row: { direction: 'outbound' } }).replayable,
		).toBe(false)
	})

	it('starts with the replay modal closed', () => {
		expect(context(rowActions, { row: {} }).replayOpen).toBe(false)
	})

	it('refreshes the surrounding log page after a replay', () => {
		const page = logsPage()
		const ctx = context(rowActions, { row: { id: 'c-1' } })
		ctx.$parent = { $parent: page }
		ctx.onReplayed()
		expect(page.refresh).toHaveBeenCalledTimes(1)
	})
})
