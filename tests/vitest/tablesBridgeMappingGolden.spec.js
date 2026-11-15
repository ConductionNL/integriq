/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the column and mapping helpers of the tables bridge:
 * columns, config blobs and mapping writes of every shape are run through the
 * exported functions and compared with
 * tests/vitest/golden/tablesBridgeMapping.json.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
 */
import { afterAll, describe, it } from 'vitest'
import { goldenFile } from './golden/goldenFile.js'
import {
	extractResults,
	mapColumnDescriptors,
	mappedValueFor,
	mapTableOptions,
	normaliseColumn,
	readColumnMapping,
	upsertColumnMapping,
} from '@/views/Synchronization/tablesBridge.js'

const golden = goldenFile(
	new URL('./golden/tablesBridgeMapping.json', import.meta.url),
)
afterAll(() => golden.save())

const COLUMNS = {
	undefined,
	null: null,
	zero: 0,
	emptyString: '',
	empty: {},
	full: {
		id: '7',
		title: 'Amount',
		type: 'number',
		subtype: 'progress',
		mandatory: 1,
		constraints: { numberDecimals: 2 },
	},
	nullFields: {
		id: null,
		title: null,
		type: null,
		subtype: null,
		mandatory: null,
		constraints: null,
	},
	undefinedSubtype: { subtype: undefined },
	zeroSubtype: { subtype: 0 },
	emptySubtype: { subtype: '' },
	falseSubtype: { subtype: false },
	numericTitle: { id: 3, title: 12, type: 5 },
	constraintsString: { constraints: 'x' },
	constraintsArray: { constraints: [1] },
	constraintsZero: { constraints: 0 },
	idNaN: { id: 'abc' },
	idZero: { id: 0, mandatory: 'yes' },
}

const CONFIGS = {
	undefined,
	null: null,
	string: 'x',
	array: [{ column: 'a', value: 'b' }],
	empty: {},
	noArray: { columnMapping: 'x' },
	mappingObject: { columnMapping: { column: 'a' } },
	functionEntry: {
		columnMapping: [
			Object.assign(function entry() {}, { column: 'F', value: 'v' }),
		],
	},
	emptyMapping: { columnMapping: [], other: 1 },
	mixed: {
		other: true,
		columnMapping: [
			{ column: 'A', value: '{{ a }}' },
			null,
			'x',
			{ column: 3, value: 'n' },
			{ value: 'no column' },
			{ column: 'B', value: null },
			{ column: 'C' },
			{ column: 'A', value: 'second' },
			{ column: '', value: 'blank' },
		],
	},
}

const TITLES = ['A', 'B', 'C', 'D', '', 3]
const VALUES = [undefined, null, '', '   ', ' x ', 'y', 5, { value: 'z' }]

describe('tablesBridge mapping golden', () => {
	it.each(Object.entries(COLUMNS))('normaliseColumn %s', (name, column) => {
		golden.check(`normaliseColumn ${name}`, normaliseColumn(column))
	})

	it('mapColumnDescriptors', () => {
		golden.check(
			'mapColumnDescriptors list',
			mapColumnDescriptors(Object.values(COLUMNS)),
		)
		golden.check('mapColumnDescriptors object', mapColumnDescriptors(COLUMNS))
		golden.check('mapColumnDescriptors null', mapColumnDescriptors(null))
	})

	it.each(Object.entries(CONFIGS))('config %s', (name, config) => {
		const before = JSON.stringify(config)
		golden.check(`readColumnMapping ${name}`, readColumnMapping(config))
		for (const title of TITLES) {
			golden.check(
				`mappedValueFor ${name} ${title}`,
				mappedValueFor(config, title),
			)
			VALUES.forEach((value, index) => {
				const result = upsertColumnMapping(config, title, value)
				golden.check(`upsert ${name} ${title} ${index}`, {
					result,
					isArray: Array.isArray(result),
					same: result === config,
					hasKey: Object.hasOwn(result, 'columnMapping'),
				})
			})
		}
		golden.check(`unchanged ${name}`, JSON.stringify(config) === before)
	})

	it('extractResults and mapTableOptions', () => {
		const bodies = [
			undefined,
			null,
			[],
			[{ id: 1 }],
			{ results: [{ id: 2 }] },
			{ results: 'x' },
			{},
			'abc',
		]
		bodies.forEach((body, index) => {
			golden.check(`extractResults ${index}`, extractResults(body))
		})
		golden.check(
			'mapTableOptions',
			mapTableOptions([
				null,
				{},
				{ id: null },
				{ id: 0 },
				{ id: '4', title: 'T' },
				{ id: 5, title: '' },
				{ id: 'x' },
			]),
		)
		golden.check('mapTableOptions object', mapTableOptions({ id: 1 }))
	})
})
