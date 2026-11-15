// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The endpoints behind the intake routing page and the intake inbox's
// "Route again". Rules are written only through `saveRule()`, so its
// validation runs on every create, edit, reorder and enable; they are read
// and deleted through OpenRegister's objects endpoint, as the index page does.
//
// @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The register the intake schemas live in. */
export const INTAKE_REGISTER = 'integriq'

/** The routing rule schema. */
export const RULE_SCHEMA = 'intake_routing_rule'

/** The intake message schema. */
export const MESSAGE_SCHEMA = 'intake_message'

/**
 * Save a rule through `saveRule()`: create without an id, update with one.
 *
 * @param {string} id The rule uuid, '' for a new rule.
 * @param {object} rule The rule's own fields.
 * @return {Promise<{id: string, rule: object}>} The saved rule.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export async function saveRule(id, rule) {
	if (id) {
		const { data } = await axios.put(
			generateUrl('/apps/integriq/api/intake/routing-rules/' + encodeURIComponent(id)),
			rule,
		)
		return data
	}

	const { data } = await axios.post(generateUrl('/apps/integriq/api/intake/routing-rules'), rule)
	return data
}

/**
 * Test a draft rule against a sample message; nothing is stored.
 *
 * @param {object} rule The draft rule.
 * @param {object} sample The sample message.
 * @return {Promise<{matches: boolean, payload: object, winningRule: object|null}>} The outcome.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-draft-rule-is-tested-against-a-sample-message-without-side-effects-req-ire-002
 */
export async function testRule(rule, sample) {
	const { data } = await axios.post(
		generateUrl('/apps/integriq/api/intake/routing-rules/test'),
		{ rule, sample },
	)
	return data
}

/**
 * The channels this instance has an adapter for.
 *
 * @return {Promise<Array<object>>} The channel descriptions.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export async function fetchChannels() {
	const { data } = await axios.get(generateUrl('/apps/integriq/api/intake/channels'))
	return Array.isArray(data?.channels) ? data.channels : []
}

/**
 * OpenRegister's registers and schemas, for the case type picker.
 *
 * @return {Promise<{registers: Array<object>, schemas: Array<object>}>} Both lists.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export async function fetchRegistersAndSchemas() {
	const [registers, schemas] = await Promise.all([
		axios.get(generateUrl('/apps/openregister/api/registers'), { params: { _limit: 500 } }),
		axios.get(generateUrl('/apps/openregister/api/schemas'), { params: { _limit: 1000 } }),
	])

	return {
		registers: registers.data?.results || [],
		schemas: schemas.data?.results || [],
	}
}

/**
 * The stored routing rules of one channel.
 *
 * @param {string} channelId The channel.
 * @return {Promise<Array<object>>} The rules.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export async function fetchChannelRules(channelId) {
	const { data } = await axios.get(objectsUrl(RULE_SCHEMA), {
		params: { channelId, _limit: 500 },
	})
	return data?.results || []
}

/**
 * The messages waiting in the inbox: held and failed.
 *
 * @return {Promise<Array<object>>} The messages, newest first.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
 */
export async function fetchWaitingMessages() {
	const lists = await Promise.all(['held', 'failed'].map(async (status) => {
		const { data } = await axios.get(objectsUrl(MESSAGE_SCHEMA), {
			params: { status, _limit: 200 },
		})
		return data?.results || []
	}))

	return lists.flat().sort((a, b) => String(b.receivedAt || '').localeCompare(String(a.receivedAt || '')))
}

/**
 * Route one held or failed message again.
 *
 * @param {string} id The `intake_message` uuid.
 * @return {Promise<object>} Its new state: status, ruleName, targetRef and reason.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
 */
export async function routeAgain(id) {
	const { data } = await axios.post(
		generateUrl('/apps/integriq/api/intake/messages/' + encodeURIComponent(id) + '/route'),
	)
	return data
}

/**
 * Route a selection of messages again, one outcome per id.
 *
 * @param {Array<string>} ids The `intake_message` uuids.
 * @return {Promise<Array<object>>} The outcomes, in the order asked.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
 */
export async function routeAgainMany(ids) {
	const { data } = await axios.post(generateUrl('/apps/integriq/api/intake/messages/route'), { ids })
	return Array.isArray(data?.results) ? data.results : []
}

/**
 * The refusal text of a failed request.
 *
 * @param {Error|object} error The axios error.
 * @param {string} fallback What to say when the answer names nothing.
 * @return {string} The text.
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
 */
export function refusalOf(error, fallback) {
	const data = error?.response?.data
	if (data && typeof data.error === 'string' && data.error !== '') {
		return data.error
	}
	if (data && typeof data.message === 'string' && data.message !== '') {
		return data.message
	}

	return fallback
}

/**
 * OpenRegister's objects endpoint for an intake schema.
 *
 * @param {string} schema The schema slug.
 * @return {string} The URL.
 */
function objectsUrl(schema) {
	return generateUrl('/apps/openregister/api/objects/' + INTAKE_REGISTER + '/' + schema)
}

/**
 * Whether the signed-in user may import a mail file, and the mailboxes to import under.
 *
 * @return {Promise<{allowed: boolean, mailboxes: Array<{id: string, name: string}>}>} The options.
 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
export async function fetchMailImportOptions() {
	const { data } = await axios.get(generateUrl('/apps/integriq/api/mail-intake/import/options'))
	return {
		allowed: data?.allowed === true,
		mailboxes: Array.isArray(data?.mailboxes) ? data.mailboxes : [],
	}
}

/**
 * Import a saved .eml or .msg file under a mailbox source.
 *
 * @param {string} sourceId The mailbox source.
 * @param {File} file The file.
 * @return {Promise<{id: string, message: object, warning: string|null}>} The imported message.
 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
export async function importMailFile(sourceId, file) {
	const form = new FormData()
	form.append('sourceId', sourceId)
	form.append('file', file)
	const { data } = await axios.post(generateUrl('/apps/integriq/api/mail-intake/import'), form)
	return data
}
