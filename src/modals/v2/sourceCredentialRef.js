// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure, DOM-free helpers for the brokered-credential (credentialRef) picker on
// the Source editor (SourceFormFields.vue). Kept out of the .vue component so
// the read/write/detection logic is unit-testable in the repo's node-env
// vitest harness (the same reason src/handlers/** and the rule action-form
// helpers live as plain modules).
//
// The canonical location of the reference on a Source is
// `configuration.authentication.credentialRef` — the exact path the backend
// BrokeredCallService reads (lib/Service/BrokeredCallService.php::isBrokered).
// When credentialRef is present the broker forbids ANY sibling key under
// `configuration.authentication` (a hard 409 config error), so writeCredentialRef
// deliberately collapses `authentication` to `{ credentialRef }` only — the UI
// can never author the mutually-exclusive state.

/**
 * Top-level Source authentication properties that embed a secret (or its
 * configuration) directly on the source. They are mutually exclusive with a
 * brokered credential from a UX standpoint and are hidden/disabled by
 * SourceFormFields while credentialRef is set. Keys match the `source` schema
 * (lib/Settings/integriq_register.json).
 *
 * @type {string[]}
 */
export const EMBEDDED_SECRET_FIELDS = [
	'auth',
	'authenticationConfig',
	'authorizationHeader',
	'apikey',
	'secret',
	'username',
	'password',
	'jwt',
	'jwtId',
]

/**
 * The app id the broker authorises against a credential's allowedApps — the
 * CALLING app (OpenConnector). The credential's declaring app may differ.
 *
 * @type {string}
 */
export const CALLING_APP_ID = 'openconnector'

/**
 * Read the credentialRef object off a Source form-data model.
 *
 * @param {object} formData The CnFormDialog form-data (a Source object).
 * @return {object|null} The credentialRef object (`{ credentialId }` / `{ credentialName }`), or null.
 */
export function readCredentialRef(formData) {
	const configuration = objectOrNull(objectOrNull(formData)?.configuration)
	const authentication = objectOrNull(configuration?.authentication)
	return objectOrNull(authentication?.credentialRef)
}

/**
 * A value when it is an object (arrays included), otherwise null.
 *
 * @param {unknown} value The value.
 * @return {object|null} The object, or null.
 */
function objectOrNull(value) {
	return value && typeof value === 'object' ? value : null
}

/**
 * Whether a value is an object that is not an array.
 *
 * @param {unknown} value The value.
 * @return {boolean} True for a plain object.
 */
function isRecord(value) {
	return objectOrNull(value) !== null && !Array.isArray(value)
}

/**
 * Whether the Source is brokered (carries a credentialRef).
 *
 * @param {object} formData The CnFormDialog form-data (a Source object).
 * @return {boolean} True when a credentialRef is set.
 */
export function isBrokered(formData) {
	return readCredentialRef(formData) !== null
}

/**
 * The selected credentialId, or null.
 *
 * @param {object} formData The CnFormDialog form-data (a Source object).
 * @return {string|null} The credentialId, or null.
 */
export function readCredentialId(formData) {
	const ref = readCredentialRef(formData)
	if (!ref) return null
	const id = ref.credentialId
	return typeof id === 'string' && id.length > 0 ? id : null
}

/**
 * Return a NEW `configuration` object with `authentication` collapsed to
 * `{ credentialRef: { credentialId } }` — every existing configuration key is
 * preserved; every sibling under `authentication` is dropped so the broker's
 * mutual-exclusivity guard can never trip. Does not mutate its argument.
 *
 * @param {object} configuration The current source `configuration` object (may be undefined).
 * @param {string} credentialId The chosen brokered-credential UUID.
 * @return {object} The next `configuration` object.
 */
export function writeCredentialRef(configuration, credentialId) {
	const base = isRecord(configuration) ? { ...configuration } : {}
	base.authentication = { credentialRef: { credentialId } }
	return base
}

/**
 * Return a NEW `configuration` object with the credentialRef removed. If
 * `authentication` held nothing but the credentialRef it is removed entirely;
 * any other sibling keys under `authentication` are preserved. Does not mutate.
 *
 * @param {object} configuration The current source `configuration` object (may be undefined).
 * @return {object} The next `configuration` object.
 */
export function clearCredentialRef(configuration) {
	if (!isRecord(configuration)) return {}
	const base = { ...configuration }
	const authentication = objectOrNull(base.authentication)
	if (!authentication) return base
	const nextAuth = authenticationWithoutRef(authentication)
	if (nextAuth === null) {
		delete base.authentication
	} else {
		base.authentication = nextAuth
	}
	return base
}

/**
 * A copy of an `authentication` object without its credentialRef, or null
 * when nothing else is left in it.
 *
 * @param {object} authentication The current `authentication` object.
 * @return {object|null} The remaining keys, or null.
 */
function authenticationWithoutRef(authentication) {
	const nextAuth = { ...authentication }
	delete nextAuth.credentialRef
	return Object.keys(nextAuth).length === 0 ? null : nextAuth
}

/**
 * Unwrap the OR credential list envelope. `GET /apps/openregister/api/credentials`
 * returns `{ results: [...] }`; be liberal and also accept a bare array.
 *
 * @param {*} data The axios response body.
 * @return {object[]} The credential objects (never null).
 */
export function extractCredentialResults(data) {
	if (Array.isArray(data)) return data
	if (data && Array.isArray(data.results)) return data.results
	return []
}

/**
 * Map raw OR credential objects to NcSelect options: `{ id, label, name, provider }`.
 * The label is `"<name> (<provider>)"` so the picker is self-describing. Rows
 * without a resolvable id are dropped (they cannot be referenced).
 *
 * @param {object[]} results The credential objects from the OR endpoint.
 * @return {object[]} NcSelect option objects.
 */
export function mapCredentialOptions(results) {
	if (!Array.isArray(results)) return []
	return results.map(credentialOption).filter((option) => option !== null)
}

/**
 * One NcSelect option for a raw OR credential object, or null when the row
 * has no resolvable id.
 *
 * @param {unknown} row The credential object.
 * @return {object|null} The option, or null.
 */
function credentialOption(row) {
	if (!objectOrNull(row)) return null
	const self = credentialSelf(row)
	const id = firstTruthy([row.id, row.uuid, self.id, self.uuid])
	if (!id) return null
	const name = firstTruthy([row.name, self.title]) || String(id)
	const provider = row.provider || ''
	return {
		id: String(id),
		label: credentialLabel(name, provider),
		name: String(name),
		provider: String(provider),
	}
}

/**
 * The `@self` metadata of a credential object, or an empty object.
 *
 * @param {object} row The credential object.
 * @return {object} The metadata.
 */
function credentialSelf(row) {
	return objectOrNull(row['@self']) || {}
}

/**
 * The first truthy value of a list, or undefined.
 *
 * @param {Array} values The candidates, in order of preference.
 * @return {unknown} The first truthy value.
 */
function firstTruthy(values) {
	return values.find(Boolean)
}

/**
 * The picker label of a credential: its name, with the provider in brackets.
 *
 * @param {unknown} name     The credential name.
 * @param {unknown} provider The provider, or an empty string.
 * @return {string} The label.
 */
function credentialLabel(name, provider) {
	return provider ? `${name} (${provider})` : String(name)
}

/**
 * Whether the Source calls with each user's own account
 * (`credentialRef: { provider, perUser: true }`, sources-per-user-oauth).
 *
 * @param {object} formData The CnFormDialog form-data (a Source object).
 * @return {boolean} True for a per-user source.
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-source-can-call-with-the-signed-in-users-own-account-req-puoa-001
 */
export function isPerUser(formData) {
	return readCredentialRef(formData)?.perUser === true
}

/**
 * The provider of a per-user Source, or null.
 *
 * @param {object} formData The CnFormDialog form-data (a Source object).
 * @return {string|null} The provider slug, or null.
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-source-can-call-with-the-signed-in-users-own-account-req-puoa-001
 */
export function readPerUserProvider(formData) {
	const ref = readCredentialRef(formData)
	if (ref?.perUser !== true) return null
	return typeof ref.provider === 'string' && ref.provider.length > 0 ? ref.provider : null
}

/**
 * Return a NEW `configuration` whose `authentication` is exactly
 * `{ credentialRef: { provider, perUser: true } }`: every call is made with the
 * signed-in user's own account at that provider. Does not mutate.
 *
 * @param {object} configuration The current source `configuration` object (may be undefined).
 * @param {string} provider The OAuth 2.0 provider slug from the broker's catalogue.
 * @return {object} The next `configuration` object.
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-source-can-call-with-the-signed-in-users-own-account-req-puoa-001
 */
export function writePerUserRef(configuration, provider) {
	const base = isRecord(configuration) ? { ...configuration } : {}
	base.authentication = { credentialRef: { provider, perUser: true } }
	return base
}

/**
 * The broker catalogue's OAuth 2.0 providers as picker options: only those
 * can back a per-user source.
 *
 * @param {Array<object>} providers The rows of GET /api/credentials/providers.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-source-can-call-with-the-signed-in-users-own-account-req-puoa-001
 */
export function perUserProviderOptions(providers) {
	return (Array.isArray(providers) ? providers : [])
		.filter((p) => p && p.kind === 'oauth2-token-set' && typeof p.identifier === 'string' && p.identifier !== '')
		.map((p) => ({ id: p.identifier, label: String(p.title || p.identifier) }))
}
