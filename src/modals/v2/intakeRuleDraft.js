// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The draft behind IntakeRuleEditorModal and the intake routing page's row
// actions: what a routing rule looks like while it is edited, what is sent to
// `saveRule()`, and where a refusal from that save belongs on the form.
//
// Every write goes through `POST`/`PUT /api/intake/routing-rules`, because
// only `IntakeRoutingService::validateRule()` refuses a channel no adapter
// answers to and a mapping onto a field the case type lacks. `saveRule()`
// stores the request body as the rule, so a payload carries the rule's own
// fields and nothing else: no `@self`, no id, no OpenRegister metadata.
//
// @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001

/** The properties of the `intake_routing_rule` schema. */
export const RULE_FIELDS = [
	'name',
	'description',
	'channelId',
	'condition',
	'targetSchema',
	'fieldMapping',
	'locationField',
	'order',
	'isEnabled',
]

/** The condition operators the schema's enum allows. */
export const OPERATORS = ['equals', 'contains', 'exists', 'in']

/** The source expressions `readSource()` reads without a key. */
export const MESSAGE_FIELDS = ['text', 'channelId', 'externalId', 'location', 'receivedAt']

/** The correspondent keys a normalised message carries. */
export const CORRESPONDENT_FIELDS = ['name', 'email', 'phone']

/** The order a new rule gets when the channel has none yet. */
export const DEFAULT_ORDER = 10

/**
 * A blank draft for a new rule.
 *
 * @return {object} The draft.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function emptyDraft() {
	return {
		name: '',
		description: '',
		channelId: '',
		conditionField: '',
		conditionOperator: 'equals',
		conditionValue: '',
		targetSchema: '',
		mappings: [],
		locationField: '',
		order: DEFAULT_ORDER,
		isEnabled: true,
	}
}

/**
 * The draft for an existing rule.
 *
 * @param {object|null} rule The stored rule, or null for a new one.
 * @return {object} The draft.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function draftFromRule(rule) {
	const draft = emptyDraft()
	if (!rule || typeof rule !== 'object') {
		return draft
	}

	const condition = isPlainObject(rule.condition) ? rule.condition : {}
	const mapping = isPlainObject(rule.fieldMapping) ? rule.fieldMapping : {}

	return {
		...draft,
		name: text(rule.name),
		description: text(rule.description),
		channelId: text(rule.channelId),
		conditionField: text(condition.field),
		conditionOperator: OPERATORS.includes(condition.operator) ? condition.operator : 'equals',
		conditionValue: text(condition.value),
		targetSchema: text(rule.targetSchema),
		mappings: Object.entries(mapping).map(([target, source]) => ({ target, source: text(source) })),
		locationField: text(rule.locationField),
		order: Number.isInteger(rule.order) ? rule.order : DEFAULT_ORDER,
		isEnabled: rule.isEnabled !== false,
	}
}

/**
 * The rule `saveRule()` stores for a draft.
 *
 * A condition without a field is no condition, so the rule matches every
 * message on its channel. `exists` compares against nothing, so it sends no
 * value. A mapping row without a target field is left out.
 *
 * @param {object} draft The draft.
 * @return {object} The rule.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function buildRulePayload(draft) {
	const fieldMapping = {}
	for (const row of draft.mappings || []) {
		const target = text(row.target).trim()
		if (target !== '') {
			fieldMapping[target] = text(row.source).trim()
		}
	}

	const rule = {
		name: text(draft.name).trim(),
		channelId: text(draft.channelId),
		condition: conditionOf(draft),
		targetSchema: text(draft.targetSchema),
		fieldMapping,
		order: toOrder(draft.order),
		isEnabled: draft.isEnabled !== false,
	}

	const description = text(draft.description).trim()
	if (description !== '') {
		rule.description = description
	}

	const locationField = text(draft.locationField).trim()
	if (locationField !== '') {
		rule.locationField = locationField
	}

	return rule
}

/**
 * The stored rule of a list row, without OpenRegister's metadata.
 *
 * @param {object} row The row as the index page lists it.
 * @return {object} The rule's own fields.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function ruleFromRow(row) {
	const rule = {}
	for (const key of RULE_FIELDS) {
		if (row && row[key] !== undefined && row[key] !== null) {
			rule[key] = row[key]
		}
	}

	return rule
}

/**
 * The uuid of a listed rule.
 *
 * @param {object|null} row The row.
 * @return {string} The uuid, or '' when the row names none.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function ruleId(row) {
	if (!row || typeof row !== 'object') {
		return ''
	}

	return text(row['@self']?.id || row.id || row.uuid)
}

/**
 * Where on the form a refusal from `saveRule()` belongs.
 *
 * The refusals are `validateRule()`'s own sentences. A missing field on the
 * case type is shown on the location field when that is the field named,
 * otherwise on the mapping row that names it.
 *
 * @param {string} message The refusal.
 * @param {object} draft The draft that was saved.
 * @return {{field: string|null, target: string|null}} The form field, and for a mapping the target field.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function refusalField(message, draft) {
	const refusal = text(message)
	const missing = refusal.match(/^Case type ".*" has no field "(.*)"\.$/)
	if (missing !== null) {
		const field = missing[1]
		if (field === text(draft?.locationField).trim()) {
			return { field: 'locationField', target: null }
		}

		return { field: 'fieldMapping', target: field }
	}

	if (refusal.startsWith('No intake channel adapter answers to')) {
		return { field: 'channelId', target: null }
	}

	if (refusal.startsWith('A routing rule must name the case type')) {
		return { field: 'targetSchema', target: null }
	}

	if (refusal.startsWith('The field mapping must be')) {
		return { field: 'fieldMapping', target: null }
	}

	return { field: null, target: null }
}

/**
 * The source expressions a condition or mapping can read on a channel.
 *
 * @param {object|null} channel The channel as `GET /api/intake/channels` describes it.
 * @return {Array<string>} The expressions.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function sourceExpressions(channel) {
	const fields = Array.isArray(channel?.fields) ? channel.fields : []

	return [
		...MESSAGE_FIELDS,
		...CORRESPONDENT_FIELDS.map((key) => 'correspondent.' + key),
		...fields.map((key) => 'fields.' + key),
	]
}

/**
 * The case types a rule can open, one option per OpenRegister schema.
 *
 * The value is the schema's slug, which is what `validateRule()` resolves and
 * what the seeded rules store; a schema without a slug falls back to its id.
 *
 * @param {Array<object>} registers The registers.
 * @param {Array<object>} schemas The schemas.
 * @return {Array<{id: string, label: string, register: string, properties: Array<string>}>} The options, by register and then title.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function caseTypeOptions(registers, schemas) {
	const registerOf = {}
	for (const register of registers || []) {
		for (const schemaId of register.schemas || []) {
			const key = String(isPlainObject(schemaId) ? schemaId.id : schemaId)
			if (registerOf[key] === undefined) {
				registerOf[key] = text(register.title || register.slug)
			}
		}
	}

	return (schemas || [])
		.map((schema) => {
			const register = registerOf[String(schema.id)] || ''
			const title = text(schema.title || schema.slug || schema.id)
			return {
				id: text(schema.slug || schema.id),
				label: register === '' ? title : register + ' – ' + title,
				register,
				properties: isPlainObject(schema.properties) ? Object.keys(schema.properties) : [],
			}
		})
		.filter((option) => option.id !== '')
		.sort((a, b) => a.register.localeCompare(b.register) || a.label.localeCompare(b.label))
}

/**
 * The sample message a test sends: typed text, or a held message picked from the inbox.
 *
 * @param {string} sampleText The typed text.
 * @param {object|null} heldMessage The picked `intake_message`, or null.
 * @return {object} The sample, in the shape `InboundMessage::fromObject()` reads.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-draft-rule-is-tested-against-a-sample-message-without-side-effects-req-ire-002
 */
export function sampleMessage(sampleText, heldMessage = null) {
	if (heldMessage && typeof heldMessage === 'object') {
		const sample = {}
		for (const key of ['text', 'externalId', 'location', 'receivedAt', 'correspondent', 'fields']) {
			if (heldMessage[key] !== undefined && heldMessage[key] !== null) {
				sample[key] = heldMessage[key]
			}
		}
		return sample
	}

	return { text: text(sampleText) }
}

/**
 * The writes that move a rule one place up or down among the rules of its channel.
 *
 * The rule swaps places with its neighbour. When both share an order, the
 * pair gets two distinct values so the move is visible in the first match.
 *
 * @param {Array<object>} rules The listed rules of the channel, any order.
 * @param {object} rule The rule to move.
 * @param {string} direction 'up' or 'down'.
 * @return {Array<{row: object, order: number}>} The rules to save with their new order; empty when it cannot move.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function moveWrites(rules, rule, direction) {
	const id = ruleId(rule)
	const sorted = (rules || [])
		.filter((row) => text(row.channelId) === text(rule?.channelId))
		.slice()
		.sort((a, b) => toOrder(a.order) - toOrder(b.order) || text(a.name).localeCompare(text(b.name)))
	const index = sorted.findIndex((row) => ruleId(row) === id)
	const neighbourIndex = direction === 'up' ? index - 1 : index + 1
	if (index === -1 || neighbourIndex < 0 || neighbourIndex >= sorted.length) {
		return []
	}

	const self = sorted[index]
	const neighbour = sorted[neighbourIndex]
	let selfOrder = toOrder(neighbour.order)
	let neighbourOrder = toOrder(self.order)
	if (selfOrder === neighbourOrder) {
		selfOrder = direction === 'up' ? selfOrder - 1 : selfOrder + 1
		neighbourOrder = toOrder(self.order)
	}

	return [
		{ row: self, order: selfOrder },
		{ row: neighbour, order: neighbourOrder },
	]
}

/**
 * The condition of a draft, or an empty object for none.
 *
 * @param {object} draft The draft.
 * @return {object} The condition.
 */
function conditionOf(draft) {
	const field = text(draft.conditionField).trim()
	if (field === '') {
		return {}
	}

	const operator = OPERATORS.includes(draft.conditionOperator) ? draft.conditionOperator : 'equals'
	if (operator === 'exists') {
		return { field, operator }
	}

	return { field, operator, value: text(draft.conditionValue) }
}

/**
 * An order as an integer.
 *
 * @param {string|number|null|undefined} value The value.
 * @return {number} The integer, DEFAULT_ORDER when it is none.
 */
function toOrder(value) {
	const number = Number.parseInt(value, 10)
	return Number.isNaN(number) ? DEFAULT_ORDER : number
}

/**
 * A value as a string, '' for nothing.
 *
 * @param {string|number|boolean|null|undefined} value The value.
 * @return {string} The string.
 */
function text(value) {
	if (value === undefined || value === null) {
		return ''
	}

	return String(value)
}

/**
 * Whether a value is a plain object.
 *
 * @param {object|Array|string|number|boolean|null|undefined} value The value.
 * @return {boolean} True for an object that is not an array.
 */
function isPlainObject(value) {
	return value !== null && typeof value === 'object' && Array.isArray(value) === false
}
