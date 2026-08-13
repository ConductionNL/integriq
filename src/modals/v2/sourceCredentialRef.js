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
 * (lib/Settings/openconnector_register.json).
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
	if (!formData || typeof formData !== 'object') return null
	const configuration = formData.configuration
	if (!configuration || typeof configuration !== 'object') return null
	const authentication = configuration.authentication
	if (!authentication || typeof authentication !== 'object') return null
	const ref = authentication.credentialRef
	return ref && typeof ref === 'object' ? ref : null
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
	const base =
		configuration
		&& typeof configuration === 'object'
		&& !Array.isArray(configuration)
			? { ...configuration }
			: {}
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
	if (
		!configuration
		|| typeof configuration !== 'object'
		|| Array.isArray(configuration)
	) {
		return {}
	}
	const base = { ...configuration }
	const authentication = base.authentication
	if (!authentication || typeof authentication !== 'object') {
		return base
	}
	const nextAuth = { ...authentication }
	delete nextAuth.credentialRef
	if (Object.keys(nextAuth).length === 0) {
		delete base.authentication
	} else {
		base.authentication = nextAuth
	}
	return base
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
	return results
		.map((row) => {
			if (!row || typeof row !== 'object') return null
			const self =
				row['@self'] && typeof row['@self'] === 'object' ? row['@self'] : {}
			const id = row.id || row.uuid || self.id || self.uuid || null
			if (!id) return null
			const name = row.name || self.title || String(id)
			const provider = row.provider || ''
			const label = provider ? `${name} (${provider})` : String(name)
			return {
				id: String(id),
				label,
				name: String(name),
				provider: String(provider),
			}
		})
		.filter((option) => option !== null)
}
