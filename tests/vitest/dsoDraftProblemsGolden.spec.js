/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for draftProblems() (src/views/admin/dsoActivityMapping.js):
 * every draft below is checked and the snapshot pins the problems, in order.
 */
import { describe, expect, it } from 'vitest'
import { draftProblems } from '../../src/views/admin/dsoActivityMapping.js'

const IMOW = 'nl.imow-gm0000.activiteit.Bouwen'
const OK = {
	imowId: IMOW,
	activityId: '',
	activityName: 'Bouwen',
	caseTypes: [{ reference: 'zt-1' }],
	samenloopRules: [{ withImowId: IMOW }],
}

const DRAFTS = {
	valid: OK,
	'activity id only': { ...OK, imowId: '', activityId: 'act-1' },
	'no ids': { ...OK, imowId: '  ', activityId: ' ' },
	'bad imow-id': { ...OK, imowId: 'not-stam' },
	'padded imow-id': { ...OK, imowId: `  ${IMOW}  ` },
	'no name': { ...OK, activityName: '   ' },
	'no case types': { ...OK, caseTypes: [] },
	'blank references': { ...OK, caseTypes: [{ reference: ' ' }, {}] },
	'one reference': {
		...OK,
		caseTypes: [{ reference: '' }, { reference: 'zt-2' }],
	},
	'bad samenloop': {
		...OK,
		samenloopRules: [{ withImowId: IMOW }, { withImowId: 'x' }],
	},
	'no samenloop': { ...OK, samenloopRules: [] },
	'samenloop without id': { ...OK, samenloopRules: [{}] },
	everything: {
		imowId: '',
		activityId: '',
		activityName: '',
		caseTypes: [],
		samenloopRules: [{ withImowId: '' }],
	},
	'bad imow and name': { ...OK, imowId: 'x', activityName: '' },
	'null fields': {
		...OK,
		imowId: null,
		activityId: undefined,
		activityName: null,
	},
}

describe('draftProblems golden', () => {
	it('lists the problems of every draft as recorded', () => {
		expect(
			Object.entries(DRAFTS).map(([name, draft]) => [
				name,
				draftProblems(draft),
			]),
		).toMatchSnapshot()
	})
})
