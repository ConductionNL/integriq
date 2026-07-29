/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the DOM-free forms-bridge editor helpers
 * (src/views/Synchronization/formsBridge.js), covering the load-bearing
 * behaviour of the form picker (SyncConfigWidget.vue) and the field-mapping
 * helper (FormsFieldMapping.vue) without a DOM mount — the repo's vitest
 * harness is node-env and mounts no .vue (see vitest.config.js), mirroring
 * tests/vitest/tablesBridge.spec.js.
 *
 * @spec openspec/changes/nextcloud-forms-connector/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008
 * @spec openspec/changes/nextcloud-forms-connector/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009
 */

import { describe, it, expect } from 'vitest'
import {
	NEXTCLOUD_FORM_KIND,
	MULTI_VALUE_QUESTION_TYPES,
	extractResults,
	mapFormOptions,
	normaliseQuestion,
	mapQuestionDescriptors,
	isArrayValuedQuestion,
	ambiguousQuestionTexts,
} from '../../src/views/Synchronization/formsBridge.js'

describe('kind discriminator', () => {
	it('matches the backend value', () => {
		expect(NEXTCLOUD_FORM_KIND).toBe('nextcloud-form')
	})
})

describe('MULTI_VALUE_QUESTION_TYPES', () => {
	it('lists multiple and multiple_unique', () => {
		expect(MULTI_VALUE_QUESTION_TYPES).toEqual(['multiple', 'multiple_unique'])
	})
})

describe('extractResults', () => {
	it('unwraps a {results:[...]} envelope', () => {
		expect(extractResults({ results: [{ id: 1 }] })).toEqual([{ id: 1 }])
	})
	it('accepts a bare array', () => {
		expect(extractResults([{ id: 2 }])).toEqual([{ id: 2 }])
	})
	it('soft-fails to [] on unexpected shapes', () => {
		expect(extractResults(null)).toEqual([])
		expect(extractResults({ nope: true })).toEqual([])
		expect(extractResults(42)).toEqual([])
	})
})

describe('mapFormOptions', () => {
	it('maps forms to numeric-id NcSelect options', () => {
		const opts = mapFormOptions([{ id: 42, title: 'Contact form' }])
		expect(opts).toEqual([{ id: 42, label: 'Contact form' }])
		expect(typeof opts[0].id).toBe('number')
	})
	it('falls back to the id as the label when title is absent', () => {
		expect(mapFormOptions([{ id: 7 }])).toEqual([{ id: 7, label: '7' }])
	})
	it('drops entries with no id and tolerates non-arrays', () => {
		expect(mapFormOptions([{ title: 'x' }, null, { id: 3, title: 'y' }])).toEqual([{ id: 3, label: 'y' }])
		expect(mapFormOptions(undefined)).toEqual([])
	})
})

describe('normaliseQuestion / mapQuestionDescriptors', () => {
	it('normalises a full question', () => {
		const q = normaliseQuestion({ id: 7, text: 'Company name', name: 'company_name', type: 'short' })
		expect(q).toEqual({ id: 7, text: 'Company name', name: 'company_name', type: 'short' })
	})
	it('tolerates missing fields', () => {
		const q = normaliseQuestion({})
		expect(q).toEqual({ id: 0, text: '', name: '', type: '' })
	})
	it('maps a list', () => {
		expect(mapQuestionDescriptors([{ id: 1, text: 'A', type: 'short' }])).toHaveLength(1)
		expect(mapQuestionDescriptors(null)).toEqual([])
	})
})

describe('isArrayValuedQuestion', () => {
	it('flags multiple as array-valued', () => {
		expect(isArrayValuedQuestion({ type: 'multiple' })).toBe(true)
	})
	it('flags multiple_unique as array-valued', () => {
		expect(isArrayValuedQuestion({ type: 'multiple_unique' })).toBe(true)
	})
	it('does not flag scalar types', () => {
		expect(isArrayValuedQuestion({ type: 'short' })).toBe(false)
		expect(isArrayValuedQuestion({ type: 'dropdown' })).toBe(false)
	})
})

describe('ambiguousQuestionTexts', () => {
	it('flags a text shared by two or more questions', () => {
		const questions = [
			{ id: 12, text: 'Comments', type: 'long' },
			{ id: 19, text: 'Comments', type: 'long' },
			{ id: 7, text: 'Company name', type: 'short' },
		]
		const ambiguous = ambiguousQuestionTexts(questions)
		expect(ambiguous.has('Comments')).toBe(true)
		expect(ambiguous.has('Company name')).toBe(false)
	})
	it('returns an empty set when no texts are ambiguous', () => {
		const questions = [{ id: 7, text: 'Company name', type: 'short' }]
		expect(ambiguousQuestionTexts(questions).size).toBe(0)
	})
	it('ignores empty-text questions and tolerates a non-array', () => {
		expect(ambiguousQuestionTexts([{ id: 1, text: '' }, { id: 2, text: '' }]).size).toBe(0)
		expect(ambiguousQuestionTexts(undefined).size).toBe(0)
	})
})
