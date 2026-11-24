/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md
 * (REQ-AAPI-001, the administrator adds a channel on the product detail page).
 *
 * The consumer routes (403 subscription_required, the shared tier budget, the
 * refused internal sink, the AsyncAPI document) are API calls and are covered
 * by the Newman folder "18 — Product channels" in tests/postman/ and by
 * PHPUnit; the scenarios carrying `@e2e exclude` in the spec say so.
 *
 * Each test seeds its own product version through OpenRegister's object API
 * under a unique slug and deletes it again, so nothing depends on the seed
 * data and nothing is left behind.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

const CHANNEL = 'publications'
const CHANNEL_TITLE = 'Publicaties'
const CHANNEL_TYPE = 'nl.woo.publication.created'

/**
 * Seed one product version without channels.
 *
 * @param request The Playwright request context.
 * @return The product's OpenRegister id.
 */
async function seedProduct(request: APIRequestContext): Promise<string> {
	const slug = `e2e-channels-${Date.now()}-${Math.floor(Math.random() * 10_000)}`
	const resp = await request.post(`${OR_BASE}/api_product`, {
		failOnStatusCode: false,
		headers: { 'OCS-APIRequest': 'true' },
		data: {
			name: 'E2E Woo-publicaties',
			productSlug: slug,
			version: '2.0.0',
			visibility: 'public',
			status: 'active',
			endpoints: [],
			tiers: {},
			channels: [],
		},
	})
	expect([200, 201], await resp.text()).toContain(resp.status())
	const body = await resp.json()
	const id = body.id ?? body.uuid ?? body['@self']?.id
	expect(typeof id, 'the seeded product has an id').toBe('string')

	return id as string
}

/**
 * The channels the stored product carries.
 *
 * @param request The Playwright request context.
 * @param id      The product's OpenRegister id.
 * @return The stored channels.
 */
async function storedChannels(
	request: APIRequestContext,
	id: string,
): Promise<Array<Record<string, unknown>>> {
	const resp = await request.get(`${OR_BASE}/api_product/${id}`, {
		failOnStatusCode: false,
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(resp.status()).toBe(200)

	return ((await resp.json()).channels ?? []) as Array<Record<string, unknown>>
}

/**
 * Open the product's detail page and wait for its channels section.
 *
 * @param page The page.
 * @param id   The product's OpenRegister id.
 * @return The channels section.
 */
async function openChannels(page: Page, id: string) {
	await page.goto(`${APP_BASE}/products/${id}`, { waitUntil: 'domcontentloaded' })
	const section = page.getByTestId('api-product-channels')
	await expect(section).toBeVisible({ timeout: 30_000 })

	return section
}

/**
 * Fill the channel form and submit it.
 *
 * @param section The channels section.
 */
async function addChannel(section: ReturnType<Page['getByTestId']>): Promise<void> {
	await section.getByLabel('Channel slug').fill(CHANNEL)
	await section.getByLabel('Channel title').fill(CHANNEL_TITLE)
	await section.getByLabel('CloudEvent types, one per line').fill(CHANNEL_TYPE)
	await section.getByRole('button', { name: 'Add channel' }).click()
}

test.describe('API product channels', () => {
	let productId = ''

	test.beforeEach(async ({ request }) => {
		productId = await seedProduct(request)
	})

	test.afterEach(async ({ request }) => {
		if (productId !== '') {
			await request.delete(`${OR_BASE}/api_product/${productId}`, {
				failOnStatusCode: false,
				headers: { 'OCS-APIRequest': 'true' },
			})
		}
	})

	// @e2e openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#scenario-an-administrator-adds-a-publications-channel
	test('an administrator adds a publications channel and it is listed with its type', async ({
		page,
		request,
	}) => {
		const section = await openChannels(page, productId)
		await expect(
			section.getByText('This product version carries no event channels yet.'),
		).toBeVisible()

		await addChannel(section)

		const row = section.getByRole('row').filter({ hasText: CHANNEL_TITLE })
		await expect(row).toBeVisible({ timeout: 15_000 })
		await expect(row).toContainText(CHANNEL)
		await expect(row).toContainText(CHANNEL_TYPE)
		await expect(
			section.getByRole('link', { name: 'AsyncAPI document of these channels' }),
		).toBeVisible()

		// The channel is stored on the product, not only shown.
		await expect
			.poll(async () => (await storedChannels(request, productId)).map((c) => c.slug))
			.toEqual([CHANNEL])
		const [stored] = await storedChannels(request, productId)
		expect(stored.types).toEqual([CHANNEL_TYPE])

		// And it is still listed after a reload.
		const reloaded = await openChannels(page, productId)
		await expect(reloaded.getByRole('row').filter({ hasText: CHANNEL_TITLE })).toBeVisible()
	})

	// @e2e openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
	test('a second channel with the same slug is refused and nothing changes', async ({
		page,
		request,
	}) => {
		const section = await openChannels(page, productId)
		await addChannel(section)
		await expect(section.getByRole('row').filter({ hasText: CHANNEL_TITLE })).toBeVisible({
			timeout: 15_000,
		})

		await addChannel(section)

		await expect(section.getByRole('alert')).toHaveText(
			'This product version already has a channel with this slug.',
		)
		await expect(section.getByRole('row').filter({ hasText: CHANNEL_TITLE })).toHaveCount(1)
		expect((await storedChannels(request, productId)).length).toBe(1)
	})
})
