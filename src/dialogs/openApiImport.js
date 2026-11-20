// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Pure helpers of OpenApiImportDialog: the request body and the sentences that
 * report an import.
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */

/**
 * The body preview and import share. A pasted document wins over an address,
 * as the server reads it; the address is only sent without one.
 *
 * @param {object} input `{ url, text, prefix, serverIndex }`.
 * @param input.url
 * @param input.text
 * @param input.prefix
 * @param input.serverIndex
 * @return {object} The request body.
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */
export function importRequest({ url = '', text = '', prefix = '', serverIndex = null } = {}) {
	const body = { prefix: String(prefix).trim() }
	if (String(text).trim() !== '') {
		body.document = String(text)
	} else {
		body.url = String(url).trim()
	}
	if (Number.isInteger(serverIndex)) {
		body.serverIndex = serverIndex
	}
	return body
}

/**
 * One sentence on what the import created, skipped and could not create.
 *
 * @param {object|null} result The import answer: `{ source, created, skipped, failed, unknown }`.
 * @param {Function} t The translate function.
 * @param {Function} n The plural translate function.
 * @return {string} The sentence, or '' without a result.
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#scenario-an-administrator-connects-a-vendor-api-from-its-document
 */
export function importSummary(result, t, n) {
	if (!result) {
		return ''
	}
	if (!result.source) {
		return t('integriq', 'Nothing was created: every chosen operation is already served or unknown.')
	}
	const parts = [
		t('integriq', 'Source {name} created.', { name: result.source.name }, undefined, { escape: false }),
		n('integriq', '{count} endpoint created.', '{count} endpoints created.', result.created ?? 0, { count: result.created ?? 0 }),
	]
	if ((result.skipped ?? 0) > 0) {
		parts.push(n('integriq', '{count} operation skipped because its route is already served.', '{count} operations skipped because their routes are already served.', result.skipped, { count: result.skipped }))
	}
	if ((result.failed ?? 0) > 0) {
		parts.push(n('integriq', '{count} endpoint could not be created.', '{count} endpoints could not be created.', result.failed, { count: result.failed }))
	}
	return parts.join(' ')
}

/**
 * Which credential the new source asks for, or '' when it needs none.
 *
 * @param {object|null} result The import answer.
 * @param {Function} t The translate function.
 * @return {string} The sentence.
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#scenario-an-administrator-connects-a-vendor-api-from-its-document
 */
export function credentialSummary(result, t) {
	const credentials = result?.source?.credentials ?? []
	if (credentials.length === 0) {
		return ''
	}
	const schemes = credentials.map((credential) => credential.scheme).filter(Boolean).join(', ')
	return t('integriq', 'The source calls nothing until you add its credential ({schemes}) on the source.', { schemes }, undefined, { escape: false })
}
