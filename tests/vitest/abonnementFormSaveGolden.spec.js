/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for save() of the subscription form
 * (src/modals/NotificatiesAbonnement/NotificatiesAbonnementForm.vue).
 *
 * save() runs on a plain context for every combination of form state, edit
 * target and server outcome. Each run records the requests, toasts, emitted
 * events, validation errors and busy flag; the snapshot pins that trace.
 */
import { describe, expect, it, vi } from 'vitest'

const trace = []
const server = { outcome: 'ok' }

/**
 * Answer a request the way the scripted server outcome says.
 *
 * @return {Promise<object>} The response.
 */
function answer() {
	if (server.outcome === 'ok') {
		return Promise.resolve({ data: {} })
	}
	if (server.outcome === 'api-error') {
		return Promise.reject({ response: { data: { error: 'kanaal unknown' } } })
	}
	if (server.outcome === 'message') {
		return Promise.reject(new Error('Network Error'))
	}
	return Promise.reject({})
}

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: () => Promise.resolve({ data: [] }),
		put: (url, body) => {
			trace.push(['put', url, body])
			return answer()
		},
		post: (url, body) => {
			trace.push(['post', url, body])
			return answer()
		},
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => trace.push(['error', text]),
	showSuccess: (text) => trace.push(['success', text]),
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcModal: {},
	NcSelect: {},
	NcTextField: {},
}))

const form = (
	await import('@/modals/NotificatiesAbonnement/NotificatiesAbonnementForm.vue')
).default

const MODELS = [
	{ name: '', sourceId: '', authHeaderName: '', authScheme: '' },
	{ name: 'Zaken', sourceId: 's1', authHeaderName: '', authScheme: '' },
	{ name: 'Zaken', sourceId: 's1' },
	{ name: 'Zaken', sourceId: '', authHeaderName: 'X-Key', authScheme: 'Token' },
]
const KANALEN = [[], ['zaken', 'besluiten']]
const TARGETS = [
	null,
	{ id: 7 },
	{ id: 8, uuid: 'u-8' },
	{ uuid: 'u-9' },
	{ name: 'no id' },
]
const OUTCOMES = ['ok', 'api-error', 'message', 'bare']

/**
 * A plain context carrying the form's methods and computed getters.
 *
 * @param {object} state The props and data to start from.
 * @return {object} The context.
 */
function context(state) {
	const ctx = { ...state, busy: false, errors: { stale: 'x' } }
	for (const [name, fn] of Object.entries(form.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, fn] of Object.entries(form.computed)) {
		Object.defineProperty(ctx, name, { get: fn.bind(ctx) })
	}
	ctx.$emit = (...args) => trace.push(['emit', ...args])
	return ctx
}

describe('subscription form save golden', () => {
	for (const outcome of OUTCOMES) {
		it(`saves as recorded when the server answers ${outcome}`, async () => {
			const runs = []
			server.outcome = outcome
			for (const model of MODELS) {
				for (const kanaalNames of KANALEN) {
					for (const abonnement of TARGETS) {
						trace.length = 0
						const ctx = context({
							model: { ...model },
							kanaalNames: [...kanaalNames],
							abonnement,
							sourceOptions: [],
						})
						const pending = ctx.save()
						const busyDuring = ctx.busy
						await pending
						runs.push({
							model,
							kanaalNames,
							abonnement,
							busyDuring,
							busyAfter: ctx.busy,
							errors: ctx.errors,
							trace: [...trace],
						})
					}
				}
			}
			expect(runs).toMatchSnapshot()
		})
	}
})
