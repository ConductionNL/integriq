/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the DOM-free tables-bridge editor helpers
 * (src/views/Synchronization/tablesBridge.js), covering the load-bearing
 * behaviour of the table picker (SyncConfigWidget.vue) and the column-mapping
 * helper (TablesColumnMapping.vue) without a DOM mount — the repo's vitest
 * harness is node-env and mounts no .vue (see vitest.config.js).
 *
 * @spec openspec/changes/tables-bridge/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006
 * @spec openspec/changes/tables-bridge/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007
 */

import { describe, it, expect } from 'vitest'
import {
	NEXTCLOUD_TABLE_KIND,
	extractResults,
	mapTableOptions,
	normaliseColumn,
	mapColumnDescriptors,
	columnTypeHint,
	readColumnMapping,
	upsertColumnMapping,
	mappedValueFor,
} from '../../src/views/Synchronization/tablesBridge.js'

describe('kind discriminator', () => {
	it('matches the backend value', () => {
		expect(NEXTCLOUD_TABLE_KIND).toBe('nextcloud-table')
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

describe('mapTableOptions', () => {
	it('maps tables to numeric-id NcSelect options', () => {
		const opts = mapTableOptions([{ id: 42, title: 'Vendor Invoices', ownerType: 'user' }])
		expect(opts).toEqual([{ id: 42, label: 'Vendor Invoices' }])
		expect(typeof opts[0].id).toBe('number')
	})
	it('falls back to the id as the label when title is absent', () => {
		expect(mapTableOptions([{ id: 7 }])).toEqual([{ id: 7, label: '7' }])
	})
	it('drops entries with no id and tolerates non-arrays', () => {
		expect(mapTableOptions([{ title: 'x' }, null, { id: 3, title: 'y' }])).toEqual([{ id: 3, label: 'y' }])
		expect(mapTableOptions(undefined)).toEqual([])
	})
})

describe('normaliseColumn / mapColumnDescriptors', () => {
	it('normalises a full column', () => {
		const c = normaliseColumn({ id: 7, title: 'Amount', type: 'number', subtype: null, mandatory: true, constraints: { numberDecimals: 2 } })
		expect(c).toEqual({ id: 7, title: 'Amount', type: 'number', subtype: null, mandatory: true, constraints: { numberDecimals: 2 } })
	})
	it('tolerates missing fields', () => {
		const c = normaliseColumn({})
		expect(c).toEqual({ id: 0, title: '', type: '', subtype: null, mandatory: false, constraints: {} })
	})
	it('maps a list', () => {
		expect(mapColumnDescriptors([{ id: 1, title: 'A', type: 'text' }])).toHaveLength(1)
		expect(mapColumnDescriptors(null)).toEqual([])
	})
})

describe('columnTypeHint', () => {
	it('describes a number column with decimals', () => {
		expect(columnTypeHint({ type: 'number', constraints: { numberDecimals: 2 } })).toBe('number (2 decimals)')
	})
	it('lists selection options', () => {
		expect(columnTypeHint({ type: 'selection', constraints: { selectionOptions: ['open', 'paid'] } })).toBe('selection: open, paid')
	})
	it('shows datetime subtype', () => {
		expect(columnTypeHint({ type: 'datetime', subtype: 'date' })).toBe('datetime (date)')
	})
	it('shows text max length', () => {
		expect(columnTypeHint({ type: 'text', constraints: { textMaxLength: 50 } })).toBe('text (max 50)')
	})
	it('flags usergroup as read-only', () => {
		expect(columnTypeHint({ type: 'usergroup' })).toContain('not writable')
	})
})

describe('column mapping read/write (title-keyed, REQ-001)', () => {
	it('reads a well-formed mapping', () => {
		const config = { columnMapping: [{ column: 'Amount', value: 'invoice.total' }] }
		expect(readColumnMapping(config)).toEqual([{ column: 'Amount', value: 'invoice.total' }])
	})
	it('soft-fails on a malformed mapping', () => {
		expect(readColumnMapping({ columnMapping: 'nope' })).toEqual([])
		expect(readColumnMapping(null)).toEqual([])
		expect(readColumnMapping({ columnMapping: [{ notColumn: 1 }] })).toEqual([])
	})
	it('upserts an entry keyed by column TITLE (not id)', () => {
		const next = upsertColumnMapping({}, 'Amount', 'invoice.total')
		expect(next.columnMapping).toEqual([{ column: 'Amount', value: 'invoice.total' }])
	})
	it('replaces an existing entry for the same title', () => {
		const config = { columnMapping: [{ column: 'Amount', value: 'old' }] }
		const next = upsertColumnMapping(config, 'Amount', 'new')
		expect(next.columnMapping).toEqual([{ column: 'Amount', value: 'new' }])
	})
	it('removes an entry (and the empty key) when value is cleared', () => {
		const config = { columnMapping: [{ column: 'Amount', value: 'x' }] }
		const next = upsertColumnMapping(config, 'Amount', '   ')
		expect(next.columnMapping).toBeUndefined()
	})
	it('preserves other config keys and does not mutate the input', () => {
		const config = { tableId: 42, columnMapping: [{ column: 'A', value: '1' }] }
		const next = upsertColumnMapping(config, 'B', '2')
		expect(next.tableId).toBe(42)
		expect(next.columnMapping).toHaveLength(2)
		// input untouched
		expect(config.columnMapping).toHaveLength(1)
	})
	it('looks up a mapped value by title', () => {
		const config = { columnMapping: [{ column: 'Amount', value: 'invoice.total' }] }
		expect(mappedValueFor(config, 'Amount')).toBe('invoice.total')
		expect(mappedValueFor(config, 'Missing')).toBe('')
	})
})
