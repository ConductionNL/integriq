/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for fetchOpenRegisterCollection() of the rule action forms:
 * responses of every shape, failures and argument combinations are run
 * through it with a scripted axios, and the requests, warnings and options
 * are compared with tests/vitest/golden/actionFormsShared.json.
 */
import { afterAll, afterEach, describe, it, vi } from 'vitest'
import { goldenFile } from './golden/goldenFile.js'

const http = { calls: [], answer: null }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (url, config) => {
			http.calls.push([url, config])
			return http.answer()
		},
	},
}))

const { fetchOpenRegisterCollection } =
	await import('@/views/Rule/actionForms/shared.js')

const golden = goldenFile(
	new URL('./golden/actionFormsShared.json', import.meta.url),
)
afterAll(() => golden.save())

const ROWS = [
	{ id: 'a', name: 'Named', title: 'Titled' },
	{ uuid: 'u-1', title: 'Only title' },
	{ id: 7 },
	{ id: 0, uuid: 'u-0' },
	{ uuid: 'u-2' },
	{},
	{ id: '', name: '', title: '' },
]

const ANSWERS = {
	results: () => Promise.resolve({ data: { results: ROWS } }),
	bare: () => Promise.resolve({ data: ROWS }),
	resultsNotArray: () => Promise.resolve({ data: { results: 'x' } }),
	emptyObject: () => Promise.resolve({ data: {} }),
	nullData: () => Promise.resolve({ data: null }),
	noData: () => Promise.resolve({}),
	noResponse: () => Promise.resolve(undefined),
	string: () => Promise.resolve({ data: 'abc' }),
	nullRow: () => Promise.resolve({ data: [null] }),
	rejects: () => Promise.reject(new Error('down')),
	throws: () => {
		throw new Error('sync')
	},
}

const ARGUMENTS = {
	schemaOnly: ['synchronization'],
	register: ['mapping', 'other'],
	limit: ['rule', 'integriq', 20],
	undefinedDefaults: ['rule', undefined, undefined],
	nullRegister: ['rule', null, null],
}

describe('fetchOpenRegisterCollection golden', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it.each(Object.keys(ANSWERS))('answer %s', async (name) => {
		for (const [label, args] of Object.entries(ARGUMENTS)) {
			const warnings = []
			vi.spyOn(console, 'warn').mockImplementation((text, error) =>
				warnings.push([text, error?.message ?? error?.constructor?.name]),
			)
			http.calls = []
			http.answer = ANSWERS[name]
			const options = await fetchOpenRegisterCollection(...args)
			golden.check(`${name} ${label}`, {
				options,
				calls: http.calls,
				warnings,
			})
			vi.restoreAllMocks()
		}
	})
})
