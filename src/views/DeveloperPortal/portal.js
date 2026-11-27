// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import { translate as t } from '@nextcloud/l10n'

/**
 * The products as select options.
 *
 * @param {Array<object>} products The portal's products.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
 */
export function productOptionsOf(products) {
	return (products || []).map((product) => ({
		id: product.id,
		label: product.version ? `${product.name} ${product.version}` : product.name,
	}))
}

/**
 * The tier names of a product, its default tier first.
 *
 * @param {object|undefined} product The product.
 * @return {Array<string>} The tier names.
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
 */
export function tierNamesOf(product) {
	const names = (product?.tiers || []).map((tier) => tier.name)
	const first = product?.defaultTier
	if (first && names.includes(first)) {
		return [first, ...names.filter((name) => name !== first)]
	}
	return names
}

/**
 * What a developer reads after requesting access.
 *
 * @param {number} status The HTTP status of the answer.
 * @return {string} The message.
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
 */
export function requestOutcome(status) {
	if (status === 202) {
		return t('integriq', 'Access requested. The product\'s approvers have been asked.')
	}
	return t('integriq', 'Access granted.')
}

/**
 * The label of a subscription status.
 *
 * @param {string} status The status.
 * @return {string} The label.
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-subscription-can-end-on-a-date-req-devp-005
 */
export function subscriptionStatusLabel(status) {
	const labels = {
		pending_approval: t('integriq', 'Waiting for approval'),
		active: t('integriq', 'Active'),
		rejected: t('integriq', 'Rejected'),
		revoked: t('integriq', 'Revoked'),
		expired: t('integriq', 'Expired'),
	}
	return labels[status] || status || ''
}
