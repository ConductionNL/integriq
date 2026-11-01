/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the account picker of the admin connection editors
 * (WebhookConnectionRow, OpenFormulierenConnectionSettings, DsoPkiSettings).
 *
 * Each component's picker data and searchAccounts() method are resolved
 * through its mixins and its own options, run against a plain context with a
 * scripted axios, and every request and state change is snapshotted.
 */
import { describe, expect, it, vi } from 'vitest'

const http = { calls: [], answer: null }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (url, config) => {
			http.calls.push(['get', url, config])
			return http.answer()
		},
		put: () => Promise.reject(new Error('not used')),
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: () => {},
	showSuccess: () => {},
	showWarning: () => {},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url) => `/ocs/v2.php/${url}`,
	generateUrl: (url) => url,
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcNoteCard: {},
	NcPasswordField: {},
	NcSelect: {},
	NcSelectUsers: {},
	NcTextField: {},
}))

const COMPONENTS = {
	WebhookConnectionRow: (await import('@/views/admin/WebhookConnectionRow.vue'))
		.default,
	OpenFormulierenConnectionSettings: (
		await import('@/views/admin/OpenFormulierenConnectionSettings.vue')
	).default,
	DsoPkiSettings: (await import('@/views/admin/DsoPkiSettings.vue')).default,
}

const PICKER_FIELDS = ['accountOptions', 'searchingAccounts', 'accountError']

/**
 * The component and its mixins, mixins first, as Vue merges them.
 *
 * @param {object} component The component options.
 * @return {Array<object>} The option objects.
 */
function layers(component) {
	return [...(component.mixins || []), component]
}

/**
 * A plain context carrying the merged data and methods of a component.
 *
 * @param {object} component The component options.
 * @return {object} The context.
 */
function context(component) {
	const ctx = {
		t: (app, text) => text,
		connection: {
			userId: '',
			account: { state: 'none', displayName: '' },
			scheme: 'openconnector',
			header: '',
			authorizationType: 'x',
			label: 'X',
		},
	}
	for (const layer of layers(component)) {
		Object.assign(ctx, layer.data ? layer.data.call(ctx) : {})
	}
	for (const layer of layers(component)) {
		for (const [name, fn] of Object.entries(layer.methods || {})) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

const ANSWERS = {
	'two entries': () =>
		Promise.resolve({
			data: { ocs: { data: [{ id: 'anna', label: 'Anna' }, { id: 'bob' }] } },
		}),
	'no entries': () => Promise.resolve({ data: { ocs: { data: null } } }),
	'request fails': () => Promise.reject(new Error('offline')),
	'malformed body': () => Promise.resolve({ data: {} }),
}

const SEARCHES = [undefined, '', 'a', 'an']

describe('account picker golden', () => {
	for (const [name, component] of Object.entries(COMPONENTS)) {
		it(`${name} renders as recorded`, async () => {
			const trace = []
			const initial = context(component)
			trace.push([
				'initial',
				Object.fromEntries(PICKER_FIELDS.map((f) => [f, initial[f]])),
			])
			for (const [answer, respond] of Object.entries(ANSWERS)) {
				for (const search of SEARCHES) {
					http.calls = []
					http.answer = respond
					const ctx = context(component)
					const pending = ctx.searchAccounts(search)
					const during = ctx.searchingAccounts
					await pending
					trace.push([
						answer,
						search,
						during,
						http.calls,
						Object.fromEntries(PICKER_FIELDS.map((f) => [f, ctx[f]])),
					])
				}
			}
			expect(trace).toMatchSnapshot()
		})
	}
})
