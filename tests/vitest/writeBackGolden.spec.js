/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the write-back helpers of a synchronization: stored
 * write-backs and editor rows of every shape are converted both ways and
 * compared with tests/vitest/golden/writeBack.json.
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
 */
import { afterAll, describe, it } from 'vitest'
import { goldenFile } from './golden/goldenFile.js'
import {
	writeBackFromRows,
	writeBackRows,
} from '@/views/Synchronization/writeBack.js'

const golden = goldenFile(new URL('./golden/writeBack.json', import.meta.url))
afterAll(() => golden.save())

/**
 * The result of a call, or the kind of error it threw.
 *
 * @param {Function} run The call.
 * @return {object} The outcome.
 */
function outcome(run) {
	try {
		return { result: run() }
	} catch (error) {
		return { threw: error.constructor.name }
	}
}

const STORED = {
	undefined,
	null: null,
	string: 'x',
	array: [{ onSuccess: { a: 1 } }],
	empty: {},
	bothNull: { onSuccess: null, onFailure: null },
	arraySide: { onSuccess: ['a'], onFailure: { status: '{{ status }}' } },
	stringSide: { onSuccess: 'x' },
	full: {
		onSuccess: { status: 'sent', remoteId: '{{ targetId }}', n: 0, z: null },
		onFailure: { status: 'failed', error: '{{ error.message }}' },
		other: { a: 1 },
	},
	emptySides: { onSuccess: {}, onFailure: {} },
}

const ROWS = {
	undefined,
	null: null,
	empty: {},
	emptySides: { onSuccess: [], onFailure: [] },
	nullSides: { onSuccess: null, onFailure: undefined },
	typing: {
		onSuccess: [
			{ field: '', value: 'x' },
			{ field: '   ', value: 'y' },
			{ field: null, value: 'z' },
			{ value: 'w' },
		],
	},
	full: {
		onSuccess: [
			{ field: ' status ', value: 'sent' },
			{ field: 'remoteId', value: '{{ targetId }}' },
			{ field: 'zero', value: 0 },
			{ field: 'blank', value: null },
			{ field: 'missing' },
			{ field: 'falsy', value: false },
		],
		onFailure: [{ field: 'status', value: 'failed' }],
		other: [{ field: 'ignored', value: 1 }],
	},
	duplicates: {
		onFailure: [
			{ field: 'a', value: 1 },
			{ field: ' a', value: 2 },
		],
	},
	numericField: { onSuccess: [{ field: 7, value: 'seven' }] },
	falseField: { onSuccess: [{ field: false, value: 'f' }] },
	onlyFailure: { onSuccess: [{ field: '' }], onFailure: [{ field: 'x' }] },
	nullRow: { onSuccess: [null] },
	stringSide: { onSuccess: 'ab' },
}

describe('writeBack golden', () => {
	it.each(Object.entries(STORED))('writeBackRows %s', (name, stored) => {
		const rows = outcome(() => writeBackRows(stored))
		golden.check(`writeBackRows ${name}`, rows)
		golden.check(
			`round trip ${name}`,
			outcome(() => writeBackFromRows(rows.result)),
		)
	})

	it.each(Object.entries(ROWS))('writeBackFromRows %s', (name, rows) => {
		golden.check(
			`writeBackFromRows ${name}`,
			outcome(() => writeBackFromRows(rows)),
		)
	})
})
