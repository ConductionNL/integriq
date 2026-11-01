/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for groupFieldRuns() (src/modals/v2/jobDraft.js): field lists
 * with grouped, ungrouped, split and malformed descriptors are grouped into
 * render runs, and the snapshot pins the runs, their keys and their members.
 */
import { describe, expect, it } from 'vitest'
import { groupFieldRuns } from '../../src/modals/v2/jobDraft.js'

const f = (key, group) => (group === undefined ? { key } : { key, group })

const LISTS = [
	null,
	undefined,
	'fields',
	{},
	[],
	[f('a')],
	[f('a', 'g'), f('b', 'g'), f('c')],
	[f('a', 'g'), f('b'), f('c', 'g')],
	[f('a', 'g'), f('b', 'h'), f('c', 'h')],
	[f('a', ''), f('b', '')],
	[f('a', 5), f('b', 5)],
	[f('a', null), f('b', null)],
	[f('a', 'a'), f('a')],
	[
		null,
		f('a', 'g'),
		undefined,
		{ group: 'g' },
		{ key: 3, group: 'g' },
		f('b', 'g'),
	],
	[f('x'), f('y'), f('z', 'g')],
]

describe('groupFieldRuns golden', () => {
	it('groups every field list as recorded', () => {
		expect(
			LISTS.map((fields) => [fields, groupFieldRuns(fields)]),
		).toMatchSnapshot()
	})
})
