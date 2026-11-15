/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the DSO activity mapping helpers: stored rows, unmapped
 * activities, drafts and refused saves of every shape are run through the
 * exported functions and compared with
 * tests/vitest/golden/dsoActivityMapping.json.
 *
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.1
 */
import { afterAll, describe, it } from 'vitest'
import { goldenFile } from './golden/goldenFile.js'
import {
	draftFromRow,
	draftFromUnmapped,
	draftProblems,
	emptyCaseType,
	payloadFromDraft,
	refusalMessage,
} from '@/views/admin/dsoActivityMapping.js'

const golden = goldenFile(
	new URL('./golden/dsoActivityMapping.json', import.meta.url),
)
afterAll(() => golden.save())

const IMOW = 'nl.imow-gm0000.activiteit.Bouwen'

/**
 * The result of a call, or the message it threw.
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

const ROWS = {
	none: undefined,
	empty: {},
	idOnly: { id: 'a' },
	selfId: { '@self': { id: 'b' } },
	selfNull: { '@self': null, uuid: 'c' },
	uuid: { uuid: 'd' },
	idWins: { id: 'a', '@self': { id: 'b' }, uuid: 'c' },
	emptyIds: { id: '', '@self': { id: '' }, uuid: '' },
	zeroId: { id: 0, uuid: 'z' },
	full: {
		id: 'e',
		imowId: `  ${IMOW} `,
		activityId: ' act ',
		activityName: ' Bouwen ',
		caseTypes: [
			{ reference: ' zt-1 ', title: ' T ', department: ' D ' },
			null,
			{ reference: 5, title: 'x' },
		],
		samenloopStrategy: 'gecombineerd',
		samenloopRules: [
			{ withImowId: ` ${IMOW}`, strategy: 'gecombineerd' },
			{ withImowId: 3, strategy: 'other' },
			null,
		],
		isActive: false,
		note: ' n ',
	},
	caseTypesNotArray: { caseTypes: { reference: 'x' } },
	emptyCaseTypes: { caseTypes: [] },
	rulesNotArray: { samenloopRules: 'x' },
	strategyOther: { samenloopStrategy: 'Gecombineerd' },
	isActiveValues: { isActive: 0 },
	isActiveNull: { isActive: null },
	nonStrings: { imowId: 1, activityId: true, activityName: {}, note: [] },
}

const UNMAPPED = {
	none: undefined,
	null: null,
	empty: {},
	full: { imowId: IMOW, activityId: 'a', activityName: 'n', extra: 1 },
	padded: { imowId: ' x ', activityName: 3 },
}

const OK = {
	imowId: IMOW,
	activityId: '',
	activityName: 'Bouwen',
	caseTypes: [{ reference: 'zt-1' }],
	samenloopStrategy: 'deelzaken',
	samenloopRules: [{ withImowId: IMOW, strategy: 'deelzaken' }],
	isActive: true,
	note: '',
}

const DRAFTS = {
	valid: OK,
	activityIdOnly: { ...OK, imowId: '', activityId: 'act-1' },
	noIds: { ...OK, imowId: '  ', activityId: ' ' },
	nullIds: { ...OK, imowId: null, activityId: null },
	badImow: { ...OK, imowId: 'not-stam' },
	badImowProvince: { ...OK, imowId: 'nl.imow-xx0000.a.B' },
	longImowNumber: { ...OK, imowId: 'nl.imow-gm1234567.a.B' },
	mnreImow: { ...OK, imowId: 'nl.imow-mnre12.a.B' },
	paddedImow: { ...OK, imowId: `  ${IMOW}  ` },
	noName: { ...OK, activityName: '   ' },
	noCaseTypes: { ...OK, caseTypes: [] },
	blankReferences: { ...OK, caseTypes: [{ reference: ' ' }, {}] },
	oneReference: {
		...OK,
		caseTypes: [
			{ reference: '' },
			{ reference: ' zt-2 ', title: ' T ', department: ' ' },
			{ reference: 'zt-3', title: '', department: ' D ' },
		],
	},
	badSamenloop: {
		...OK,
		samenloopRules: [{ withImowId: IMOW }, { withImowId: 'x' }],
	},
	paddedSamenloop: {
		...OK,
		samenloopRules: [{ withImowId: ` ${IMOW} `, strategy: 'gecombineerd' }],
	},
	noSamenloop: { ...OK, samenloopRules: [] },
	samenloopWithoutId: { ...OK, samenloopRules: [{}] },
	everything: {
		imowId: '',
		activityId: '',
		activityName: '',
		caseTypes: [],
		samenloopRules: [{ withImowId: '' }],
	},
	badImowAndName: { ...OK, imowId: 'x', activityName: '' },
	inactive: { ...OK, isActive: false, note: ' note ', activityId: ' a ' },
	activeUndefined: { ...OK, isActive: undefined },
	activeZero: { ...OK, isActive: 0 },
	noCaseTypeArray: { ...OK, caseTypes: undefined },
	noRuleArray: { ...OK, samenloopRules: undefined },
}

const ERRORS = {
	none: undefined,
	null: null,
	noResponse: {},
	noData: { response: {} },
	message: { response: { data: { message: 'm', error: 'e' } } },
	errorOnly: { response: { data: { error: 'e' } } },
	emptyMessage: { response: { data: { message: '', error: '' } } },
	stringData: { response: { data: 'oops' } },
}

describe('dsoActivityMapping golden', () => {
	it('emptyCaseType', () => {
		golden.check('emptyCaseType', emptyCaseType())
	})

	it.each(Object.entries(ROWS))('draftFromRow %s', (name, row) => {
		golden.check(
			`draftFromRow ${name}`,
			outcome(() => draftFromRow(row)),
		)
	})

	it('draftFromRow with null', () => {
		golden.check(
			'draftFromRow null',
			outcome(() => draftFromRow(null)),
		)
	})

	it.each(Object.entries(UNMAPPED))('draftFromUnmapped %s', (name, item) => {
		golden.check(
			`draftFromUnmapped ${name}`,
			outcome(() => draftFromUnmapped(item)),
		)
	})

	it.each(Object.entries(DRAFTS))('draft %s', (name, draft) => {
		golden.check(
			`draftProblems ${name}`,
			outcome(() => draftProblems(draft)),
		)
		golden.check(
			`payloadFromDraft ${name}`,
			outcome(() => payloadFromDraft(draft)),
		)
	})

	it.each(Object.entries(ROWS))('round trip %s', (name, row) => {
		const draft = draftFromRow(row)
		golden.check(`round trip ${name}`, {
			problems: draftProblems(draft),
			payload: payloadFromDraft(draft),
		})
	})

	it.each(Object.entries(ERRORS))('refusalMessage %s', (name, error) => {
		golden.check(`refusalMessage ${name}`, refusalMessage(error, 'fallback'))
	})
})
