/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the column hints of the tables bridge
 * (src/views/Synchronization/tablesBridge.js): every column shape below is
 * rendered through columnTypeHint() and the snapshot pins the hint text.
 */

import { describe, expect, it } from 'vitest'
import { columnTypeHint } from '../../src/views/Synchronization/tablesBridge.js'

const COLUMNS = [
	null,
	undefined,
	{},
	{ type: '' },
	{ type: 'number' },
	{ type: 'number', constraints: { numberDecimals: 2 } },
	{ type: 'number', constraints: { numberDecimals: 0 } },
	{ type: 'number', constraints: { numberDecimals: null } },
	{ type: 'number', constraints: 'oops' },
	{ type: 'selection' },
	{ type: 'selection', constraints: { selectionOptions: [] } },
	{ type: 'selection', constraints: { selectionOptions: 'open' } },
	{ type: 'selection', constraints: { selectionOptions: ['paid'] } },
	{ type: 'selection', constraints: { selectionOptions: ['open', 3, null] } },
	{ type: 'datetime' },
	{ type: 'datetime', subtype: 'date' },
	{ type: 'datetime', subtype: '' },
	{ type: 'datetime', subtype: 0 },
	{ type: 'text' },
	{ type: 'text', constraints: { textMaxLength: 255 } },
	{ type: 'text', constraints: { textMaxLength: undefined } },
	{ type: 'usergroup' },
	{ type: 'usergroup', constraints: { numberDecimals: 1 } },
	{ type: 'link' },
	{ type: 'constructor' },
	{ type: 'toString' },
	{ type: '__proto__' },
	{ type: 7 },
	{ type: null },
]

describe('columnTypeHint golden', () => {
	it('renders every column shape as recorded', () => {
		expect(
			COLUMNS.map((column) => [column, columnTypeHint(column)]),
		).toMatchSnapshot()
	})
})
