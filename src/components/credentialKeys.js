// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The credential type of an API key (ConsumerCredentials::TYPE_API_KEY). */
export const API_KEY = 'apiKey'

/** The credential type of a pinned client certificate (ConsumerCredentials::TYPE_CLIENT_CERTIFICATE). */
export const CLIENT_CERTIFICATE = 'clientCertificate'

/**
 * The key routes of one of the developer's applications.
 *
 * @param {string} applicationId the application's id
 * @return {string} the URL
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
 */
export function portalKeysUrl(applicationId) {
	return generateUrl('/apps/integriq/api/portal/applications/{id}/keys', { id: applicationId })
}

/**
 * The administrator's credential routes of a consumer.
 *
 * @param {string} consumerId the consumer's id
 * @return {string} the URL
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
 */
export function consumerCredentialsUrl(consumerId) {
	return generateUrl('/apps/integriq/api/consumers/{id}/credentials', { id: consumerId })
}

/**
 * The credentials behind a credential route, without their hashes.
 *
 * @param {string} url the credential route
 * @return {Promise<object[]>} the credentials
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
 */
export async function listCredentials(url) {
	const response = await axios.get(url)
	return Array.isArray(response?.data?.results) ? response.data.results : []
}

/**
 * Create a credential: a generated key, whose answer holds the key the only time it is sent, or a pinned certificate.
 *
 * @param {string} url the credential route
 * @param {object} body the label, and the expiry or the certificate fields
 * @return {Promise<{key: string, credential: object|null}>} the key ('' for a certificate) and the credential entry
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
 */
export async function createCredential(url, body) {
	const response = await axios.post(url, body)
	return { key: String(response?.data?.key ?? ''), credential: response?.data?.credential ?? null }
}

/**
 * Revoke one credential.
 *
 * @param {string} url the credential route
 * @param {string} credentialId the credential's id
 * @return {Promise<void>} resolves once revoked
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
 */
export async function revokeCredential(url, credentialId) {
	await axios.delete(url + '/' + encodeURIComponent(credentialId))
}
