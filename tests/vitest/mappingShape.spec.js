/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The shape normalisers for a mapping's `mapping`, `cast` and `unset` fields
 * (src/components/mapping/mappingShape.js).
 *
 * @spec openspec/specs/mapping-editor-ui/spec.md
 */
import { describe, expect, it } from 'vitest'
import { asObjectMap, asUnsetList } from '@/components/mapping/mappingShape.js'

describe('asObjectMap', () => {
	it.each([
		['null', null],
		['undefined', undefined],
		['empty string', ''],
		['zero', 0],
		['false', false],
	])('returns an empty object for %s', (_label, raw) => {
		expect(asObjectMap(raw)).toEqual({})
	})

	it('returns the same object when given a plain object', () => {
		const raw = { a: '{{ b }}', c: 'd' }
		expect(asObjectMap(raw)).toBe(raw)
	})

	it('parses a JSON object string', () => {
		expect(asObjectMap('{"name":"{{ title }}","age":"integer"}')).toEqual({
			name: '{{ title }}',
			age: 'integer',
		})
	})

	it.each([
		['a JSON array', '["a","b"]'],
		['a JSON number', '42'],
		['a JSON string', '"text"'],
		['JSON null', 'null'],
		['invalid JSON', '{not json'],
	])('returns an empty object for %s', (_label, raw) => {
		expect(asObjectMap(raw)).toEqual({})
	})

	it('returns an empty object for an array value', () => {
		expect(asObjectMap(['a', 'b'])).toEqual({})
	})

	it('returns an empty object for a non-zero number', () => {
		expect(asObjectMap(7)).toEqual({})
	})
})

describe('asUnsetList', () => {
	it.each([
		['null', null],
		['undefined', undefined],
		['empty string', ''],
		['false', false],
	])('returns an empty list for %s', (_label, raw) => {
		expect(asUnsetList(raw)).toEqual([])
	})

	it('keeps only non-empty strings of an array', () => {
		expect(asUnsetList(['a', '', 3, null, 'b', { c: 1 }])).toEqual(['a', 'b'])
	})

	it('splits a comma-separated string, trimming the spaces around commas', () => {
		expect(asUnsetList('a, b ,c ,  d')).toEqual(['a', 'b', 'c', 'd'])
	})

	it('drops empty entries from a comma-separated string', () => {
		expect(asUnsetList('a,,b,')).toEqual(['a', 'b'])
	})

	it('returns a single-entry list for a string without commas', () => {
		expect(asUnsetList('secret')).toEqual(['secret'])
	})

	it('returns an empty list for an object', () => {
		expect(asUnsetList({ a: 1 })).toEqual([])
	})

	it('returns an empty list for a number', () => {
		expect(asUnsetList(5)).toEqual([])
	})
})
