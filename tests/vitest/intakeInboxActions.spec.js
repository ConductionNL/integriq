/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Assign and Reply in the intake inbox: the actions show only to a handler
 * with the right, Assign only on a held or routed message and Reply only on a
 * channel that can carry one; each sends to its route and reads the inbox
 * again. Components run on a plain context; the HTTP client and the toasts are
 * mocked.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: {},
	NcActions: {},
	NcButton: {},
	NcDialog: {},
	NcTextArea: {},
	NcTextField: {},
}))

const actions = await import('@/components/intake/inboxActions.js')
const rowActions = (await import('@/components/intake/IntakeMessageRowActions.vue')).default

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
 * Answer the two reads: the user's rights and the channel list.
 *
 * @param {object} rights `{assign, reply}`.
 * @return {void}
 */
function answers(rights) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/api/intake/messages/rights')) {
			return Promise.resolve({ data: rights })
		}
		return Promise.resolve({
			data: {
				channels: [
					{ channelId: 'whatsapp', canReply: true },
					{ channelId: 'form', canReply: false },
				],
			},
		})
	})
}

beforeEach(() => {
	vi.clearAllMocks()
	actions.resetInboxActions()
})

describe('inboxActions', () => {
	it('assigns held and routed messages only', () => {
		expect(actions.isAssignable({ status: 'held' })).toBe(true)
		expect(actions.isAssignable({ status: 'routed' })).toBe(true)
		expect(actions.isAssignable({ status: 'assigned' })).toBe(false)
		expect(actions.isAssignable({ status: 'rejected' })).toBe(false)
		expect(actions.isAssignable(null)).toBe(false)
	})

	it('answers a message only on a channel that can reply', () => {
		const channels = new Set(['whatsapp'])
		expect(actions.isAnswerable({ channelId: 'whatsapp' }, channels)).toBe(true)
		expect(actions.isAnswerable({ channelId: 'form' }, channels)).toBe(false)
		expect(actions.isAnswerable({}, channels)).toBe(false)
	})

	it('asks for the rights once per page load', async () => {
		answers({ assign: true, reply: false })

		expect(await actions.fetchInboxRights()).toEqual({ assign: true, reply: false })
		await actions.fetchInboxRights()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/messages/rights')
	})

	it('allows nothing when the rights cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('403'))

		expect(await actions.fetchInboxRights()).toEqual({ assign: false, reply: false })
	})

	it('reads which channels can reply', async () => {
		answers({ assign: false, reply: true })

		expect([...await actions.fetchReplyChannels()]).toEqual(['whatsapp'])
	})
})

describe('IntakeMessageRowActions: who sees what', () => {
	it('shows a handler with both rights Assign on a held WhatsApp message, and Reply', async () => {
		answers({ assign: true, reply: true })
		const ctx = context(rowActions, { row: { id: 'm1', status: 'held', channelId: 'whatsapp' } })

		await ctx.loadRights()

		expect(ctx.assignable).toBe(true)
		expect(ctx.answerable).toBe(true)
	})

	it('shows a reader without the rights neither action', async () => {
		answers({ assign: false, reply: false })
		const ctx = context(rowActions, { row: { id: 'm1', status: 'held', channelId: 'whatsapp' } })

		await ctx.loadRights()

		expect(ctx.assignable).toBe(false)
		expect(ctx.answerable).toBe(false)
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('offers no Reply on a channel that cannot carry one', async () => {
		answers({ assign: true, reply: true })
		const ctx = context(rowActions, { row: { id: 'm1', status: 'routed', channelId: 'form' } })

		await ctx.loadRights()

		expect(ctx.assignable).toBe(true)
		expect(ctx.answerable).toBe(false)
	})
})

describe('IntakeMessageRowActions: sending', () => {
	it('assigns the message to the case the handler names and reads the inbox again', async () => {
		axios.post.mockResolvedValue({ data: { id: 'm1', status: 'assigned', targetRef: 'ZAAK-7' } })
		const page = { $options: { name: 'CnLogsPage' }, refresh: vi.fn(), $parent: null }
		const ctx = context(rowActions, { row: { id: 'm1', status: 'held' } })
		ctx.$parent = page
		ctx.openDialog('assign')
		ctx.caseRef = ' ZAAK-7 '

		expect(await ctx.onSubmit()).toBe(true)

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/messages/m1/assign', { caseRef: 'ZAAK-7' })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Assigned to ZAAK-7')
		expect(ctx.dialog).toBe('')
		expect(page.refresh).toHaveBeenCalledTimes(1)
	})

	it('sends the reply on the channel and keeps the dialog open when it is not sent', async () => {
		axios.post.mockResolvedValue({ data: { status: 'failed', channelId: 'whatsapp', detail: 'Window closed' } })
		const ctx = context(rowActions, { row: { id: 'm1', status: 'routed', channelId: 'whatsapp' } })
		ctx.openDialog('reply')
		ctx.replyText = 'We bellen u morgen.'

		expect(await ctx.onSubmit()).toBe(false)

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/intake/messages/m1/reply', { text: 'We bellen u morgen.' })
		expect(dialogs.showError).toHaveBeenCalledWith('Window closed')
		expect(ctx.dialog).toBe('reply')
	})

	it('reports a refused assignment with the server\'s reason', async () => {
		axios.post.mockRejectedValue(Object.assign(new Error('x'), { response: { data: { error: 'Not held or routed' } } }))
		const ctx = context(rowActions, { row: { id: 'm1', status: 'held' } })
		ctx.openDialog('assign')
		ctx.caseRef = 'ZAAK-7'

		expect(await ctx.onSubmit()).toBe(false)

		expect(dialogs.showError).toHaveBeenCalledWith('Not held or routed')
		expect(ctx.sending).toBe(false)
	})

	it('sends nothing while the dialog is empty', async () => {
		const ctx = context(rowActions, { row: { id: 'm1', status: 'held' } })
		ctx.openDialog('assign')

		expect(await ctx.onSubmit()).toBe(false)
		expect(axios.post).not.toHaveBeenCalled()
	})
})
