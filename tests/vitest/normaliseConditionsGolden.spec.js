/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for normaliseConditions() (src/views/Rule/ruleDraft.js): every
 * persisted conditions shape below is normalised and the snapshot pins the
 * group node it becomes, and whether a group comes back as the same object.
 */
import { describe, expect, it } from 'vitest'
import { normaliseConditions } from '../../src/views/Rule/ruleDraft.js'

const GROUP = { or: [{ '==': [{ var: 'a' }, 1] }] }

const INPUTS = [
	null,
	undefined,
	'',
	'   ',
	'not json',
	'{"and":[{"==":[{"var":"x"},2]}]}',
	'[{"or":[]}]',
	'"nested string"',
	'null',
	'42',
	[],
	[GROUP],
	[null],
	[[1]],
	['leaf'],
	[{ '==': [1, 1] }, { '>': [2, 1] }],
	GROUP,
	{ and: [] },
	{ and: 'not a list' },
	{ or: [], extra: true },
	{ xor: [] },
	{},
	{ '==': [{ var: 'b' }, 3] },
	0,
	7,
	true,
	false,
]

describe('normaliseConditions golden', () => {
	it('normalises every shape as recorded', () => {
		expect(
			INPUTS.map((raw) => {
				const out = normaliseConditions(raw)
				return [raw, out, out === raw]
			}),
		).toMatchSnapshot()
	})
})
