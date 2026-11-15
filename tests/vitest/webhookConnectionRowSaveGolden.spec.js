/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for save() of the webhook connection row
 * (src/views/admin/WebhookConnectionRow.vue).
 *
 * save() runs on a plain context for every account, secret and server
 * answer. Each run records the request, toasts, emitted events, the account
 * error and the saving flag; the snapshot pins that trace.
 */
import { describe, expect, it, vi } from 'vitest'

const trace = []
const server = { answer: null }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: () => Promise.resolve({ data: [] }),
		put: (url, body) => {
			trace.push(['put', url, body])
			return server.answer.reject ? Promise.reject(server.answer.reject) : Promise.resolve({ data: server.answer.data })
		},
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => trace.push(['error', text]),
	showSuccess: (text) => trace.push(['success', text]),
	showWarning: (text) => trace.push(['warning', text]),
}))

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcPasswordField: {}, NcSelect: {}, NcSelectUsers: {}, NcTextField: {}, NcNoteCard: {}, NcLoadingIcon: {}, NcCheckboxRadioSwitch: {} }))

const row = (await import('@/views/admin/WebhookConnectionRow.vue')).default

/**
 * A plain context carrying the row's methods.
 *
 * @param {object} state The state to start from.
 * @return {object} The context.
 */
function context(state) {
	const ctx = {
		connection: { authorizationType: 'openformulieren', label: 'Open Formulieren' },
		saving: false,
		accountError: 'stale',
		t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m)),
		...state,
	}
	for (const [name, fn] of Object.entries(row.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.$emit = (...args) => trace.push(['emit', ...args])
	return ctx
}

const ANSWERS = {
	ok: { data: { connection: { id: 1 } } },
	'ok with warnings': { data: { connection: { id: 2 }, warnings: ['Secret is short', 'Header unusual'] } },
	'field error': { reject: { response: { data: { fieldErrors: { userId: 'Unknown account' }, errors: ['Account missing', 'Try again'] } } } },
	'errors not a list': { reject: { response: { data: { errors: 'nope' } } } },
	'no body': { reject: { response: {} } },
	network: { reject: new Error('Network Error') },
}

describe('webhook connection row save golden', () => {
	it('saves as recorded', async () => {
		const runs = []
		for (const account of [null, { id: 'svc', user: 'svc' }]) {
			for (const [name, answer] of Object.entries(ANSWERS)) {
				trace.length = 0
				server.answer = answer
				const ctx = context({ account, scheme: 'stripe', secret: 'whsec', header: 'Stripe-Signature' })
				const pending = ctx.save()
				const savingDuring = ctx.saving
				await pending
				runs.push({ account, answer: name, savingDuring, saving: ctx.saving, secret: ctx.secret, accountError: ctx.accountError, trace: [...trace] })
			}
		}
		expect(runs).toMatchSnapshot()
	})
})
