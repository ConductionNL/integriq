/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The routing rule draft (src/modals/v2/intakeRuleDraft.js): what the editor
 * sends to saveRule(), where a refusal from it is shown, the case type and
 * source options, the test sample, and the writes a reorder makes.
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
import { describe, expect, it } from 'vitest'
import {
	buildRulePayload,
	caseTypeOptions,
	draftFromRule,
	emptyDraft,
	moveWrites,
	refusalField,
	ruleFromRow,
	ruleId,
	sampleMessage,
	sourceExpressions,
} from '@/modals/v2/intakeRuleDraft.js'

const STORED = {
	name: 'Openbare ruimte straatverlichting',
	channelId: 'public-space-report',
	targetSchema: 'case',
	condition: { field: 'text', operator: 'contains', value: 'lantaarnpaal' },
	fieldMapping: { title: 'fields.category', description: 'text' },
	locationField: 'locatie',
	order: 10,
	isEnabled: true,
}

describe('draftFromRule and buildRulePayload', () => {
	it('round-trips a stored rule into the same rule', () => {
		expect(buildRulePayload(draftFromRule(STORED))).toEqual(STORED)
	})

	it('starts a new rule enabled at order 10 with no channel', () => {
		const draft = draftFromRule(null)
		expect(draft).toEqual(emptyDraft())
		expect(draft.isEnabled).toBe(true)
		expect(draft.order).toBe(10)
		expect(draft.channelId).toBe('')
	})

	it('sends an empty condition when no field is chosen, so the rule matches every message', () => {
		const draft = { ...emptyDraft(), name: 'Teams', channelId: 'teams', targetSchema: 'case', conditionValue: 'ignored' }
		expect(buildRulePayload(draft).condition).toEqual({})
	})

	it('sends no value for an exists condition', () => {
		const draft = { ...emptyDraft(), conditionField: 'location', conditionOperator: 'exists', conditionValue: 'x' }
		expect(buildRulePayload(draft).condition).toEqual({ field: 'location', operator: 'exists' })
	})

	it('drops mapping rows without a target and trims both sides', () => {
		const draft = {
			...emptyDraft(),
			mappings: [{ target: ' title ', source: ' text ' }, { target: '', source: 'fields.street' }],
		}
		expect(buildRulePayload(draft).fieldMapping).toEqual({ title: 'text' })
	})

	it('leaves out an empty description and location field, and keeps a disabled flag', () => {
		const payload = buildRulePayload({ ...emptyDraft(), description: '  ', isEnabled: false, order: '20' })
		expect(payload).not.toHaveProperty('description')
		expect(payload).not.toHaveProperty('locationField')
		expect(payload.isEnabled).toBe(false)
		expect(payload.order).toBe(20)
	})

	it('falls back to equals for an operator the schema does not know', () => {
		expect(draftFromRule({ condition: { field: 'text', operator: 'like' } }).conditionOperator).toBe('equals')
	})
})

describe('ruleFromRow and ruleId', () => {
	it('keeps only the rule schema fields of a listed row', () => {
		const row = { ...STORED, '@self': { id: 'uuid-1', register: 'integriq' }, id: 'uuid-1', uuid: 'uuid-1', extra: 1 }
		expect(ruleFromRow(row)).toEqual(STORED)
	})

	it('reads the uuid from @self, then id, then uuid', () => {
		expect(ruleId({ '@self': { id: 'a' }, id: 'b' })).toBe('a')
		expect(ruleId({ id: 'b', uuid: 'c' })).toBe('b')
		expect(ruleId({ uuid: 'c' })).toBe('c')
		expect(ruleId(null)).toBe('')
	})
})

describe('refusalField', () => {
	const draft = { ...emptyDraft(), locationField: 'locatie' }

	it('puts a missing mapped field on that mapping row', () => {
		expect(refusalField('Case type "case" has no field "straatnaam".', draft))
			.toEqual({ field: 'fieldMapping', target: 'straatnaam' })
	})

	it('puts a missing location field on the location field', () => {
		expect(refusalField('Case type "case" has no field "locatie".', draft))
			.toEqual({ field: 'locationField', target: null })
	})

	it('puts an unknown channel on the channel and a missing case type on the case type', () => {
		expect(refusalField('No intake channel adapter answers to "x".', draft).field).toBe('channelId')
		expect(refusalField('A routing rule must name the case type it opens.', draft).field).toBe('targetSchema')
		expect(refusalField('The field mapping must be an object of target field to source.', draft).field).toBe('fieldMapping')
	})

	it('names no field for another refusal', () => {
		expect(refusalField('Schema not found', draft)).toEqual({ field: null, target: null })
	})
})

describe('options', () => {
	it('offers the message fields, the correspondent and the channel fields', () => {
		expect(sourceExpressions({ fields: ['category', 'street'] })).toEqual([
			'text', 'channelId', 'externalId', 'location', 'receivedAt',
			'correspondent.name', 'correspondent.email', 'correspondent.phone',
			'fields.category', 'fields.street',
		])
		expect(sourceExpressions(null)).toHaveLength(8)
	})

	it('lists every schema by slug, grouped and labelled by its register', () => {
		const options = caseTypeOptions(
			[{ title: 'Zaken', schemas: [2, 1] }],
			[
				{ id: 1, slug: 'case', title: 'Zaak', properties: { title: {}, description: {} } },
				{ id: 2, slug: 'bezwaar', title: 'Bezwaar' },
				{ id: 3, title: 'Losse' },
			],
		)
		expect(options).toEqual([
			{ id: '3', label: 'Losse', register: '', properties: [] },
			{ id: 'bezwaar', label: 'Zaken – Bezwaar', register: 'Zaken', properties: [] },
			{ id: 'case', label: 'Zaken – Zaak', register: 'Zaken', properties: ['title', 'description'] },
		])
	})
})

describe('sampleMessage', () => {
	it('sends typed text as the message text', () => {
		expect(sampleMessage('De lantaarnpaal is kapot')).toEqual({ text: 'De lantaarnpaal is kapot' })
	})

	it('sends a held message without its status, uuid or channel', () => {
		const held = { id: 'm1', status: 'held', channelId: 'teams', text: 'hoi', fields: { a: 1 }, reason: 'none' }
		expect(sampleMessage('ignored', held)).toEqual({ text: 'hoi', fields: { a: 1 } })
	})
})

describe('moveWrites', () => {
	const first = { id: 'r1', name: 'Eerste', channelId: 'teams', order: 10 }
	const second = { id: 'r2', name: 'Tweede', channelId: 'teams', order: 20 }
	const other = { id: 'r3', name: 'Ander', channelId: 'form-submission', order: 15 }

	it('swaps the orders of a rule and the one above it on the same channel', () => {
		expect(moveWrites([second, other, first], second, 'up')).toEqual([
			{ row: second, order: 10 },
			{ row: first, order: 20 },
		])
	})

	it('gives two rules of the same order distinct values when one moves', () => {
		const a = { id: 'a', name: 'A', channelId: 'teams', order: 10 }
		const b = { id: 'b', name: 'B', channelId: 'teams', order: 10 }
		expect(moveWrites([a, b], b, 'up')).toEqual([{ row: b, order: 9 }, { row: a, order: 10 }])
		expect(moveWrites([a, b], a, 'down')).toEqual([{ row: a, order: 11 }, { row: b, order: 10 }])
	})

	it('writes nothing for the first rule moving up or the last moving down', () => {
		expect(moveWrites([first, second], first, 'up')).toEqual([])
		expect(moveWrites([first, second], second, 'down')).toEqual([])
		expect(moveWrites([first], other, 'up')).toEqual([])
	})
})
