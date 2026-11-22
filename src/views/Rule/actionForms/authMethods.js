// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The two shapes of an authentication rule's configuration, as
 * EndpointService::processAuthenticationRule() reads them:
 *
 * - one method: `{ type, header?, keys?, users?, groups?, requiredScopes? }`;
 * - any one of several: `{ anyOf: [method, ...], requiredScopes? }`, where the
 *   first method that passes lets the call through and the rule's
 *   `requiredScopes` applies to every method that sets none of its own.
 *
 * A method inside `anyOf` may not hold an `anyOf` of its own: the service
 * answers such an entry with "not supported".
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
 */

/** The method a new `anyOf` entry starts as. */
const DEFAULT_METHOD = { type: 'apikey' }

/**
 * The keys that belong to the rule, not to one method.
 *
 * @type {Array<string>}
 */
const RULE_KEYS = ['requiredScopes']

/**
 * Whether the configuration accepts any one of several methods.
 *
 * @param {object|undefined} value The rule's authentication configuration.
 * @return {boolean} True for the `anyOf` shape.
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
 */
export function isAnyOf(value) {
	return Array.isArray(value?.anyOf)
}

/**
 * The methods of the configuration: the `anyOf` list, or the one method.
 *
 * @param {object|undefined} value The rule's authentication configuration.
 * @return {Array<object>} The methods, each a plain object.
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
 */
export function methodsOf(value) {
	if (isAnyOf(value)) {
		return value.anyOf.map((method) => (method && typeof method === 'object' ? method : {}))
	}
	return [methodPart(value)]
}

/**
 * The one-method fields of a configuration, without the rule-level keys.
 *
 * @param {object|undefined} value The configuration.
 * @return {object} The method fields.
 */
function methodPart(value) {
	const method = { ...(value && typeof value === 'object' ? value : {}) }
	delete method.anyOf
	for (const key of RULE_KEYS) {
		delete method[key]
	}
	return method
}

/**
 * The rule-level keys of a configuration that are set.
 *
 * @param {object|undefined} value The configuration.
 * @return {object} The rule-level keys.
 */
function rulePart(value) {
	const rule = {}
	for (const key of RULE_KEYS) {
		if (value && value[key] !== undefined) {
			rule[key] = value[key]
		}
	}
	return rule
}

/**
 * Turn a one-method configuration into `anyOf` with that method first, or an
 * `anyOf` configuration back into its first method. The rule's
 * `requiredScopes` stays on the rule either way, so switching never drops a
 * scope check.
 *
 * @param {object|undefined} value The configuration.
 * @param {boolean} anyOf True for the `anyOf` shape.
 * @return {object} The configuration in the asked shape.
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
 */
export function withAnyOf(value, anyOf) {
	if (anyOf === isAnyOf(value)) {
		return { ...(value || {}) }
	}
	if (anyOf) {
		const first = methodPart(value)
		return { ...rulePart(value), anyOf: [first.type ? first : { ...DEFAULT_METHOD, ...first }] }
	}
	return { ...methodPart(methodsOf(value)[0] || {}), ...rulePart(value) }
}

/**
 * Replace the method at a position of an `anyOf` configuration.
 *
 * @param {object} value The `anyOf` configuration.
 * @param {number} index The position.
 * @param {object} method The new method; an `anyOf` of its own is dropped.
 * @return {object} The next configuration.
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
 */
export function withMethod(value, index, method) {
	const methods = methodsOf(value).slice()
	const next = { ...(method || {}) }
	delete next.anyOf
	methods[index] = next
	return { ...value, anyOf: methods }
}

/**
 * Add a method at the end of an `anyOf` configuration.
 *
 * @param {object} value The `anyOf` configuration.
 * @return {object} The next configuration.
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
 */
export function withAddedMethod(value) {
	return { ...value, anyOf: [...methodsOf(value), { ...DEFAULT_METHOD }] }
}

/**
 * Remove the method at a position of an `anyOf` configuration. The last
 * method stays: an empty `anyOf` would make the service skip the list and
 * read the rule as one method without a type.
 *
 * @param {object} value The `anyOf` configuration.
 * @param {number} index The position.
 * @return {object} The next configuration.
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005
 */
export function withoutMethod(value, index) {
	const methods = methodsOf(value)
	if (methods.length <= 1) {
		return { ...value, anyOf: methods }
	}
	return { ...value, anyOf: methods.filter((_, position) => position !== index) }
}
