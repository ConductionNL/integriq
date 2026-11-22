/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers for the Authenticatie section on the admin settings page
 * (change portal-idp-broker-config, REQ-IDPC-008). They turn a stored
 * `idpRegistration` object into an editable draft, say which fields and which
 * credential roles the draft's kind needs, check a draft the way the schema
 * and IdpRegistrationService will, and turn a draft back into the payload
 * OpenRegister stores. A draft holds credential ids only, never a secret.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */

import { translate as t } from '@nextcloud/l10n'

/** The OpenRegister collection of the registrations. */
export const REGISTRATIONS_URL =
	'/apps/openregister/api/objects/integriq/idpRegistration'

/** Integriq's overview of the registrations with their readiness. */
export const OVERVIEW_URL = '/apps/integriq/api/idp/registrations'

/** The OpenRegister organisations the signed-in administrator belongs to. */
export const ORGANISATIONS_URL = '/apps/openregister/api/organisations'

/** The OpenRegister credentials the signed-in administrator can reference. */
export const CREDENTIALS_URL = '/apps/openregister/api/credentials'

/** The app id a credential must allow before integriq may read it. */
export const APP_ID = 'integriq'

/** The subject fields an assertion can carry, in the order the modal shows them. */
export const SUBJECT_FIELDS = [
	'bsn',
	'pseudonym',
	'kvk',
	'rsin',
	'branch',
	'eidasPersonIdentifier',
]

/**
 * The providers whose login discloses attributes rather than an identity.
 * Each is reached over an OIDC bridge only, so its protocol is fixed.
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
export const ATTRIBUTE_PROVIDERS = ['yivi']

const text = (value) => (typeof value === 'string' ? value.trim() : '')

/**
 * Whether a provider's login discloses attributes (Yivi).
 *
 * @param {string} provider The provider id.
 * @return {boolean} True for an attribute provider.
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
export function isAttributeProvider(provider) {
	return ATTRIBUTE_PROVIDERS.includes(provider)
}

/**
 * The value when it is a plain object, otherwise an empty one.
 *
 * @param {unknown} value The value.
 * @return {object} The object.
 */
function record(value) {
	return value && typeof value === 'object' && !Array.isArray(value) ? value : {}
}

/**
 * The address of one test run.
 *
 * @param {string} id The registration id.
 * @return {string} The address.
 */
export function testUrl(id) {
	return `${OVERVIEW_URL}/${encodeURIComponent(id)}/test`
}

/**
 * The address of one stored registration.
 *
 * @param {string} id The registration id.
 * @return {string} The address.
 */
export function objectUrl(id) {
	return `${REGISTRATIONS_URL}/${encodeURIComponent(id)}`
}

/**
 * The providers, as select options.
 *
 * @return {Array<{id: string, label: string}>} The options.
 */
export function providerOptions() {
	return [
		{ id: 'digid', label: t('integriq', 'DigiD') },
		{ id: 'eherkenning', label: t('integriq', 'eHerkenning') },
		{ id: 'eidas', label: t('integriq', 'eIDAS') },
		{ id: 'yivi', label: t('integriq', 'Yivi') },
	]
}

/**
 * The protocols, as select options.
 *
 * @return {Array<{id: string, label: string}>} The options.
 */
export function kindOptions() {
	return [
		{ id: 'saml-sp', label: t('integriq', 'SAML Service Provider') },
		{ id: 'oidc-rp', label: t('integriq', 'OIDC Relying Party') },
	]
}

/**
 * The OIDC client authentication methods, as select options.
 *
 * @return {Array<{id: string, label: string}>} The options.
 */
export function clientAuthOptions() {
	return [
		{ id: 'private_key_jwt', label: t('integriq', 'Signed client assertion (private_key_jwt)') },
		{ id: 'client_secret_basic', label: t('integriq', 'Client secret (client_secret_basic)') },
	]
}

/**
 * The pseudonymisation modes, as select options.
 *
 * @return {Array<{id: string, label: string}>} The options.
 */
export function pseudonymisationOptions() {
	return [
		{ id: 'hmac', label: t('integriq', 'Salted HMAC of the BSN') },
		{ id: 'polymorphic', label: t('integriq', 'Polymorphic pseudonym from the broker') },
	]
}

/**
 * The label of one option id, or the id itself when no option has it.
 *
 * @param {Array<{id: string, label: string}>} options The options.
 * @param {string} id The id.
 * @return {string} The label.
 */
export function labelOf(options, id) {
	return options.find((option) => option.id === id)?.label || id || ''
}

/**
 * The label of a credential role.
 *
 * @param {string} role The role.
 * @return {string} The label.
 */
export function roleLabel(role) {
	const labels = {
		signing: t('integriq', 'Signing credential'),
		decryption: t('integriq', 'Decryption credential'),
		clientSecret: t('integriq', 'Client secret credential'),
		pseudonymKeys: t('integriq', 'Pseudonym keys credential'),
		salt: t('integriq', 'Salt credential'),
	}
	return labels[role] || role
}

/**
 * An empty draft: a SAML registration, disabled, with HMAC pseudonyms.
 *
 * @return {object} The draft.
 */
export function emptyDraft() {
	return {
		id: null,
		organisation: '',
		provider: 'digid',
		kind: 'saml-sp',
		enabled: false,
		label: '',
		idpMetadataUrl: '',
		spEntityId: '',
		issuer: '',
		clientId: '',
		clientAuth: 'private_key_jwt',
		pseudonymisation: 'hmac',
		subjectMapping: Object.fromEntries(SUBJECT_FIELDS.map((field) => [field, ''])),
		assuranceMapping: [],
		attributes: [],
		yiviTrust: '',
		yiviBsnToConsumer: false,
		credentials: {
			signing: '',
			decryption: '',
			clientSecret: '',
			pseudonymKeys: '',
			salt: '',
		},
	}
}

/**
 * The id of a stored object, from whichever field carries it.
 *
 * @param {object} object The stored object.
 * @return {string|null} The id.
 */
export function objectId(object) {
	const row = record(object)
	return row.id || record(row['@self']).id || row.uuid || null
}

/**
 * A draft from a stored registration.
 *
 * @param {object} object The stored `idpRegistration` object.
 * @return {object} The draft.
 */
export function draftFromObject(object) {
	const row = record(object)
	const draft = emptyDraft()
	draft.id = objectId(row)
	for (const key of ['organisation', 'label', 'idpMetadataUrl', 'spEntityId', 'issuer', 'clientId']) {
		draft[key] = text(row[key])
	}
	draft.provider = text(row.provider) || draft.provider
	draft.kind = text(row.kind) || draft.kind
	draft.clientAuth = text(row.clientAuth) || draft.clientAuth
	draft.pseudonymisation = text(row.pseudonymisation) || draft.pseudonymisation
	draft.enabled = row.enabled === true
	const mapping = record(row.subjectMapping)
	for (const field of SUBJECT_FIELDS) {
		draft.subjectMapping[field] = text(mapping[field])
	}
	draft.assuranceMapping = Object.entries(record(row.assuranceMapping)).map(
		([sent, level]) => ({ sent, level: text(level) }),
	)
	const credentials = record(row.credentials)
	for (const role of Object.keys(draft.credentials)) {
		draft.credentials[role] = text(credentials[role])
	}
	draft.attributes = (Array.isArray(row.yiviAttributes) ? row.yiviAttributes : [])
		.filter((entry) => Object.keys(record(entry)).length > 0)
		.map((entry) => ({ id: text(entry.id), claim: text(entry.claim), label: text(entry.label), carriesBsn: entry.carriesBsn === true }))
	// Not edited on this screen, but carried so a save does not reset them.
	draft.yiviTrust = text(row.yiviTrust)
	draft.yiviBsnToConsumer = row.yiviBsnToConsumer === true
	return fixedForProvider(draft)
}

/**
 * A draft with what its provider fixes applied: an attribute provider is
 * reached over OIDC and pseudonymises with the salt, whatever the draft said.
 *
 * @param {object} draft The draft; changed in place.
 * @return {object} The same draft.
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
export function fixedForProvider(draft) {
	if (isAttributeProvider(draft.provider)) {
		draft.kind = 'oidc-rp'
		draft.pseudonymisation = 'hmac'
	}
	return draft
}

/**
 * The attribute lines a service may ask for, as stored: lines without an id
 * or a claim name left out, the label trimmed and left out when empty.
 *
 * @param {Array<object>} attributes The draft's attribute lines.
 * @return {Array<object>} The `yiviAttributes` entries.
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
export function attributeEntries(attributes) {
	return (Array.isArray(attributes) ? attributes : [])
		.filter((line) => text(line.id) && text(line.claim))
		.map((line) => {
			const entry = { id: text(line.id), claim: text(line.claim), carriesBsn: line.carriesBsn === true }
			if (text(line.label)) {
				entry.label = text(line.label)
			}
			return entry
		})
}

/**
 * The credential roles a draft must name, as the runtime reads them
 * (design D4): signing for SAML and for private_key_jwt, the client secret
 * for client_secret_basic, and the salt or the pseudonym keys by mode.
 *
 * @param {object} draft The draft.
 * @return {string[]} The required roles.
 */
export function requiredRoles(draft) {
	const roles = []
	if (draft.kind === 'saml-sp' || draft.clientAuth === 'private_key_jwt') {
		roles.push('signing')
	}
	if (draft.kind === 'oidc-rp' && draft.clientAuth === 'client_secret_basic') {
		roles.push('clientSecret')
	}
	roles.push(draft.pseudonymisation === 'polymorphic' ? 'pseudonymKeys' : 'salt')
	return roles
}

/**
 * The credential roles the modal offers for a draft: the required ones, plus
 * the decryption credential for SAML (needed when the identity provider
 * encrypts its assertions).
 *
 * @param {object} draft The draft.
 * @return {string[]} The roles.
 */
export function offeredRoles(draft) {
	const roles = requiredRoles(draft)
	if (draft.kind === 'saml-sp') {
		roles.splice(1, 0, 'decryption')
	}
	return roles
}

/**
 * What is wrong with a draft, one sentence per problem; empty when it can
 * be saved.
 *
 * @param {object} draft The draft.
 * @return {string[]} The problems.
 */
export function draftProblems(draft) {
	const problems = []
	if (!text(draft.organisation)) {
		problems.push(t('integriq', 'Choose the organisation the logins are for.'))
	}
	if (draft.kind === 'saml-sp' && !/^https:\/\//.test(text(draft.idpMetadataUrl))) {
		problems.push(t('integriq', 'Enter the identity provider metadata URL, starting with https://.'))
	}
	problems.push(...attributeProblems(draft))
	if (draft.kind === 'oidc-rp') {
		if (!/^https:\/\//.test(text(draft.issuer))) {
			problems.push(t('integriq', 'Enter the issuer, starting with https://.'))
		}
		if (!text(draft.clientId)) {
			problems.push(t('integriq', 'Enter the client id the broker registered.'))
		}
	}
	for (const role of requiredRoles(draft)) {
		if (!text(draft.credentials[role])) {
			problems.push(t('integriq', 'Choose the {role}.', { role: roleLabel(role).toLowerCase() }))
		}
	}
	return problems
}

/**
 * What is wrong with an attribute provider's attribute list; empty for any other provider.
 *
 * @param {object} draft The draft.
 * @return {string[]} The problems.
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
function attributeProblems(draft) {
	if (!isAttributeProvider(draft.provider)) {
		return []
	}
	const lines = Array.isArray(draft.attributes) ? draft.attributes : []
	const problems = []
	if (attributeEntries(lines).length === 0) {
		problems.push(t('integriq', 'Add at least one attribute a service may ask for.'))
	}
	if (lines.some((line) => !text(line.id) || !text(line.claim))) {
		problems.push(t('integriq', 'Give every attribute an id and a claim name.'))
	}
	return problems
}

/**
 * The payload OpenRegister stores for a draft: the fields of the draft's
 * kind only, empty values left out, and credential ids for the offered
 * roles only.
 *
 * @param {object} draft The draft.
 * @return {object} The payload.
 */
export function payloadFromDraft(draft) {
	if (isAttributeProvider(draft.provider)) {
		return attributePayload(draft)
	}
	const payload = {
		organisation: text(draft.organisation),
		provider: draft.provider,
		kind: draft.kind,
		enabled: draft.enabled === true,
		pseudonymisation: draft.pseudonymisation,
	}
	const keys = draft.kind === 'saml-sp'
		? ['label', 'idpMetadataUrl', 'spEntityId']
		: ['label', 'issuer', 'clientId']
	for (const key of keys) {
		if (text(draft[key])) {
			payload[key] = text(draft[key])
		}
	}
	if (draft.kind === 'oidc-rp') {
		payload.clientAuth = draft.clientAuth
	}
	const mapping = {}
	for (const field of SUBJECT_FIELDS) {
		if (text(draft.subjectMapping[field])) {
			mapping[field] = text(draft.subjectMapping[field])
		}
	}
	payload.subjectMapping = mapping
	payload.assuranceMapping = Object.fromEntries(
		draft.assuranceMapping
			.filter((line) => text(line.sent) && text(line.level))
			.map((line) => [text(line.sent), text(line.level)]),
	)
	const credentials = {}
	for (const role of offeredRoles(draft)) {
		if (text(draft.credentials[role])) {
			credentials[role] = text(draft.credentials[role])
		}
	}
	payload.credentials = credentials
	return payload
}

/**
 * The payload of an attribute provider's draft: the OIDC fields, the
 * attribute list, and the credentials it offers. No subject or assurance
 * mapping: an attribute login has neither.
 *
 * @param {object} draft The draft.
 * @return {object} The payload.
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
function attributePayload(draft) {
	const fixed = fixedForProvider({ ...draft })
	const payload = {
		organisation: text(fixed.organisation),
		provider: fixed.provider,
		kind: fixed.kind,
		enabled: fixed.enabled === true,
		pseudonymisation: fixed.pseudonymisation,
		clientAuth: fixed.clientAuth,
		yiviAttributes: attributeEntries(fixed.attributes),
		yiviBsnToConsumer: fixed.yiviBsnToConsumer === true,
	}
	if (text(fixed.yiviTrust)) {
		payload.yiviTrust = text(fixed.yiviTrust)
	}
	for (const key of ['label', 'issuer', 'clientId']) {
		if (text(fixed[key])) {
			payload[key] = text(fixed[key])
		}
	}
	const credentials = {}
	for (const role of offeredRoles(fixed)) {
		if (text(fixed.credentials[role])) {
			credentials[role] = text(fixed.credentials[role])
		}
	}
	payload.credentials = credentials
	return payload
}

/**
 * Organisation options from OpenRegister's organisation list.
 *
 * @param {unknown} data The response body.
 * @return {Array<{id: string, label: string}>} The options.
 */
export function organisationOptions(data) {
	const rows = Array.isArray(record(data).results) ? data.results : []
	return rows
		.map((row) => {
			const id = text(record(row).uuid) || text(record(row).id)
			return id ? { id, label: text(row.name) || id } : null
		})
		.filter((option) => option !== null)
}

/**
 * Credential options from OpenRegister's credential list: only credentials
 * that allow integriq (or name no allowed apps), labelled by name and
 * provider. The list carries no secret value, and nothing here reads one.
 *
 * @param {unknown} data The response body.
 * @return {Array<{id: string, label: string}>} The options.
 */
export function credentialOptions(data) {
	const rows = Array.isArray(data) ? data : (Array.isArray(record(data).results) ? data.results : [])
	return rows
		.map((row) => {
			const id = String(objectId(row) || '')
			const allowed = record(row).allowedApps
			if (!id || (Array.isArray(allowed) && allowed.length > 0 && !allowed.includes(APP_ID))) {
				return null
			}
			const name = text(row.name) || id
			return { id, label: text(row.provider) ? `${name} (${text(row.provider)})` : name }
		})
		.filter((option) => option !== null)
}

/**
 * The text of a readiness status and its reasons, for the table.
 *
 * @param {string} status The readiness status.
 * @param {string[]} reasons The reasons, naming properties and roles only.
 * @return {string} The text.
 */
export function statusText(status, reasons) {
	const labels = {
		ready: t('integriq', 'Ready'),
		disabled: t('integriq', 'Disabled'),
		'flag-off': t('integriq', 'Broker switched off'),
		incomplete: t('integriq', 'Incomplete'),
		'secret-missing': t('integriq', 'Credential missing'),
		'keepiq-missing': t('integriq', 'Keepiq missing'),
	}
	const label = labels[status] || status || ''
	const list = Array.isArray(reasons) ? reasons.filter((reason) => text(reason)) : []
	return list.length > 0 ? `${label}: ${list.join(', ')}` : label
}

/**
 * The name of a Test check, for the result list: the metadata or discovery
 * document, or a credential by its role.
 *
 * @param {string} check The check id the server returns.
 * @return {string} The name.
 */
export function checkLabel(check) {
	if (check === 'metadata') {
		return t('integriq', 'Identity provider metadata')
	}
	if (check === 'discovery') {
		return t('integriq', 'Discovery document')
	}
	if (typeof check === 'string' && check.startsWith('credential:')) {
		return roleLabel(check.slice('credential:'.length))
	}
	return check
}
