/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the question helpers of the forms bridge: questions and
 * question lists of every shape are run through the exported functions and
 * compared with tests/vitest/golden/formsBridge.json.
 *
 * @spec openspec/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009
 */
import { afterAll, describe, it } from 'vitest'
import { goldenFile } from './golden/goldenFile.js'
import {
	ambiguousQuestionTexts,
	extractResults,
	isArrayValuedQuestion,
	mapFormOptions,
	mapQuestionDescriptors,
	normaliseQuestion,
} from '@/views/Synchronization/formsBridge.js'

const golden = goldenFile(new URL('./golden/formsBridge.json', import.meta.url))
afterAll(() => golden.save())

const QUESTIONS = {
	undefined,
	null: null,
	zero: 0,
	empty: {},
	full: { id: '3', text: 'Name', name: 'name', type: 'short' },
	nullFields: { id: null, text: null, name: null, type: null },
	numbers: { id: 4, text: 5, name: 6, type: 7 },
	falsy: { id: 0, text: '', name: false, type: 0 },
	idText: { id: 'abc' },
	multiple: { type: 'multiple' },
	multipleUnique: { type: 'multiple_unique' },
	upperMultiple: { type: 'MULTIPLE' },
}

const LISTS = {
	undefined,
	null: null,
	string: 'abc',
	set: new Set([{ text: 'A' }, { text: 'A' }]),
	empty: [],
	unique: [{ text: 'A' }, { text: 'B' }],
	shared: [{ text: 'A' }, { text: 'B' }, { text: 'A' }, { text: 'A' }],
	blanks: [{ text: '' }, { text: '' }, {}, null],
	numbers: [{ text: 1 }, { text: '1' }, { text: 2 }],
	spaces: [{ text: 'A ' }, { text: 'A' }, { text: ' ' }, { text: ' ' }],
	two: [{ text: 'X' }, { text: 'Y' }, { text: 'Y' }, { text: 'X' }, { text: 'Z' }],
}

describe('formsBridge golden', () => {
	it.each(Object.entries(QUESTIONS))('question %s', (name, question) => {
		golden.check(`normaliseQuestion ${name}`, normaliseQuestion(question))
		golden.check(`isArrayValued ${name}`, isArrayValuedQuestion(question))
	})

	it.each(Object.entries(LISTS))('list %s', (name, list) => {
		golden.check(`ambiguous ${name}`, [...ambiguousQuestionTexts(list)])
		golden.check(`descriptors ${name}`, mapQuestionDescriptors(list))
		golden.check(`results ${name}`, extractResults(list))
	})

	it('maps form options', () => {
		golden.check(
			'mapFormOptions',
			mapFormOptions([
				null,
				{},
				{ id: null },
				{ id: 0 },
				{ id: '4', title: 'Survey' },
				{ id: 5, title: '' },
			]),
		)
		golden.check('mapFormOptions object', mapFormOptions({ id: 1 }))
		golden.check('extractResults results', extractResults({ results: [1] }))
	})
})
