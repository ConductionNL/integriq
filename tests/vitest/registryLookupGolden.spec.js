/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the wording rules of the registry lookup dialog: every
 * provenance shape and value shape is run through the exported functions and
 * compared with tests/vitest/golden/registryLookup.json.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-resolved-value-carries-its-provenance-req-rfs-003
 */
import { afterAll, describe, it } from 'vitest'
import { goldenFile } from './golden/goldenFile.js'
import {
	ageText,
	listOf,
	provenanceState,
	valueRows,
} from '@/dialogs/registryLookup.js'

const golden = goldenFile(new URL('./golden/registryLookup.json', import.meta.url))
afterAll(() => golden.save())

const calls = []
/**
 * A translate function that records its calls.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @param {object} [vars] The placeholders.
 * @return {string} The text with its placeholders as JSON.
 */
function t(app, text, vars) {
	calls.push([app, text, vars])
	return vars ? `${text} ${JSON.stringify(vars)}` : text
}

/**
 * The result of one call together with every translate call it made.
 *
 * @param {Function} run The call.
 * @return {object} The result and the calls.
 */
function traced(run) {
	calls.length = 0
	const result = run()
	return { result, calls: [...calls] }
}

const AGES = [
	undefined,
	null,
	'',
	'x',
	-5,
	0,
	29.4,
	59.6,
	60,
	89,
	3599,
	3600,
	86399,
	86400,
	200000,
	'120',
]

const PROVENANCES = {
	none: undefined,
	null: null,
	empty: {},
	unreachableNoAge: { unreachable: true },
	unreachableNullAge: { unreachable: true, cacheAgeSeconds: null },
	unreachableZeroAge: { unreachable: true, cacheAgeSeconds: 0 },
	unreachableAged: { unreachable: true, cacheAgeSeconds: 7200 },
	unreachableAndLive: { unreachable: true, live: true, cacheAgeSeconds: 5 },
	unreachableFalsy: { unreachable: 0, cacheAgeSeconds: 70 },
	live: { live: true, cacheAgeSeconds: 3 },
	liveTruthy: { live: 'yes' },
	cachedNoAge: { live: false },
	cachedAged: { live: false, cacheAgeSeconds: 100000 },
	cachedString: { cacheAgeSeconds: '45' },
}

const VALUES = {
	undefined,
	null: null,
	emptyString: '',
	string: 'Amsterdam',
	zero: 0,
	number: 42,
	boolFalse: false,
	boolTrue: true,
	emptyArray: [],
	array: [1, 'a', null],
	emptyObject: {},
	flat: { a: 'x', b: 2, c: true, d: null, e: undefined, f: '' },
	nested: { a: { b: 1 }, c: [1, 2], d: [], e: {} },
	emptyKey: { '': 'blank' },
}

const LISTS = {
	undefined,
	null: null,
	array: [1, 2],
	results: { results: [3] },
	resultsNotArray: { results: 'x' },
	object: { a: 1 },
	string: 'abc',
}

describe('registryLookup golden', () => {
	it.each(AGES.map((age, index) => [index, age]))('ageText %i', (index, age) => {
		golden.check(
			`ageText ${index}`,
			traced(() => ageText(age, t)),
		)
	})

	it.each(Object.entries(PROVENANCES))(
		'provenanceState %s',
		(name, provenance) => {
			golden.check(
				`provenanceState ${name}`,
				traced(() => provenanceState(provenance, t)),
			)
		},
	)

	it.each(Object.entries(VALUES))('valueRows %s', (name, value) => {
		golden.check(`valueRows ${name}`, valueRows(value))
	})

	it.each(Object.entries(LISTS))('listOf %s', (name, data) => {
		golden.check(`listOf ${name}`, listOf(data))
	})
})
