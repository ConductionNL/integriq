/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The developer portal's helpers: product options, tier order, the outcome of
 * an access request, subscription status labels, and the change-notice list
 * on the product page.
 *
 * @spec openspec/specs/api-product-gateway/spec.md
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, key) => (key in vars ? String(vars[key]) : `{${key}}`)),
}))

const { productOptionsOf, requestOutcome, subscriptionStatusLabel, tierNamesOf } = await import('../../src/views/DeveloperPortal/portal.js')
const { noticeKindLabel, noticesOf } = await import('../../src/views/ApiProducts/productChangeNotices.js')

describe('developer portal helpers', () => {
	it('labels each product with its version', () => {
		expect(productOptionsOf([{ id: 'p-1', name: 'Zaken API', version: '1.0.0' }, { id: 'p-2', name: 'Besluiten API' }]))
			.toEqual([{ id: 'p-1', label: 'Zaken API 1.0.0' }, { id: 'p-2', label: 'Besluiten API' }])
		expect(productOptionsOf(undefined)).toEqual([])
	})

	it('puts the default tier first', () => {
		const product = { defaultTier: 'basic', tiers: [{ name: 'premium' }, { name: 'basic' }] }
		expect(tierNamesOf(product)).toEqual(['basic', 'premium'])
		expect(tierNamesOf({ defaultTier: 'gone', tiers: [{ name: 'a' }] })).toEqual(['a'])
		expect(tierNamesOf(undefined)).toEqual([])
	})

	it('tells a pending request from a granted one', () => {
		expect(requestOutcome(202)).toBe('Access requested. The product\'s approvers have been asked.')
		expect(requestOutcome(201)).toBe('Access granted.')
	})

	it('labels subscription states and keeps an unknown one', () => {
		expect(subscriptionStatusLabel('pending_approval')).toBe('Waiting for approval')
		expect(subscriptionStatusLabel('expired')).toBe('Expired')
		expect(subscriptionStatusLabel('paused')).toBe('paused')
	})
})

describe('product change notices', () => {
	it('labels the kinds', () => {
		expect(noticeKindLabel('sunset-date-set')).toBe('Sunset date set')
		expect(noticeKindLabel('subscription-expiring')).toBe('Subscription ending')
		expect(noticeKindLabel('other')).toBe('other')
	})

	it('lists notices newest first from either answer shape', () => {
		const older = { id: 'a', sentAt: '2026-11-01T09:00:00+00:00' }
		const newer = { id: 'b', sentAt: '2026-11-02T09:00:00+00:00' }
		expect(noticesOf({ results: [older, newer] }).map((n) => n.id)).toEqual(['b', 'a'])
		expect(noticesOf([older]).map((n) => n.id)).toEqual(['a'])
		expect(noticesOf(null)).toEqual([])
	})
})
