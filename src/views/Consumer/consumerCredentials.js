/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A consumer's credentials as the consumer page shows them
 * (access-consumer-credentials, tasks 2 to 4): newest first, with a
 * description that never holds a key or a hash.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
 */

/** The credential types. */
export const TYPE_API_KEY = 'apiKey'
export const TYPE_CLIENT_CERTIFICATE = 'clientCertificate'

/**
 * The credentials API of a consumer, or of one of its credentials.
 *
 * @param {string} consumerId The consumer's id
 * @param {string} [credentialId] One credential's id
 * @return {string} The path under the integriq app
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
 */
export function credentialsPath(consumerId, credentialId = '') {
	const base = `/apps/integriq/api/consumers/${encodeURIComponent(consumerId)}/credentials`
	return credentialId ? `${base}/${encodeURIComponent(credentialId)}` : base
}

/**
 * The credentials newest first; anything that is not a credential is left out.
 *
 * @param {Array<object>} credentials The credentials the server listed
 * @return {Array<object>} Sorted copies
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
 */
export function sortedCredentials(credentials) {
	return (Array.isArray(credentials) ? credentials : [])
		.filter((credential) => credential && typeof credential === 'object' && credential.id)
		.map((credential) => ({ ...credential }))
		.sort((one, other) => String(other.createdAt ?? '').localeCompare(String(one.createdAt ?? '')))
}

/**
 * What identifies a credential on screen: a key's prefix, a certificate's
 * subject or fingerprint, or the issuer and subject pattern it is pinned by.
 * Never the key or its hash.
 *
 * @param {object} credential The credential
 * @return {string} The identifying text
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
 */
export function identifies(credential) {
	if (credential?.type === TYPE_CLIENT_CERTIFICATE) {
		if (credential.subjectPattern) {
			return `${credential.issuer || ''} · ${credential.subjectPattern}`.trim()
		}

		return credential.subject || credential.fingerprint || ''
	}

	return credential?.keyPrefix ? `${credential.keyPrefix}…` : ''
}

/**
 * Whether a credential has expired at a moment.
 *
 * @param {object} credential The credential
 * @param {Date} [now] The moment
 * @return {boolean} Whether it has expired
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
 */
export function isExpired(credential, now = new Date()) {
	const expires = credential?.expiresAt ? new Date(credential.expiresAt) : null
	return expires !== null && !Number.isNaN(expires.getTime()) && expires.getTime() <= now.getTime()
}

/**
 * The body of a create request, from what the dialog holds.
 *
 * @param {object} form The dialog's fields
 * @return {object} The request body
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 */
export function createBody(form) {
	const body = { label: String(form.label ?? '').trim() }
	if (form.expiresAt) {
		body.expiresAt = new Date(form.expiresAt).toISOString()
	}

	if (form.type === TYPE_CLIENT_CERTIFICATE) {
		body.type = TYPE_CLIENT_CERTIFICATE
		if (String(form.certificate ?? '').trim() !== '') {
			body.certificate = String(form.certificate).trim()
		} else {
			body.issuerCertificate = String(form.issuerCertificate ?? '').trim()
			body.subjectPattern = String(form.subjectPattern ?? '').trim()
		}
	}

	return body
}
