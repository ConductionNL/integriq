/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for run() of the mapping test panel
 * (src/components/mapping/MappingResultPanel.vue).
 *
 * run() executes on a plain context built from the component's data() and
 * methods, against a scripted mapping-test endpoint. Each scenario records
 * the requests, emitted events and the result state after every step; the
 * snapshot pins that trace, overlapping runs included.
 */
import { describe, expect, it, vi } from 'vitest'

const http = { calls: [], pending: [] }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: () => Promise.resolve({ data: [] }),
		post: (url, body) => {
			http.calls.push(['post', url, body])
			return new Promise((resolve, reject) => {
				http.pending.push({ resolve, reject })
			})
		},
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: () => {},
	showSuccess: () => {},
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
}))

const panel = (await import('@/components/mapping/MappingResultPanel.vue')).default

const FIELDS = [
	'running',
	'runError',
	'result',
	'isValid',
	'validationErrors',
	'validatedSchemaId',
	'runSequence',
]

/**
 * A plain context carrying the panel's data and methods.
 *
 * @param {object} props The props to start from.
 * @param {Array} log The trace to append to.
 * @return {object} The context.
 */
function context(props, log) {
	const ctx = {
		t: (app, text, vars) =>
			text.replace(/\{(\w+)\}/g, (m, k) =>
				vars && k in vars ? String(vars[k]) : m,
			),
	}
	Object.assign(ctx, props)
	Object.assign(ctx, panel.data.call(ctx))
	for (const [name, fn] of Object.entries(panel.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.$emit = (...args) => log.push(['emit', ...args])
	return ctx
}

/**
 * Let pending promise callbacks run.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
	}
}

const MAPPING = { mapping: { a: '{{ b }}' } }

const ANSWERS = {
	'result object': (p) =>
		p.resolve({ data: { resultObject: { a: 1 }, isValid: true } }),
	'invalid result': (p) =>
		p.resolve({
			data: {
				resultObject: { a: 2 },
				isValid: false,
				validationErrors: ['a is wrong'],
			},
		}),
	'bare body': (p) => p.resolve({ data: { a: 3 } }),
	'empty body': (p) => p.resolve({ data: null }),
	'no data': (p) => p.resolve({}),
	'error with message': (p) =>
		p.reject({ response: { status: 422, data: { message: 'bad mapping' } } }),
	'error with error': (p) =>
		p.reject({ response: { status: 500, data: { error: 'boom' } } }),
	'error with both': (p) =>
		p.reject({
			response: { status: 409, data: { message: 'first', error: 'second' } },
		}),
	'transport error': (p) => p.reject(new Error('Network Error')),
	'bare error': (p) => p.reject({}),
}

const SCENARIOS = {
	'no mapping': { props: { mapping: null, inputObject: '{}' } },
	'invalid json': { props: { ...MAPPING, inputObject: '{nope' } },
	'empty input': { props: { ...MAPPING, inputObject: '   ' } },
	'object input': { props: { ...MAPPING, inputObject: { b: 1 } } },
	'with schema': {
		props: { ...MAPPING, inputObject: '{"b":2}' },
		schema: { id: 'sch-1' },
	},
	'schema without id': {
		props: { ...MAPPING, inputObject: '{"b":2}' },
		schema: { label: 'x' },
	},
}

describe('mapping result panel run golden', () => {
	for (const [scenario, setup] of Object.entries(SCENARIOS)) {
		it.each(Object.keys(ANSWERS))(
			`${scenario}, server answers %s`,
			async (answer) => {
				const log = []
				http.calls = []
				http.pending = []
				const ctx = context(setup.props, log)
				ctx.selectedSchema = setup.schema ?? null
				const step = (label) =>
					log.push([
						'state',
						label,
						Object.fromEntries(FIELDS.map((f) => [f, ctx[f]])),
					])
				const done = ctx.run()
				step('started')
				if (http.pending[0]) ANSWERS[answer](http.pending[0])
				await done
				await flush()
				step('settled')
				log.push(['calls', http.calls])
				expect(log).toMatchSnapshot()
			},
		)
	}

	it.each(Object.keys(ANSWERS))(
		'an older run answering %s after a newer one leaves the newer state',
		async (answer) => {
			const log = []
			http.calls = []
			http.pending = []
			const ctx = context({ ...MAPPING, inputObject: '{"b":1}' }, log)
			const step = (label) =>
				log.push([
					'state',
					label,
					Object.fromEntries(FIELDS.map((f) => [f, ctx[f]])),
				])
			const first = ctx.run()
			const second = ctx.run()
			step('both started')
			ANSWERS[answer](http.pending[0])
			await first
			await flush()
			step('older settled')
			http.pending[1].resolve({ data: { resultObject: { newest: true } } })
			await second
			await flush()
			step('newer settled')
			expect(log).toMatchSnapshot()
		},
	)
})
