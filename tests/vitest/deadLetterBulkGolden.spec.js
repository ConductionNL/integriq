/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the selection, debounced reload and bulk replay/discard of
 * the two dead-letter pages (EventDeliveriesPage, SyncDeadLetterPage).
 *
 * Each page's data and methods are resolved through its mixins and its own
 * options and run on a plain context against a scripted server. Every
 * request, toast and state step is recorded; the snapshot pins that trace.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

const trace = []
const server = { bulk: 'all ok', list: 'ok' }

const BULK = {
	'all ok': () => Promise.resolve({ data: { results: { a: 'ok', b: 'ok' } } }),
	'some failed': () =>
		Promise.resolve({
			data: { results: { a: 'ok', b: 'not found', c: 'error' } },
		}),
	'one failed': () =>
		Promise.resolve({ data: { results: { a: 'ok', b: 'error' } } }),
	'no results': () => Promise.resolve({ data: {} }),
	'error with error': () =>
		Promise.reject({ response: { data: { error: 'locked' } } }),
	'transport error': () => Promise.reject(new Error('Network Error')),
	'bare error': () => Promise.reject({}),
}

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (url, config) => {
			trace.push(['get', url, config])
			if (server.list === 'fails') return Promise.reject(new Error('down'))
			return Promise.resolve({ data: { results: [{ uuid: 'r1' }] } })
		},
		post: (url, body) => {
			trace.push(['post', url, structuredClone(body)])
			return BULK[server.bulk]()
		},
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => trace.push(['error', text]),
	showSuccess: (text) => trace.push(['success', text]),
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcLoadingIcon: {},
	NcSelect: {},
	NcTextField: {},
}))

vi.mock('@/modals/EventDelivery/EventDeliveryDetailModal.vue', () => ({
	default: {},
}))
vi.mock('@/modals/Synchronization/SyncDeadLetterDetailModal.vue', () => ({
	default: {},
}))

const PAGES = {
	EventDeliveriesPage: (
		await import('@/views/EventDelivery/EventDeliveriesPage.vue')
	).default,
	SyncDeadLetterPage: (
		await import('@/views/Synchronization/SyncDeadLetterPage.vue')
	).default,
}

const FIELDS = ['selected', 'bulkConfirm', 'busy', 'loading', 'rows']

/**
 * A plain context carrying the merged data and methods of a page.
 *
 * @param {object} page The page options.
 * @return {object} The context.
 */
function context(page) {
	const layers = [...(page.mixins || []), page]
	const ctx = {}
	for (const layer of layers) {
		Object.assign(ctx, layer.data ? layer.data.call(ctx) : {})
	}
	for (const layer of layers) {
		for (const [name, fn] of Object.entries(layer.methods || {})) {
			ctx[name] = typeof fn === 'function' ? fn.bind(ctx) : fn
		}
	}
	return ctx
}

/**
 * Let pending promise callbacks run.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 6; i++) {
		await Promise.resolve()
	}
}

describe('dead-letter bulk golden', () => {
	afterEach(() => {
		vi.useRealTimers()
	})

	for (const [name, page] of Object.entries(PAGES)) {
		it(`${name}: selection and debounced reload as recorded`, async () => {
			vi.useFakeTimers()
			trace.length = 0
			server.list = 'ok'
			const ctx = context(page)
			const step = (label) =>
				trace.push([
					'state',
					label,
					Object.fromEntries(FIELDS.map((f) => [f, ctx[f]])),
				])
			step('initial')
			ctx.toggleSelect({ uuid: 'a' })
			ctx.toggleSelect({ id: 'b' })
			ctx.toggleSelect({ uuid: 'c', id: 'x' })
			trace.push([
				'isSelected',
				ctx.isSelected({ uuid: 'a' }),
				ctx.isSelected({ id: 'x' }),
			])
			ctx.toggleSelect({ uuid: 'a' })
			step('toggled')
			ctx.reloadDebounced()
			vi.advanceTimersByTime(399)
			step('before window')
			ctx.reloadDebounced()
			vi.advanceTimersByTime(399)
			step('restarted')
			vi.advanceTimersByTime(1)
			await vi.runAllTimersAsync()
			step('reloaded')
			expect(trace).toMatchSnapshot()
		})

		it.each(Object.keys(BULK))(
			`${name}: bulk commit as recorded when the server answers %s`,
			async (bulk) => {
				const runs = []
				server.bulk = bulk
				for (const verb of [null, 'replay', 'discard']) {
					for (const selected of [[], ['a', 'b']]) {
						trace.length = 0
						server.list = verb === 'discard' ? 'fails' : 'ok'
						const ctx = context(page)
						ctx.bulkConfirm = verb
						ctx.selected = [...selected]
						const pending = ctx.commitBulk()
						const busyDuring = ctx.busy
						await pending
						await flush()
						runs.push({
							verb,
							selected,
							busyDuring,
							state: Object.fromEntries(
								FIELDS.map((f) => [f, ctx[f]]),
							),
							trace: [...trace],
						})
					}
				}
				expect(runs).toMatchSnapshot()
			},
		)
	}
})
