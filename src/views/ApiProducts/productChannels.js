// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The channel list of an API product version, as the detail page edits it.
 *
 * Kept apart from ApiProductDetail.vue so the rules can be tested without
 * mounting the page: a channel has a slug in the schema's pattern, a title and
 * at least one CloudEvent type, and its slug is unique within the version
 * (events-async-api-products, REQ-AAPI-001). The server refuses a repeated slug
 * as well; this answers before the save is sent.
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
 */

const SLUG = /^[a-z0-9][a-z0-9-]*$/

/**
 * The product's channels, or an empty list.
 *
 * @param {object|null} product The api_product object.
 * @return {Array<object>} The channels.
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
 */
export function channelsOf(product) {
	return Array.isArray(product?.channels) ? product.channels : []
}

/**
 * The CloudEvent types typed in one field, one per line or comma separated.
 *
 * @param {string} text The typed text.
 * @return {Array<string>} The types, trimmed, without empties or repeats.
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
 */
export function parseTypes(text) {
	return [...new Set(String(text || '').split(/[\n,]/).map((type) => type.trim()).filter(Boolean))]
}

/**
 * The channel list with a draft added, or replacing the channel it edits.
 *
 * @param {Array<object>} channels The current channels.
 * @param {{slug: string, title: string, types: string, description: string}} draft The form.
 * @param {string} editing The slug of the channel being edited, or ''.
 * @return {{channels?: Array<object>, error?: string}} The new list, or why it is refused.
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
 */
export function withChannel(channels, draft, editing = '') {
	const slug = String(draft?.slug || '').trim()
	const title = String(draft?.title || '').trim()
	const types = parseTypes(draft?.types)
	if (!SLUG.test(slug)) {
		return { error: 'slug' }
	}
	if (title === '') {
		return { error: 'title' }
	}
	if (types.length === 0) {
		return { error: 'types' }
	}
	const others = (Array.isArray(channels) ? channels : []).filter((channel) => channel?.slug !== editing)
	if (others.some((channel) => channel?.slug === slug)) {
		return { error: 'duplicate' }
	}
	const channel = { slug, title, types }
	const description = String(draft?.description || '').trim()
	if (description !== '') {
		channel.description = description
	}
	if (editing === '') {
		return { channels: [...others, channel] }
	}
	return { channels: (channels || []).map((existing) => (existing?.slug === editing ? channel : existing)) }
}

/**
 * The channel list without one channel.
 *
 * @param {Array<object>} channels The current channels.
 * @param {string} slug The slug to remove.
 * @return {Array<object>} The remaining channels.
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
 */
export function withoutChannel(channels, slug) {
	return (Array.isArray(channels) ? channels : []).filter((channel) => channel?.slug !== slug)
}

/**
 * The form filled from a channel, for editing it.
 *
 * @param {object} channel The channel.
 * @return {{slug: string, title: string, types: string, description: string}} The form.
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
 */
export function draftOf(channel) {
	return {
		slug: channel?.slug || '',
		title: channel?.title || '',
		types: (Array.isArray(channel?.types) ? channel.types : []).join('\n'),
		description: channel?.description || '',
	}
}
