/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the shared rule draft/conditions helpers
 * (src/views/Rule/ruleDraft.js) behind both rule editing surfaces —
 * `views/Rule/RuleDetailPage.vue` and `modals/v2/RuleEditorModal.vue`.
 *
 * What is worth pinning here is the round trip, because both ends of it are
 * easy to regress and a mistake is silent — a rule that saves "fine" and then
 * never fires:
 *
 *   1. `normaliseConditions()` has to absorb every shape rows have actually
 *      been persisted in. The pre-manifest modal wrote whatever its textarea
 *      parsed to (a bare string, a bare leaf, an array), so the visual builder
 *      must not be handed a non-group root.
 *   2. `serializeRuleConditions()` must return an **object**, not the array
 *      `syncDraft.serializeConditions()` returns. The `rule` schema types
 *      `conditions` as an object and the `synchronization` schema as
 *      `array<object>`; swapping the two writes data neither side reads.
 *   3. `ACTION_TYPES` ids are matched literally by
 *      `EndpointService::handleRuleProcessing()`, whose `match` throws on an
 *      unknown type. A typo here is a rule that cannot run.
 */

import { describe, it, expect } from 'vitest'
import {
	ACTION_OPTIONS,
	ACTION_TYPES,
	DEFAULT_ERROR_CONFIG,
	EMPTY_ROOT_GROUP,
	TIMING_OPTIONS,
	UNDISPATCHED_ACTION_TYPES,
	emptyRuleDraft,
	normaliseConditions,
	serializeRuleConditions,
} from '../../src/views/Rule/ruleDraft.js'

describe('normaliseConditions', () => {
	it.each([
		['null', null],
		['undefined', undefined],
		['an empty string', ''],
		['an empty array', []],
	])('returns an empty AND group for %s', (_label, input) => {
		expect(normaliseConditions(input)).toEqual({ and: [] })
	})

	it('returns a group node untouched', () => {
		const group = { and: [{ '==': [{ var: 'status' }, 'active'] }] }
		expect(normaliseConditions(group)).toBe(group)
	})

	it('keeps an OR root as OR rather than rewriting it to AND', () => {
		const group = { or: [{ '==': [{ var: 'a' }, 1] }] }
		expect(normaliseConditions(group)).toEqual(group)
	})

	it('parses the JSON string the pre-manifest raw editor persisted', () => {
		const raw =
			'{"and": [{"==": [{"var": "status"}, "active"]}, {">=": [{"var": "age"}, 18]}]}'
		expect(normaliseConditions(raw)).toEqual({
			and: [
				{ '==': [{ var: 'status' }, 'active'] },
				{ '>=': [{ var: 'age' }, 18] },
			],
		})
	})

	it('falls back to an empty group on unparseable text instead of throwing', () => {
		expect(normaliseConditions('{not json')).toEqual({ and: [] })
	})

	it('wraps a bare leaf with AND so the builder always has a group root', () => {
		expect(normaliseConditions({ '==': [{ var: 'a' }, 1] })).toEqual({
			and: [{ '==': [{ var: 'a' }, 1] }],
		})
	})

	it('unwraps a single-element array that wraps a group', () => {
		expect(normaliseConditions([{ or: [{ '==': [{ var: 'a' }, 1] }] }])).toEqual(
			{
				or: [{ '==': [{ var: 'a' }, 1] }],
			},
		)
	})

	it('wraps a multi-element array of leaves with AND', () => {
		const leaves = [{ '==': [{ var: 'a' }, 1] }, { '>': [{ var: 'b' }, 2] }]
		expect(normaliseConditions(leaves)).toEqual({ and: leaves })
	})

	it('does not hand out the shared EMPTY_ROOT_GROUP object', () => {
		const first = normaliseConditions(null)
		first.and.push({ '==': [1, 1] })
		expect(normaliseConditions(null)).toEqual({ and: [] })
		expect(EMPTY_ROOT_GROUP).toEqual({ and: [] })
	})
})

describe('serializeRuleConditions', () => {
	it('returns the group as an OBJECT, not wrapped in an array', () => {
		const group = { and: [{ '==': [{ var: 'a' }, 1] }] }
		const out = serializeRuleConditions(group)
		expect(Array.isArray(out)).toBe(false)
		expect(out).toEqual(group)
	})

	it('collapses an empty AND group to an empty object', () => {
		expect(serializeRuleConditions({ and: [] })).toEqual({})
	})

	it('collapses an empty OR group to an empty object', () => {
		expect(serializeRuleConditions({ or: [] })).toEqual({})
	})

	it.each([
		['null', null],
		['undefined', undefined],
		['a string', 'nope'],
		['an array', [{ and: [] }]],
	])('returns an empty object for %s', (_label, input) => {
		expect(serializeRuleConditions(input)).toEqual({})
	})

	it('round-trips a populated tree through normalise → serialize', () => {
		const raw = '{"or": [{"==": [{"var": "status"}, "active"]}]}'
		expect(serializeRuleConditions(normaliseConditions(raw))).toEqual({
			or: [{ '==': [{ var: 'status' }, 'active'] }],
		})
	})
})

describe('emptyRuleDraft', () => {
	it('defaults order to the schema default of 100, not 0', () => {
		expect(emptyRuleDraft().order).toBe(100)
	})

	it('defaults timing to a value the pipeline actually compares against', () => {
		expect(TIMING_OPTIONS.map((o) => o.id)).toContain(emptyRuleDraft().timing)
	})

	it('defaults type to the one action type the modal can fully configure', () => {
		expect(emptyRuleDraft().type).toBe('error')
	})

	it('starts with an empty condition group', () => {
		expect(emptyRuleDraft().conditions).toEqual({ and: [] })
	})

	it('returns a fresh object each call so two dialogs cannot share a draft', () => {
		const first = emptyRuleDraft()
		first.name = 'mutated'
		first.conditions.and.push({ '==': [1, 1] })
		const second = emptyRuleDraft()
		expect(second.name).toBe('')
		expect(second.conditions).toEqual({ and: [] })
	})
})

describe('option vocabularies', () => {
	/**
	 * The arms of the `match ($ruleType)` in
	 * EndpointService::handleRuleProcessing() — the endpoint rule pipeline.
	 * Anything outside this set hits the `default` arm and throws
	 * "Unsupported rule type:" at request time.
	 */
	const ENDPOINT_RULE_TYPES = [
		'save_object',
		'authentication',
		'error',
		'mapping',
		'synchronization',
		'javascript',
		'fileparts_create',
		'filepart_upload',
		'download',
		'extend_input',
		'extend_external_input',
		'audit_trail',
		'write_file',
		'locking',
		'override',
		'webhook_signature',
		'custom',
		'composite_fanout',
		'referentienummer',
		'avg_bsn_policy',
		'selfurl_hal',
		'approval',
		'flow',
	]

	/**
	 * The arms of the second pipeline, SynchronizationService::processRules().
	 * It accepts a different set — notably `fetch_file`, which has no endpoint
	 * arm at all.
	 */
	const SYNCHRONIZATION_RULE_TYPES = [
		'error',
		'mapping',
		'synchronization',
		'save_object',
		'fetch_file',
		'write_file',
		'extend_input',
	]

	it('offers only action types one of the two pipelines can dispatch', () => {
		const dispatchable = new Set([
			...ENDPOINT_RULE_TYPES,
			...SYNCHRONIZATION_RULE_TYPES,
		])
		const undispatched = ACTION_TYPES.map((entry) => entry.id).filter(
			(id) => !dispatchable.has(id),
		)
		expect(undispatched).toEqual(UNDISPATCHED_ACTION_TYPES)
	})

	it('records exactly the known undispatched types, so the gap cannot grow quietly', () => {
		// Fails the day an `upload` arm lands (trim the list) or a new
		// undispatched type is added to the picker (add the arm instead).
		expect(UNDISPATCHED_ACTION_TYPES).toEqual(['upload'])
	})

	it('offers no duplicate action types', () => {
		const ids = ACTION_TYPES.map((entry) => entry.id)
		expect(new Set(ids).size).toBe(ids.length)
	})

	it('offers exactly before/after for timing', () => {
		expect(TIMING_OPTIONS.map((o) => o.id)).toEqual(['before', 'after'])
	})

	it('offers the four HTTP verbs the rule pipeline scopes on', () => {
		expect(ACTION_OPTIONS.map((o) => o.id)).toEqual([
			'post',
			'get',
			'put',
			'delete',
		])
	})

	it('labels every option, so no select renders a bare id', () => {
		for (const list of [ACTION_TYPES, TIMING_OPTIONS, ACTION_OPTIONS]) {
			for (const entry of list) {
				expect(entry.label).toBeTruthy()
			}
		}
	})
})

describe('DEFAULT_ERROR_CONFIG', () => {
	it('carries the four keys the error rule reads', () => {
		expect(Object.keys(DEFAULT_ERROR_CONFIG).sort()).toEqual([
			'code',
			'includeJsonLogicResult',
			'message',
			'name',
		])
	})

	it('defaults to a valid HTTP status code', () => {
		expect(DEFAULT_ERROR_CONFIG.code).toBeGreaterThanOrEqual(100)
		expect(DEFAULT_ERROR_CONFIG.code).toBeLessThanOrEqual(999)
	})

	it('defaults the JsonLogic disclosure switch to off', () => {
		expect(DEFAULT_ERROR_CONFIG.includeJsonLogicResult).toBe(false)
	})
})
