// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The request shapes behind the SharePoint to publication setup dialog
 * (connectors-sharepoint-publication-intake REQ-SPPI-001), kept out of the
 * component so they are testable without mounting it.
 *
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 */

import { translate as t } from '@nextcloud/l10n'

export const LOOKUP_URL = '/apps/integriq/api/sharepoint/lookup'
export const INTAKE_URL = '/apps/integriq/api/sharepoint/publication-intake'

/**
 * The catalog item the setup belongs to.
 *
 * @type {string}
 */
export const SHAREPOINT_CATALOG_SLUG = 'adapter:sharepoint-online'

/**
 * Whether a catalog item is the SharePoint card that offers "Route to publications".
 *
 * @param {object|null} item The catalog item row.
 * @return {boolean}
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 */
export function offersPublicationRoute(item) {
	return String(item?.slug ?? '') === SHAREPOINT_CATALOG_SLUG
}

/**
 * The query parameters of one Graph lookup.
 *
 * @param {string} credentialId The picked credential.
 * @param {string} kind sites, drives or children.
 * @param {object} params search, siteId, driveId or itemId.
 * @return {object} The query parameters, without empty values.
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 */
export function lookupParams(credentialId, kind, params = {}) {
	const query = { credential: credentialId, kind }
	for (const key of ['search', 'siteId', 'driveId', 'itemId']) {
		const value = String(params[key] ?? '').trim()
		if (value !== '') {
			query[key] = value
		}
	}
	return query
}

/**
 * The select options of a lookup answer; `foldersOnly` keeps only folders.
 *
 * @param {object} data The lookup answer `{ items }`.
 * @param {boolean} foldersOnly Keep only folders.
 * @return {object[]} `{ id, label, webUrl }` options.
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 */
export function lookupOptions(data, foldersOnly = false) {
	const items = Array.isArray(data?.items) ? data.items : []
	return items
		.filter((item) => item && item.id && (!foldersOnly || item.isFolder === true))
		.map((item) => ({
			id: String(item.id),
			label: String(item.name || item.id),
			webUrl: item.webUrl || '',
		}))
}

/**
 * Why a lookup failed, in words: a Graph refusal names the permission to grant.
 *
 * @param {object} error The failed request.
 * @return {string} The translated text.
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#scenario-graph-refuses-the-site-search
 */
export function lookupFailureText(error) {
	const data = error?.response?.data || {}
	if (data.error === 'graph_refused') {
		const text = t('integriq', 'Microsoft Graph refused this lookup. Grant the app registration the {permission} permission and try again.', { permission: data.permission || '' })
		return data.graphMessage ? `${text} (${data.graphMessage})` : text
	}
	if (data.error === 'credential_unusable') {
		return t('integriq', 'The credential could not be used. Check it in the credential broker.')
	}
	return t('integriq', 'The lookup failed. Try again.')
}

/**
 * The body the save posts.
 *
 * @param {object} state The dialog's choices.
 * @return {object} The intake request body.
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 */
export function intakeBody(state) {
	return {
		credentialId: state.credential?.id || '',
		siteId: state.site?.id || '',
		driveId: state.drive?.id || '',
		folderId: state.folder?.id || '',
		folderName: state.folder?.label || '',
		catalog: String(state.catalog?.id ?? state.catalog ?? '').trim(),
		register: String(state.register || '').trim(),
		schema: String(state.schema || '').trim(),
		requiresApproval: state.requiresApproval === true,
	}
}

/**
 * Whether every choice the save needs is made.
 *
 * @param {object} state The dialog's choices.
 * @return {boolean}
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 */
export function canSave(state) {
	const body = intakeBody(state)
	return ['credentialId', 'siteId', 'driveId', 'catalog', 'register', 'schema'].every((key) => body[key] !== '')
}

/**
 * The synchronization the save created, for "Run now".
 *
 * @param {object} data The intake answer `{ created }`.
 * @return {string|null} Its id.
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 */
export function createdSynchronizationId(data) {
	const created = data?.created?.synchronizations
	return Array.isArray(created) && created[0]?.id ? String(created[0].id) : null
}
