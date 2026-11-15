/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The endpoints behind the intake routing page and the inbox: which URL each
 * call reaches, with which body or query, and how an answer of the wrong
 * shape is read. Only the HTTP client is mocked.
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))

const api = await import('@/modals/v2/intakeRuleApi.js')

beforeEach(() => {
	vi.clearAllMocks()
})

describe('saveRule and testRule', () => {
	it('creates without an id and updates with an encoded one', async () => {
		axios.post.mockResolvedValueOnce({ data: { id: 'new' } })
		expect(await api.saveRule('', { name: 'r' })).toEqual({ id: 'new' })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/routing-rules', { name: 'r' })

		axios.put.mockResolvedValueOnce({ data: { id: 'a/b' } })
		expect(await api.saveRule('a/b', { name: 'r' })).toEqual({ id: 'a/b' })
		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/routing-rules/a%2Fb', { name: 'r' })
	})

	it('sends the rule and the sample to the test endpoint', async () => {
		axios.post.mockResolvedValueOnce({ data: { matched: true } })
		expect(await api.testRule({ name: 'r' }, { text: 'hi' })).toEqual({ matched: true })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/routing-rules/test', { rule: { name: 'r' }, sample: { text: 'hi' } })
	})
})

describe('reading lists', () => {
	it('returns the channels, or none when the answer carries no list', async () => {
		axios.get.mockResolvedValueOnce({ data: { channels: [{ channelId: 'teams' }] } })
		expect(await api.fetchChannels()).toEqual([{ channelId: 'teams' }])
		axios.get.mockResolvedValueOnce({ data: { channels: 'nope' } })
		expect(await api.fetchChannels()).toEqual([])
		axios.get.mockResolvedValueOnce({ data: null })
		expect(await api.fetchChannels()).toEqual([])
	})

	it('reads registers and schemas together with their limits, defaulting to empty lists', async () => {
		axios.get.mockImplementation(async (url) => (url.endsWith('/registers') ? { data: { results: [{ id: 1 }] } } : { data: {} }))
		expect(await api.fetchRegistersAndSchemas()).toEqual({ registers: [{ id: 1 }], schemas: [] })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/registers', { params: { _limit: 500 } })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/schemas', { params: { _limit: 1000 } })
	})

	it('reads one channel\'s rules from the objects endpoint', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 'r1' }] } })
		expect(await api.fetchChannelRules('teams')).toEqual([{ id: 'r1' }])
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/intake_routing_rule', { params: { channelId: 'teams', _limit: 500 } })
		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await api.fetchChannelRules('teams')).toEqual([])
	})

	it('lists held and failed messages together, newest first, a missing time last', async () => {
		axios.get.mockImplementation(async (url, { params }) => ({
			data: params.status === 'held'
				? { results: [{ id: 'h1', receivedAt: '2026-11-01T10:00:00Z' }, { id: 'h2' }] }
				: { results: [{ id: 'f1', receivedAt: '2026-11-02T10:00:00Z' }] },
		}))
		const list = await api.fetchWaitingMessages()
		expect(list.map((message) => message.id)).toEqual(['f1', 'h1', 'h2'])
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/intake_message', { params: { status: 'held', _limit: 200 } })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/intake_message', { params: { status: 'failed', _limit: 200 } })
	})

	it('treats a status without results as empty', async () => {
		axios.get.mockResolvedValue({ data: {} })
		expect(await api.fetchWaitingMessages()).toEqual([])
	})
})

describe('routing again', () => {
	it('routes one message by its encoded id', async () => {
		axios.post.mockResolvedValueOnce({ data: { status: 'routed' } })
		expect(await api.routeAgain('m 1')).toEqual({ status: 'routed' })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/messages/m%201/route')
	})

	it('routes many and returns the per-message results, or none', async () => {
		axios.post.mockResolvedValueOnce({ data: { results: [{ id: 'a', ok: true }] } })
		expect(await api.routeAgainMany(['a'])).toEqual([{ id: 'a', ok: true }])
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/messages/route', { ids: ['a'] })
		axios.post.mockResolvedValueOnce({ data: {} })
		expect(await api.routeAgainMany(['a'])).toEqual([])
	})
})

describe('refusalOf', () => {
	it('prefers the error, then the message, then the fallback', () => {
		expect(api.refusalOf({ response: { data: { error: 'E', message: 'M' } } }, 'F')).toBe('E')
		expect(api.refusalOf({ response: { data: { error: '', message: 'M' } } }, 'F')).toBe('M')
		expect(api.refusalOf({ response: { data: { error: 4, message: '' } } }, 'F')).toBe('F')
		expect(api.refusalOf(new Error('x'), 'F')).toBe('F')
		expect(api.refusalOf(null, 'F')).toBe('F')
	})
})

describe('mail import', () => {
	it('reads whether importing is allowed and the mailboxes, strictly', async () => {
		axios.get.mockResolvedValueOnce({ data: { allowed: true, mailboxes: [{ id: 's1' }] } })
		expect(await api.fetchMailImportOptions()).toEqual({ allowed: true, mailboxes: [{ id: 's1' }] })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/integriq/api/mail-intake/import/options')
		axios.get.mockResolvedValueOnce({ data: { allowed: 'yes', mailboxes: {} } })
		expect(await api.fetchMailImportOptions()).toEqual({ allowed: false, mailboxes: [] })
	})

	it('posts the source and the file as a form', async () => {
		axios.post.mockResolvedValueOnce({ data: { imported: 1 } })
		const file = new Blob(['From: a'], { type: 'message/rfc822' })
		expect(await api.importMailFile('s1', file)).toEqual({ imported: 1 })
		const [url, form] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/integriq/api/mail-intake/import')
		expect(form).toBeInstanceOf(FormData)
		expect(form.get('sourceId')).toBe('s1')
		expect(form.get('file')).toBeInstanceOf(Blob)
	})
})
