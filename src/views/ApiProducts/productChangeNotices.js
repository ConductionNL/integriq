// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import { translate as t } from '@nextcloud/l10n'

/**
 * The label of a product_change_notice kind.
 *
 * @param {string} kind The kind.
 * @return {string} The translated label, or the kind itself when unknown.
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
 */
export function noticeKindLabel(kind) {
	const labels = {
		deprecated: t('integriq', 'Deprecated'),
		'sunset-date-set': t('integriq', 'Sunset date set'),
		'new-version': t('integriq', 'New version'),
		retired: t('integriq', 'Retired'),
		'subscription-expiring': t('integriq', 'Subscription ending'),
	}
	return labels[kind] || kind || ''
}

/**
 * The notices of an object-API answer, newest first.
 *
 * @param {object|Array} data The answer: `{results}` or a list.
 * @return {Array<object>} The notices.
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
 */
export function noticesOf(data) {
	const rows = Array.isArray(data?.results) ? data.results : Array.isArray(data) ? data : []
	return [...rows].sort((a, b) => String(b.sentAt || '').localeCompare(String(a.sentAt || '')))
}
