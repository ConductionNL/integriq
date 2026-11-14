// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The signed-in user's connected accounts for integriq's per-user sources
 * (sources-per-user-oauth task 3).
 *
 * A connected account is the user's own OAuth 2.0 token set in OpenRegister's
 * credential broker: kind `oauth2-token-set`, personal scope. integriq never
 * holds the token; it reads the metadata the broker lists for the caller and
 * asks the broker to disconnect one, which revokes it upstream where the
 * provider allows and disables it locally. After that, the user's next call
 * to a per-user source answers the connect prompt again.
 *
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-sees-and-disconnects-their-own-accounts-req-puoa-003
 */

/** The credential kind of a connected OAuth 2.0 account. */
export const OAUTH2_KIND = 'oauth2-token-set'

/** The broker's listing of the caller's own personal credentials. */
export const CREDENTIALS_URL = '/apps/openregister/api/credentials'

/**
 * The broker's disconnect endpoint for one connected account.
 *
 * @param {string} id The credential UUID.
 * @return {string} The app-relative URL (DELETE).
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-sees-and-disconnects-their-own-accounts-req-puoa-003
 */
export function disconnectUrl(id) {
	return `/apps/openregister/api/credentials/oauth2/${encodeURIComponent(id)}`
}

/**
 * The name a person recognises an account by: the provider's display name,
 * else the handle, else the account id.
 *
 * @param {object} account The `account` the broker stored with the token set.
 * @return {string} The identity, '' when the provider returned none.
 */
export function accountIdentity(account) {
	const value = account && typeof account === 'object' ? account : {}
	for (const key of ['displayName', 'handle', 'id']) {
		const text = typeof value[key] === 'string' ? value[key].trim() : ''
		if (text !== '') {
			return text
		}
	}
	return ''
}

/**
 * The connected accounts among the broker's rows: the caller's personal,
 * not disabled OAuth 2.0 token sets, oldest first.
 *
 * @param {Array<object>} rows The rows of GET /api/credentials.
 * @return {Array<{id: string, provider: string, identity: string, connectedOn: string}>} The accounts.
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-sees-and-disconnects-their-own-accounts-req-puoa-003
 */
export function connectedAccounts(rows) {
	return (Array.isArray(rows) ? rows : [])
		.filter((row) => row && row.kind === OAUTH2_KIND
			&& (row.scope ?? 'personal') === 'personal'
			&& row.status !== 'disabled')
		.map((row) => ({
			id: String(row.id ?? row.uuid ?? row['@self']?.id ?? ''),
			provider: String(row.provider ?? ''),
			identity: accountIdentity(row.account),
			connectedOn: String(row['@self']?.created ?? row.created ?? ''),
		}))
		.filter((account) => account.id !== '')
		.sort((a, b) => a.connectedOn.localeCompare(b.connectedOn))
}

/**
 * Load the caller's connected accounts.
 *
 * @param {{get: (url: string) => Promise<{data: object}>}} http An axios-like client.
 * @param {(url: string) => string} [urlOf] Makes the app-relative URL absolute (generateUrl).
 * @return {Promise<Array<object>>} The accounts, see connectedAccounts().
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-sees-and-disconnects-their-own-accounts-req-puoa-003
 */
export async function loadConnectedAccounts(http, urlOf = (url) => url) {
	const { data } = await http.get(urlOf(CREDENTIALS_URL))
	return connectedAccounts(data?.results ?? data)
}

/**
 * Disconnect one account in the broker.
 *
 * @param {{delete: (url: string) => Promise<object>}} http An axios-like client.
 * @param {string} id The credential UUID.
 * @param {(url: string) => string} [urlOf] Makes the app-relative URL absolute (generateUrl).
 * @return {Promise<void>} Resolves once the broker answered.
 * @throws {Error} Without an id.
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-sees-and-disconnects-their-own-accounts-req-puoa-003
 */
export async function disconnectAccount(http, id, urlOf = (url) => url) {
	if (typeof id !== 'string' || id === '') {
		throw new Error('An account to disconnect needs its id.')
	}
	await http.delete(urlOf(disconnectUrl(id)))
}
