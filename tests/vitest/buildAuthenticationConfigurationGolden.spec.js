/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the authentication block of a rule's save payload: key
 * rows of every shape, compared with
 * tests/vitest/golden/buildAuthenticationConfiguration.json.
 */
import { afterAll, describe, it } from 'vitest'
import { goldenFile } from './golden/goldenFile.js'
import { buildAuthenticationConfiguration } from '@/modals/Rule/buildAuthenticationConfiguration.js'

const golden = goldenFile(
	new URL('./golden/buildAuthenticationConfiguration.json', import.meta.url),
)
afterAll(() => golden.save())

/**
 * The result of a call, or the kind of error it threw, with whether `keys` is present.
 *
 * @param {Function} run The call.
 * @return {object} The outcome.
 */
function outcome(run) {
	try {
		const result = run()
		return { result, hasKeys: Object.hasOwn(result, 'keys') }
	} catch (error) {
		return { threw: error.constructor.name }
	}
}

const ROWS = {
	omitted: undefined,
	null: null,
	empty: [],
	seedRow: [{ apiKey: '', user: null }],
	halfKey: [{ apiKey: 'k-1' }],
	halfUser: [{ apiKey: '', user: { id: 'alice' } }],
	userWithoutId: [{ apiKey: 'k-1', user: {} }],
	one: [{ apiKey: 'k-1', user: { id: 'alice' } }],
	mixed: [
		{ apiKey: 'k-1', user: { id: 'alice' } },
		{ apiKey: '', user: { id: 'bob' } },
		{ apiKey: 'k-3', user: { id: 'carol' } },
	],
	sameKey: [
		{ apiKey: 'k-1', user: { id: 'alice' } },
		{ apiKey: 'k-1', user: { id: 'bob' } },
	],
	zeroId: [{ apiKey: 'k-1', user: { id: 0 } }],
	nullRow: [null],
}

describe('buildAuthenticationConfiguration golden', () => {
	it('builds every key row shape', () => {
		for (const [name, apiKeys] of Object.entries(ROWS)) {
			golden.check(
				`rows/${name}`,
				outcome(() =>
					buildAuthenticationConfiguration({
						type: 'api-key',
						users: ['alice'],
						groups: ['admin'],
						apiKeys,
					}),
				),
			)
		}
		golden.check(
			'no-arguments-but-type',
			outcome(() => buildAuthenticationConfiguration({ type: 'basic' })),
		)
	})
})
