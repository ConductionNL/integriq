/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * callLogApi: the exact requests behind preview, replay (one call or many)
 * and fire, which recorded calls count as failed, and the small readers the
 * call-log modals share (source options, dry runs, ids, server errors).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

const api = await import('@/modals/CallLog/callLogApi.js')

beforeEach(() => {
	get.mockReset()
	post.mockReset()
})

describe('requests', () => {
	it('previews one call, its id encoded into the path', async () => {
		get.mockResolvedValue({ data: { versions: [] } })

		expect(await api.previewCall('a/b c')).toEqual({ versions: [] })
		expect(get).toHaveBeenCalledWith('/index.php/apps/integriq/api/calls/a%2Fb%20c/preview')
	})

	it('replays a single call on its own endpoint, a real run unless dryRun is exactly true', async () => {
		post.mockResolvedValue({ data: { ok: true } })

		expect(await api.replayCalls(['c-1'])).toEqual({ ok: true })
		await api.replayCalls(['c-1'], { dryRun: 'yes', mappingVersion: '' })

		expect(post.mock.calls).toEqual([
			['/index.php/apps/integriq/api/calls/c-1/replay', { dryRun: false }],
			['/index.php/apps/integriq/api/calls/c-1/replay', { dryRun: false }],
		])
	})

	it('replays several calls in one bulk request, with the mapping version when given', async () => {
		post.mockResolvedValue({ data: { items: [] } })

		await api.replayCalls(['c-1', 'c-2'], { dryRun: true, mappingVersion: '3' })

		expect(post).toHaveBeenCalledWith('/index.php/apps/integriq/api/calls/replay', {
			dryRun: true,
			mappingVersion: '3',
			calls: ['c-1', 'c-2'],
		})
	})

	it('fires a call by hand with its target, request and a strict dry-run flag', async () => {
		post.mockResolvedValue({ data: { sent: true } })

		await api.fireCall('src-1', { method: 'GET', path: '/x' }, 1)

		expect(post).toHaveBeenCalledWith('/index.php/apps/integriq/api/calls/fire', {
			target: 'src-1',
			request: { method: 'GET', path: '/x' },
			dryRun: false,
		})
	})

	it('lists the newest outbound calls that failed', async () => {
		get.mockResolvedValue({
			data: {
				results: [
					{ id: 1, direction: 'outbound', statusCode: 500 },
					{ id: 2, direction: 'inbound', statusCode: 500 },
					{ id: 3, direction: 'outbound', statusCode: 204 },
					{ id: 4, statusCode: null },
				],
			},
		})

		const failed = await api.recentFailedCalls(25)

		expect(failed.map((c) => c.id)).toEqual([1, 4])
		expect(get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/call_log', {
			params: { _limit: 25, '_order[created]': 'desc' },
		})
	})

	it('reads a failed-call list that is not an array as empty, and asks for 100 by default', async () => {
		get.mockResolvedValue({ data: { results: 'oops' } })

		expect(await api.recentFailedCalls()).toEqual([])
		expect(get.mock.calls[0][1].params._limit).toBe(100)
	})

	it('maps the sources to options by object id, id or uuid, labelled by name or title', async () => {
		get.mockResolvedValue({
			data: {
				results: [
					{ '@self': { id: 9 }, id: 1, name: 'KvK' },
					{ id: 2, title: 'BRP' },
					{ uuid: 'u-3', id: 3 },
				],
			},
		})

		expect(await api.listSourceOptions()).toEqual([
			{ id: '9', label: 'KvK' },
			{ id: '2', label: 'BRP' },
			{ id: '3', label: '3' },
		])
		expect(get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/source', { params: { _limit: 500 } })
	})
})

describe('readers', () => {
	it('counts a call as failed unless its status is 2xx', () => {
		expect([199, 200, 299, 300, 404, 0].map((statusCode) => api.callFailed({ statusCode })))
			.toEqual([true, false, false, true, true, true])
		expect(api.callFailed({ statusCode: '201' })).toBe(false)
		expect(api.callFailed({})).toBe(true)
		expect(api.callFailed(null)).toBe(true)
	})

	it('reads a dry run as a successful item that was not sent', () => {
		expect(api.isDryRun({ sent: false, succeeded: true })).toBe(true)
		expect(api.isDryRun({ sent: true, succeeded: true })).toBe(false)
		expect(api.isDryRun({ sent: false, succeeded: false })).toBe(false)
		expect(api.isDryRun({ succeeded: true })).toBe(false)
	})

	it('reads a call id from the object id, then the id, then the uuid, as text', () => {
		expect(api.callId({ '@self': { id: 5 }, id: 6, uuid: 'u' })).toBe('5')
		expect(api.callId({ id: 6, uuid: 'u' })).toBe('6')
		expect(api.callId({ uuid: 'u' })).toBe('u')
		expect(api.callId({ id: 0 })).toBe('0')
		expect(api.callId(undefined)).toBe('')
	})

	it('prefers the server error over the fallback text', () => {
		const refused = { response: { data: { error: 'Source is disabled' } } }

		expect(api.serverError(refused, 'fallback')).toBe('Source is disabled')
		expect(api.serverError(new Error('x'), 'fallback')).toBe('fallback')
		expect(api.replayError(refused)).toBe('Source is disabled')
		expect(api.replayError({})).toBe('The replay could not be started.')
		expect(api.fireError({})).toBe('The call could not be fired.')
		expect(api.dryRunText()).toBe('Dry run: this is what would be sent. Nothing was sent.')
	})
})
