/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A message a send check refused is held, not dropped (REQ-OSI-010). The
 * held-messages page lists those messages with the check that stopped them,
 * and its two row actions release (send) or withdraw (not sent) one message.
 *
 * Spec coverage: openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-message-that-fails-a-send-check-is-held-for-review
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const trace = []
const server = { answer: () => Promise.resolve({ data: {} }) }

vi.mock('@nextcloud/axios', () => ({
	default: {
		post: (url, body) => {
			trace.push(['post', url, body])
			return server.answer()
		},
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => trace.push(['error', text]),
	showSuccess: (text) => trace.push(['success', text]),
}))

const { releaseHeldMessage, withdrawHeldMessage } = await import('@/handlers/heldMessage.js')
const handlers = await import('@/handlers/actionHandlers.js')

const root = join(__dirname, '..', '..')
const read = (relative) => JSON.parse(readFileSync(join(root, relative), 'utf8'))
const merged = buildManifest(
	read('src/manifest.json'),
	[read('src/manifest.d/held-messages.json')],
	read('src/menu-layout.json'),
)
const page = merged.pages.find((p) => p.id === 'HeldMessages')

beforeEach(() => {
	trace.length = 0
	server.answer = () => Promise.resolve({ data: {} })
})

describe('held messages page', () => {
	it('lists only messages held for review, with the check and the reason', () => {
		expect(page.type).toBe('index')
		expect(page.config.schema).toBe('outbound_message')
		expect(page.config.filter).toEqual({ holdStatus: 'review' })
		const keys = page.config.columns.map((column) => column.key)
		expect(keys).toContain('hold.check')
		expect(keys).toContain('hold.reason')
		expect(keys).not.toContain('body')
	})

	it('offers release and withdraw through registered handlers', () => {
		const names = page.config.actions.map((action) => action.handler)
		expect(names).toEqual(['releaseHeldMessageHandler', 'withdrawHeldMessageHandler'])
		const registry = readFileSync(join(root, 'src/registry.js'), 'utf8')
		for (const name of names) {
			expect(typeof handlers[name]).toBe('function')
			expect(registry).toMatch(new RegExp(`\\t${name},`))
		}
	})
})

describe('held message actions', () => {
	it('releases one message by its id and says it was handed over', async () => {
		server.answer = () => Promise.resolve({ data: { status: 'released', retry: { retried: ['a'], skipped: [] } } })

		const answer = await releaseHeldMessage({ id: 'msg 1' })

		expect(answer.status).toBe('released')
		expect(trace).toEqual([
			['post', '/index.php/apps/integriq/api/outbound/messages/msg%201/release', undefined],
			['success', 'The message was released and handed to the delivery pipeline.'],
		])
	})

	it('says nothing was sent when every recipient is still opted out', async () => {
		server.answer = () => Promise.resolve({ data: { retry: { retried: [], skipped: ['a'] } } })

		await releaseHeldMessage({ uuid: 'msg-2' })

		expect(trace[1]).toEqual(['success', 'The message was released, but every recipient is still opted out, so nothing was sent.'])
	})

	it('withdraws one message and passes the server refusal on', async () => {
		await withdrawHeldMessage({ id: 'msg-3' })
		expect(trace).toEqual([
			['post', '/index.php/apps/integriq/api/outbound/messages/msg-3/withdraw', undefined],
			['success', 'The message was withdrawn and recorded as not sent.'],
		])

		trace.length = 0
		server.answer = () => Promise.reject({ response: { data: { error: 'Only a message held for review can be released.' } } })
		expect(await releaseHeldMessage({ id: 'msg-4' })).toBeNull()
		expect(trace[1]).toEqual(['error', 'That did not work: Only a message held for review can be released.'])
	})

	it('posts nothing for a row without an id', async () => {
		expect(await withdrawHeldMessage({})).toBeNull()
		expect(trace).toEqual([['error', 'This row names no message.']])
	})
})
