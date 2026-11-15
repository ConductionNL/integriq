/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Route again" in the intake inbox: the row action on a held or failed
 * message, the bulk dialog that answers each message, and the sentence each
 * outcome becomes. Components run on a plain context; the HTTP client and the
 * toasts are mocked.
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { describeOutcome, isRoutable } from '@/components/intake/routeAgainOutcome.js'

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: {},
	NcActions: {},
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcModal: {},
}))

const rowActions = (await import('@/components/intake/IntakeMessageRowActions.vue')).default
const bulkModal = (await import('@/modals/Intake/IntakeRouteAgainModal.vue')).default

/**
 * A plain context with a component's props, data, computed getters and methods.
 *
 * @param {object} component The component definition.
 * @param {object} props The props.
 * @return {object} The context.
 */
function context(component, props = {}) {
	const ctx = { ...props, $emit: vi.fn() }
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
 * @return {object} The page.
 */
function logsPage() {
	return { $options: { name: 'CnLogsPage' }, refresh: vi.fn(), $parent: null }
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('routeAgainOutcome', () => {
	it('offers Route again on held and failed messages only', () => {
		expect(isRoutable({ status: 'held' })).toBe(true)
		expect(isRoutable({ status: 'failed' })).toBe(true)
		expect(isRoutable({ status: 'routed' })).toBe(false)
		expect(isRoutable(null)).toBe(false)
	})

	it('names the rule and the case of a routed message', () => {
		expect(describeOutcome({ status: 'routed', ruleName: 'Teams', targetRef: 'ZAAK-1' }))
			.toEqual({ routed: true, text: 'Routed by "Teams" to ZAAK-1' })
		expect(describeOutcome({ status: 'routed', ruleName: 'Teams', targetRef: '' }))
			.toEqual({ routed: true, text: 'Routed by "Teams"' })
	})

	it('gives the reason a message is still waiting, or the refusal', () => {
		expect(describeOutcome({ status: 'held', reason: 'No routing rule matched' }))
			.toEqual({ routed: false, text: 'Still held: No routing rule matched' })
		expect(describeOutcome({ id: 'x', error: 'Not a held message' }))
			.toEqual({ routed: false, text: 'Not a held message' })
	})
})

describe('IntakeMessageRowActions', () => {
	it('is shown on a held message with a uuid, not on a routed one', () => {
		expect(context(rowActions, { row: { id: 'm1', status: 'held' } }).routable).toBe(true)
		expect(context(rowActions, { row: { id: 'm1', status: 'routed' } }).routable).toBe(false)
		expect(context(rowActions, { row: { status: 'held' } }).routable).toBe(false)
	})

	it('routes the message again, says where it went and reads the inbox again', async () => {
		axios.post.mockResolvedValue({ data: { id: 'm1', status: 'routed', ruleName: 'Teams', targetRef: 'ZAAK-1', reason: '' } })
		const page = logsPage()
		const ctx = context(rowActions, { row: { id: 'm1', status: 'held' } })
		ctx.$parent = page

		expect(await ctx.onRouteAgain()).toBe(true)

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/messages/m1/route')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Routed by "Teams" to ZAAK-1')
		expect(page.refresh).toHaveBeenCalledTimes(1)
	})

	it('reports a refusal as an error', async () => {
		axios.post.mockRejectedValue(Object.assign(new Error('x'), { response: { data: { id: 'm1', error: 'Not held' } } }))
		const ctx = context(rowActions, { row: { id: 'm1', status: 'failed' } })

		expect(await ctx.onRouteAgain()).toBe(false)

		expect(dialogs.showError).toHaveBeenCalledWith('Not held')
		expect(ctx.routing).toBe(false)
	})
})

describe('IntakeRouteAgainModal', () => {
	it('loads the held and failed messages, newest first', async () => {
		axios.get.mockImplementation((url, { params }) => Promise.resolve({
			data: {
				results: params.status === 'held'
					? [{ id: 'h1', status: 'held', receivedAt: '2026-11-01T10:00:00Z' }]
					: [{ id: 'f1', status: 'failed', receivedAt: '2026-11-02T10:00:00Z' }],
			},
		}))
		const ctx = context(bulkModal, { open: true })

		await ctx.load()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/intake_message', { params: { status: 'held', _limit: 200 } })
		expect(ctx.messages.map((message) => message.id)).toEqual(['f1', 'h1'])
	})

	it('routes the selection in one request and answers each message', async () => {
		axios.post.mockResolvedValue({
			data: {
				results: [
					{ id: 'a', status: 'routed', ruleName: 'Teams', targetRef: 'ZAAK-1', reason: '' },
					{ id: 'b', status: 'held', ruleName: '', targetRef: '', reason: 'No routing rule matched' },
					{ id: 'c', status: 'routed', ruleName: 'Teams', targetRef: 'ZAAK-2', reason: '' },
				],
			},
		})
		const ctx = context(bulkModal, { open: true })
		ctx.selected = ['a', 'b', 'c']

		await ctx.routeSelected()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/messages/route', { ids: ['a', 'b', 'c'] })
		expect(ctx.outcomes.a).toEqual({ routed: true, text: 'Routed by "Teams" to ZAAK-1' })
		expect(ctx.outcomes.b).toEqual({ routed: false, text: 'Still held: No routing rule matched' })
		expect(ctx.selected).toEqual(['b'])
		expect(ctx.summary).toBe('%n messages routed, %n still waiting')
		expect(ctx.$emit).toHaveBeenCalledWith('routed', expect.any(Array))
	})

	it('toggles a message in and out of the selection', () => {
		const ctx = context(bulkModal, { open: true })
		ctx.toggle('a', true)
		ctx.toggle('a', true)
		ctx.toggle('b', true)
		ctx.toggle('a', false)
		expect(ctx.selected).toEqual(['b'])
	})
})
