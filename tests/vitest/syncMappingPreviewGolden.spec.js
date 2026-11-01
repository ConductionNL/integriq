/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for runPreview() of the synchronization mapping preview
 * (src/views/Synchronization/SyncMappingPreview.vue).
 *
 * runPreview() runs on a plain context for every mapping state, input text
 * and server outcome. Each run records the requests, the reload calls and
 * the preview state; the snapshot pins that trace.
 */
import { describe, expect, it, vi } from 'vitest'

const trace = []
const server = { outcome: 'result object' }

const ANSWERS = {
	'result object': () => Promise.resolve({ data: { resultObject: { a: 1 } } }),
	'bare body': () => Promise.resolve({ data: { a: 2 } }),
	'null body': () => Promise.resolve({ data: null }),
	'no data': () => Promise.resolve({}),
	'error with message': () =>
		Promise.reject({ response: { data: { message: 'bad mapping' } } }),
	'transport error': () => Promise.reject(new Error('Network Error')),
	'bare error': () => Promise.reject({}),
}

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: () => Promise.resolve({ data: {} }),
		post: (url, body) => {
			trace.push(['post', url, body])
			return ANSWERS[server.outcome]()
		},
	},
}))

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {} }))

globalThis.t = (app, text, vars) =>
	text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))

const preview = (await import('@/views/Synchronization/SyncMappingPreview.vue'))
	.default

const FIELDS = ['inputError', 'runError', 'result', 'running']
const MAPPINGS = [
	{ mapping: null, mappingId: null },
	{ mapping: null, mappingId: 'm-1' },
	{ mapping: { mapping: { a: '{{ b }}' } }, mappingId: 'm-1' },
]
const INPUTS = [undefined, '', '   ', '{"b":1}', 'null', '[1,2]', '{bad']

/**
 * A plain context carrying the preview's data and methods.
 *
 * @param {object} state The props and data to start from.
 * @return {object} The context.
 */
function context(state) {
	const ctx = {}
	Object.assign(ctx, preview.data.call(ctx), {
		runError: 'stale run',
		inputError: 'stale input',
		result: { stale: true },
		...state,
	})
	for (const [name, fn] of Object.entries(preview.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.loadAndRun = () => {
		trace.push(['loadAndRun'])
		return 'reloaded'
	}
	return ctx
}

describe('sync mapping preview run golden', () => {
	it.each(Object.keys(ANSWERS))(
		'previews as recorded when the server answers %s',
		async (outcome) => {
			const runs = []
			server.outcome = outcome
			for (const mapping of MAPPINGS) {
				for (const inputJson of INPUTS) {
					trace.length = 0
					const ctx = context({ ...mapping, inputJson })
					const pending = ctx.runPreview()
					const runningDuring = ctx.running
					const returned = await pending
					runs.push({
						mapping,
						inputJson,
						runningDuring,
						returned,
						state: Object.fromEntries(FIELDS.map((f) => [f, ctx[f]])),
						trace: [...trace],
					})
				}
			}
			expect(runs).toMatchSnapshot()
		},
	)
})
