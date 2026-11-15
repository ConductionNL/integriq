/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for load() and save() of the action-authorization matrix
 * editor (src/views/admin/ActionAuthMatrix.vue).
 *
 * Both run on a plain context for every server answer shape. Each run records
 * the requests, toasts, console errors and the resulting state; the snapshot
 * pins that trace.
 */
import { describe, expect, it, vi } from 'vitest'

const trace = []
const server = { get: null, put: null }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (url) => {
			trace.push(['get', url])
			return server.get instanceof Error ? Promise.reject(server.get) : Promise.resolve({ data: server.get })
		},
		put: (url, body) => {
			trace.push(['put', url, JSON.parse(JSON.stringify(body))])
			return server.put instanceof Error ? Promise.reject(server.put) : Promise.resolve({ data: server.put })
		},
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => trace.push(['error', text]),
	showSuccess: (text) => trace.push(['success', text]),
}))

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {} }))

const matrix = (await import('@/views/admin/ActionAuthMatrix.vue')).default

/**
 * A plain context carrying the editor's data and methods.
 *
 * @param {object} state Data to start from.
 * @return {object} The context.
 */
function context(state) {
	const ctx = { ...matrix.data(), ...state, t: (app, text) => text }
	for (const [name, fn] of Object.entries(matrix.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

/**
 * The state a run leaves behind.
 *
 * @param {object} ctx The context.
 * @return {object} The state.
 */
function stateOf(ctx) {
	return { loading: ctx.loading, saving: ctx.saving, error: ctx.error, actions: ctx.actions, groups: ctx.groups, matrix: ctx.matrix }
}

const GETS = [
	{ actions: ['sync.run', 'flow.edit', 'job.run'], groups: ['admin', 'ops', 'devs'], matrix: { 'sync.run': ['ops'], 'flow.edit': 'ops', other: ['x'] } },
	{ actions: 'sync.run', groups: null, matrix: [] },
	{ actions: ['a'], groups: ['g'], matrix: null },
	{},
	new Error('down'),
]

const PUTS = [
	{ matrix: { 'sync.run': ['admin', 'ops'], 'flow.edit': 'admin' } },
	{ matrix: 'no' },
	null,
	{},
	new Error('refused'),
]

describe('action matrix golden', () => {
	it('loads as recorded', async () => {
		const errors = vi.spyOn(console, 'error').mockImplementation((text) => trace.push(['console', text]))
		const runs = []
		for (const answer of GETS) {
			trace.length = 0
			server.get = answer
			const ctx = context({})
			await ctx.load()
			runs.push({ answer: answer instanceof Error ? 'Error' : answer, state: stateOf(ctx), trace: [...trace] })
		}
		errors.mockRestore()
		expect(runs).toMatchSnapshot()
	})

	it('saves as recorded', async () => {
		const errors = vi.spyOn(console, 'error').mockImplementation((text) => trace.push(['console', text]))
		const runs = []
		const starts = [
			{ actions: ['sync.run', 'flow.edit'], matrix: { 'sync.run': ['ops', 'admin', 'devs'], 'flow.edit': [] } },
			{ actions: ['sync.run', 'job.run'], matrix: { 'sync.run': ['admin'] } },
			{ actions: [], matrix: { stray: ['ops'] } },
		]
		for (const start of starts) {
			for (const answer of PUTS) {
				trace.length = 0
				server.put = answer
				const ctx = context(JSON.parse(JSON.stringify(start)))
				const pending = ctx.save()
				const savingDuring = ctx.saving
				await pending
				runs.push({ start, answer: answer instanceof Error ? 'Error' : answer, savingDuring, state: stateOf(ctx), trace: [...trace] })
			}
		}
		errors.mockRestore()
		expect(runs).toMatchSnapshot()
	})
})
