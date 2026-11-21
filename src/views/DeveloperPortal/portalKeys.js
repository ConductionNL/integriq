// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The credential type of an API key (ConsumerCredentials::TYPE_API_KEY). */
export const API_KEY = 'apiKey'

/**
 * The key routes of one of the developer's applications.
 *
 * @param {string} applicationId the application's id
 * @return {string} the URL
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
 */
export function keysUrl(applicationId) {
	return generateUrl('/apps/integriq/api/portal/applications/{id}/keys', { id: applicationId })
}

/**
 * The application's credentials, without their hashes.
 *
 * @param {string} applicationId the application's id
 * @return {Promise<object[]>} the credentials
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
 */
export async function listKeys(applicationId) {
	const response = await axios.get(keysUrl(applicationId))
	return Array.isArray(response?.data?.results) ? response.data.results : []
}

/**
 * Generate a key; the answer holds the key, the only time it is sent.
 *
 * @param {string} applicationId the application's id
 * @param {string} label the key's label
 * @return {Promise<{key: string, credential: object|null}>} the key and its credential entry
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
 */
export async function generateKey(applicationId, label) {
	const response = await axios.post(keysUrl(applicationId), { label })
	return { key: String(response?.data?.key ?? ''), credential: response?.data?.credential ?? null }
}

/**
 * Revoke one key.
 *
 * @param {string} applicationId the application's id
 * @param {string} keyId the key's id
 * @return {Promise<void>} resolves once revoked
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-manages-the-keys-of-their-own-application-req-devp-003
 */
export async function revokeKey(applicationId, keyId) {
	await axios.delete(keysUrl(applicationId) + '/' + encodeURIComponent(keyId))
}
