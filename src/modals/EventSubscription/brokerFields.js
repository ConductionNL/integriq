// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure helpers for the Broker delivery action on the subscription form
// (SubscriptionActionFields.vue and BrokerConnectionFields.vue). DOM-free so
// they run in the node-env vitest harness.
//
// The broker list comes from GET /api/events/brokers (the registry's
// describeAll()), never from a fixed list, so a transport a deployment
// registers needs no form change.
//
// @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-the-subscription-form-offers-broker-as-a-delivery-action-req-ebsc-002

/** The dormant transport: it refuses every publish on purpose. */
export const LOG_BROKER_ID = 'log'

/** The one broker that routes on a key. */
export const RABBITMQ_BROKER_ID = 'rabbitmq'

/** Keys of protocolSettings.broker that hold a secret. The form never writes them. */
export const BROKER_SECRET_KEYS = ['password', 'token']

/**
 * Broker descriptions as select options, the dormant log transport last.
 *
 * @param {object[]} descriptions The `results` of GET /api/events/brokers.
 * @return {object[]} `{id, label, needsTopic, contentModes, refuses}` options.
 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-the-subscription-form-offers-broker-as-a-delivery-action-req-ebsc-002
 */
export function brokerOptions(descriptions) {
	if (!Array.isArray(descriptions)) return []
	const options = descriptions
		.filter((row) => row && typeof row === 'object' && row.id)
		.map((row) => ({
			id: String(row.id),
			label: String(row.label || row.id),
			needsTopic: row.needsTopic !== false,
			contentModes:
				Array.isArray(row.contentModes) && row.contentModes.length > 0
					? row.contentModes.map(String)
					: ['structured'],
			refuses: row.id === LOG_BROKER_ID,
		}))
	return [
		...options.filter((option) => !option.refuses),
		...options.filter((option) => option.refuses),
	]
}

/**
 * Whether the routing key field is shown for a broker.
 *
 * @param {string|null} brokerId The picked broker.
 * @return {boolean} True for RabbitMQ only.
 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-the-subscription-form-offers-broker-as-a-delivery-action-req-ebsc-002
 */
export function showsRoutingKey(brokerId) {
	return brokerId === RABBITMQ_BROKER_ID
}

/**
 * The content modes a broker offers.
 *
 * @param {object|null} option The picked broker option.
 * @return {string[]} Its content modes, structured when it names none.
 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-the-subscription-form-offers-broker-as-a-delivery-action-req-ebsc-002
 */
export function contentModesFor(option) {
	return option
		&& Array.isArray(option.contentModes)
		&& option.contentModes.length > 0
		? option.contentModes
		: ['structured']
}

/**
 * Drop the keys whose value is empty.
 *
 * @param {object} object The object to compact.
 * @return {object} The object without undefined, null or blank-string values.
 * @spec exclude presentation-only compaction helper
 */
function compact(object) {
	const out = {}
	for (const [key, value] of Object.entries(object)) {
		if (value === undefined || value === null) continue
		if (typeof value === 'string' && value.trim() === '') continue
		out[key] = typeof value === 'string' ? value.trim() : value
	}
	return out
}

/**
 * The action a broker subscription stores, after one field changed.
 *
 * Picking another broker drops a routing key it does not use and a content
 * mode it does not offer.
 *
 * @param {object} current The subscription's current action.
 * @param {object} patch The changed fields.
 * @param {object[]} options The broker options.
 * @return {object} `{kind: 'broker', brokerId, topic, routingKey, contentMode, orderingKey}` without empty keys.
 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-the-subscription-form-offers-broker-as-a-delivery-action-req-ebsc-002
 */
export function buildBrokerAction(current, patch, options) {
	const base = current && typeof current === 'object' ? current : {}
	const next = {
		brokerId: base.brokerId,
		topic: base.topic,
		routingKey: base.routingKey,
		contentMode: base.contentMode,
		orderingKey: base.orderingKey,
		...patch,
	}
	const option =
		(options || []).find((candidate) => candidate.id === next.brokerId) || null
	if (!showsRoutingKey(next.brokerId)) {
		delete next.routingKey
	}
	const modes = contentModesFor(option)
	if (!next.contentMode || !modes.includes(next.contentMode)) {
		next.contentMode = modes[0]
	}
	return { kind: 'broker', ...compact(next) }
}

/**
 * The broker connection block off the form data.
 *
 * @param {object} formData The subscription form data.
 * @return {object} `protocolSettings.broker`, or an empty object.
 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
 */
export function readBrokerConnection(formData) {
	const broker = formData?.protocolSettings?.broker
	return broker && typeof broker === 'object' ? broker : {}
}

/**
 * The protocolSettings a broker subscription stores, after one connection field changed.
 *
 * Only base URL, virtual host (RabbitMQ), username and a credential reference
 * are written. A stored password or token is dropped, never carried over, so
 * saving the form moves the subscription onto the credential.
 *
 * @param {object} protocolSettings The current protocolSettings.
 * @param {object} patch The changed connection fields.
 * @param {string|null} brokerId The picked broker.
 * @return {object} The new protocolSettings.
 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
 */
export function buildBrokerSettings(protocolSettings, patch, brokerId) {
	const settings =
		protocolSettings && typeof protocolSettings === 'object'
			? protocolSettings
			: {}
	const current =
		settings.broker && typeof settings.broker === 'object' ? settings.broker : {}
	const next = {
		baseUrl: current.baseUrl,
		vhost: current.vhost,
		username: current.username,
		credentialRef: current.credentialRef,
		...patch,
	}
	if (brokerId !== RABBITMQ_BROKER_ID) {
		delete next.vhost
	}
	if (!next.credentialRef || !next.credentialRef.credentialId) {
		delete next.credentialRef
	}
	return { ...settings, broker: compact(next) }
}

/**
 * Whether the subscription still stores a broker secret (shown masked by the app's endpoints).
 *
 * @param {object} broker A `protocolSettings.broker` block as the app's subscription endpoint returns it.
 * @return {boolean} True when a password or token is stored.
 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-stored-broker-secrets-are-masked-on-the-apps-subscription-endpoints-req-ebsc-004
 */
export function storesBrokerSecret(broker) {
	if (!broker || typeof broker !== 'object') return false
	return BROKER_SECRET_KEYS.some(
		(key) =>
			broker[key] !== undefined && broker[key] !== null && broker[key] !== '',
	)
}
