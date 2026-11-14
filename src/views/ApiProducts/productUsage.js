/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The per-consumer and per-endpoint usage lists of an API product's analytics.
 *
 * @spec openspec/changes/gateway-usage-per-consumer-and-endpoint/specs/api-product-gateway/spec.md#requirement-product-usage-is-split-per-consumer-and-per-endpoint-req-apgu-001
 */

/**
 * The two usage lists the analytics response carries, in display order.
 *
 * @param {object|null} analytics The `GET /api/products/{id}/analytics` response.
 * @param {(app: string, text: string) => string} t The `t` function, called as t('integriq', text).
 * @return {Array<{key: string, title: string, column: string, entries: Array<object>}>} One list per split; an absent split is an empty list.
 */
export function usageSplits(analytics, t) {
	const entries = (key) => (Array.isArray(analytics?.[key]) ? analytics[key] : [])

	return [
		{
			key: 'consumer',
			title: t('integriq', 'Per consumer'),
			column: t('integriq', 'Consumer'),
			entries: entries('byConsumer'),
		},
		{
			key: 'endpoint',
			title: t('integriq', 'Per endpoint'),
			column: t('integriq', 'Endpoint'),
			entries: entries('byEndpoint'),
		},
	]
}

/**
 * The row label of one usage entry: its name, else its id, else "Anonymous"
 * for traffic no consumer (or endpoint) is recorded for.
 *
 * @param {object} entry A `byConsumer` or `byEndpoint` entry.
 * @param {string} key `consumer` or `endpoint`, the field holding the entry's id.
 * @param {(app: string, text: string) => string} t The `t` function.
 * @return {string} The label.
 */
export function usageLabel(entry, key, t) {
	if (typeof entry?.name === 'string' && entry.name !== '') {
		return entry.name
	}

	if (typeof entry?.[key] === 'string' && entry[key] !== '') {
		return entry[key]
	}

	return t('integriq', 'Anonymous')
}
