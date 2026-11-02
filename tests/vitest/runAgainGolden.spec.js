/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for runAgain() (src/handlers/runAgain.js): every run record and
 * server outcome is traced through the request, the notices, the notice's
 * click and the router, and the snapshot pins that trace.
 */
import { describe, expect, it, vi } from 'vitest'

const trace = []
const server = { outcome: 'ok' }

const ANSWERS = {
	ok: () => Promise.resolve({ data: { runId: 'run-7' } }),
	'ok without id': () => Promise.resolve({ data: {} }),
	'ok without body': () => Promise.resolve({}),
	'error with error': () =>
		Promise.reject({ response: { data: { error: 'locked' } } }),
	'transport error': () => Promise.reject(new Error('Network Error')),
	'bare error': () => Promise.reject({}),
}

vi.mock('@nextcloud/axios', () => ({
	default: {
		post: (url, body) => {
			trace.push(['post', url, body])
			return ANSWERS[server.outcome]()
		},
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => trace.push(['error', text]),
	showSuccess: (text, options) => {
		trace.push(['success', text, Object.keys(options || {})])
		options?.onClick?.()
	},
}))

vi.mock('@/handlers/routerRef.js', () => ({
	getRouter: () => ({
		push: (location) => {
			trace.push(['push', location])
			return Promise.resolve()
		},
	}),
}))

const { runAgain } = await import('@/handlers/runAgain.js')

const RUNS = [
	null,
	undefined,
	{},
	{ synchronizationId: '' },
	{ synchronizationId: 'sync 1/2' },
]

describe('runAgain golden', () => {
	it.each(Object.keys(ANSWERS))(
		'runs again as recorded when the server answers %s',
		async (outcome) => {
			const rows = []
			server.outcome = outcome
			for (const run of RUNS) {
				trace.length = 0
				const returned = await runAgain(run)
				rows.push({ run, returned, trace: [...trace] })
			}
			expect(rows).toMatchSnapshot()
		},
	)
})
