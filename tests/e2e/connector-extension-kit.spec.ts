/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * platform-connector-extension-kit, REQ-CXK-001 and REQ-CXK-003: what a
 * separate Nextcloud app contributes through RegisterConnectorExtensionsEvent
 * shows up in integriq's own screens, and a Marketplace card says which app
 * provided it.
 *
 * Two groups:
 *
 *  - The Marketplace card. A contributed template is materialised as a
 *    `catalog_item` carrying `providedBy` (MaterializeCatalogItems keeps it,
 *    schema 1.2.0). Reading the extension's template directory and refusing a
 *    taken slug is ContributedTemplatesTest's; this file seeds one such
 *    `catalog_item` and checks that CatalogItemCard says "Provided by <app>"
 *    on it and on no built-in card.
 *  - The skeleton extension app (`examples/connector-extension/`, app id
 *    `integriq_example_channel`), which contributes the `example-webhook`
 *    intake channel. These tests run only when that app is enabled on the
 *    instance, which is asked of Nextcloud's own app list, and skip otherwise:
 *    the skeleton is not installed by default.
 *
 * Left to PHPUnit, as the spec's `@e2e exclude` lines say: a contributed
 * payment provider chosen by id (PaymentIntentServiceProviderRegistryTest), a
 * contribution claiming a taken id such as `teams` (ConnectorExtensionKitTest),
 * `occ integriq:extensions` (ExtensionsListTest, ContributedTemplatesTest) and
 * the skeleton passing the contract test cases (its own PHPUnit run in CI).
 *
 * Runs as the admin the global setup logs in. Every object this file creates
 * is removed at the end; a channel consumer that already existed gets its own
 * authorization configuration back.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { createHmac } from 'node:crypto'
import { APP_BASE } from './spec-coverage/_helpers.ts'
import { appDialog } from './support/dialogs.ts'
import { searchStore } from './support/store.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'

/** The app id of the skeleton extension in examples/connector-extension/. */
const SKELETON_APP = 'integriq_example_channel'

/** The intake channel the skeleton contributes. */
const SKELETON_CHANNEL = 'example-webhook'

/** The label the skeleton's channel describes itself with. */
const SKELETON_CHANNEL_LABEL = 'Example webhook'

/** The HMAC secret this file gives the skeleton channel's consumer. */
const CHANNEL_SECRET = 'e2e-extension-kit-secret'

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-cxk-${Date.now().toString(36)}`

/**
 * The id of a stored OpenRegister object, whichever key carries it.
 *
 * @param row The object as the API returned it.
 * @return Its id.
 */
function idOf(row: Record<string, any>): string {
	return String(row.id ?? row.uuid ?? row['@self']?.id ?? '')
}

/**
 * Whether an app is enabled on the instance, from Nextcloud's own app list.
 *
 * @param request The request fixture, carrying the admin session.
 * @param appId The app id.
 * @return True when the app is enabled.
 */
async function appEnabled(request: APIRequestContext, appId: string): Promise<boolean> {
	const resp = await request.get('/ocs/v2.php/cloud/apps', {
		params: { filter: 'enabled', format: 'json' },
		headers: { 'OCS-APIRequest': 'true' },
		failOnStatusCode: false,
	})
	expect(resp.status(), 'the admin can read the enabled apps').toBe(200)
	const apps = ((await resp.json())?.ocs?.data?.apps ?? []) as string[]

	return apps.includes(appId)
}

/**
 * The HMAC header value of the `openconnector` scheme for a body.
 *
 * @param body The exact bytes that will be sent.
 * @return The header value.
 */
function hmacHeader(body: string): string {
	const timestamp = Math.floor(Date.now() / 1000)
	const digest = createHmac('sha256', CHANNEL_SECRET)
		.update(`${timestamp}.${body}`)
		.digest('hex')

	return `t=${timestamp},v1=${digest}`
}

/**
 * Give the skeleton channel a webhook consumer that trusts this file's secret.
 *
 * A channel's trust lives on a `consumer` whose `authorizationType` is
 * `intake-channel-<channelId>`; with none, or with two, every delivery is
 * refused. An existing one is reused and its configuration handed back so the
 * caller can restore it.
 *
 * @param request The request fixture.
 * @return The consumer's id, whether this call created it, and what it held before.
 */
async function trustSkeletonChannel(request: APIRequestContext): Promise<{
	id: string
	created: boolean
	previous: unknown
}> {
	const authorizationType = `intake-channel-${SKELETON_CHANNEL}`
	const found = await request.get(`${OR_BASE}/consumer`, {
		params: { authorizationType, _limit: '10' },
		failOnStatusCode: false,
	})
	expect(found.status()).toBe(200)
	const existing = ((await found.json()).results ?? []) as Array<Record<string, any>>
	expect(existing.length, `more than one ${authorizationType} consumer refuses every delivery`).toBeLessThan(2)

	const authorizationConfiguration = { scheme: 'openconnector', secret: CHANNEL_SECRET }
	if (existing.length === 1) {
		const id = idOf(existing[0])
		const patched = await request.patch(`${OR_BASE}/consumer/${id}`, {
			data: { authorizationConfiguration },
			failOnStatusCode: false,
		})
		expect(patched.status(), await patched.text()).toBeLessThan(300)

		return { id, created: false, previous: existing[0].authorizationConfiguration ?? null }
	}

	const resp = await request.post(`${OR_BASE}/consumer`, {
		data: {
			name: `${RUN} ${SKELETON_CHANNEL}`,
			description: 'Seeded by tests/e2e/connector-extension-kit.spec.ts',
			authorizationType,
			authorizationConfiguration,
			userId: 'admin',
		},
		failOnStatusCode: false,
	})
	expect(resp.status(), await resp.text()).toBeLessThan(300)

	return { id: idOf(await resp.json()), created: true, previous: null }
}

/**
 * Deliver one HMAC-protected payload on the skeleton channel.
 *
 * @param request The request fixture.
 * @param payload The skeleton's payload shape, `{id, from, text}`.
 * @return The parsed answer.
 */
async function deliverToSkeleton(
	request: APIRequestContext,
	payload: Record<string, unknown>,
): Promise<Record<string, any>> {
	const body = JSON.stringify(payload)
	const resp = await request.post(`${API_BASE}/intake/channels/${SKELETON_CHANNEL}/inbound`, {
		headers: {
			'Content-Type': 'application/json',
			'X-OpenConnector-Signature': hmacHeader(body),
		},
		data: body,
		failOnStatusCode: false,
	})
	expect(resp.status(), await resp.text()).toBe(202)

	return await resp.json()
}

/**
 * Open the routing rule editor for a new rule.
 *
 * @param page The page.
 * @return The dialog.
 */
async function openNewRoutingRule(page: Page) {
	await page.goto(`${APP_BASE}/intake/routing-rules`, { waitUntil: 'domcontentloaded' })
	await page.locator('main').getByRole('button', { name: /^Add\b/i }).first().click()
	const dialog = appDialog(page)
	await expect(dialog).toBeVisible({ timeout: 10_000 })
	return dialog
}

test.describe('connector extension kit: the Marketplace names the app that provided a card', () => {
	const created: string[] = []

	test.afterEach(async ({ request }) => {
		for (const id of created.splice(0)) {
			await request.delete(`${OR_BASE}/catalog_item/${id}`, { failOnStatusCode: false })
		}
	})

	// @e2e connector-extension-kit::an-administrator-checks-an-extension-after-installing-it
	test('a contributed template card says which app provided it, a built-in card does not', async ({
		page,
		request,
	}) => {
		const name = `E2E contributed template ${RUN}`
		const resp = await request.post(`${OR_BASE}/catalog_item`, {
			data: {
				slug: `source-template:${RUN}`,
				name,
				description: 'Seeded by tests/e2e/connector-extension-kit.spec.ts',
				kind: 'source-template',
				mechanism: 'always-available',
				status: 'available',
				tier: 'curated',
				category: 'e2e',
				providedBy: SKELETON_APP,
			},
			failOnStatusCode: false,
		})
		expect(resp.status(), await resp.text()).toBeLessThan(300)
		created.push(idOf(await resp.json()))

		await searchStore(page, name)
		const card = page.getByTestId('catalog-item-card').filter({ hasText: name })
		await expect(card).toHaveCount(1, { timeout: 15_000 })
		await expect(card.getByTestId('catalog-provided-by')).toHaveText(`Provided by ${SKELETON_APP}`)

		await searchStore(page, 'Salesforce')
		const builtIn = page.getByTestId('catalog-item-card').filter({ hasText: 'Salesforce' }).first()
		await expect(builtIn).toBeVisible({ timeout: 15_000 })
		await expect(builtIn.getByTestId('catalog-provided-by')).toHaveCount(0)
	})
})

test.describe('connector extension kit: with the skeleton extension app enabled', () => {
	let skeletonEnabled = false
	let consumer: { id: string, created: boolean, previous: unknown } | null = null
	const messages: string[] = []

	test.beforeAll(async ({ request }) => {
		skeletonEnabled = await appEnabled(request, SKELETON_APP)
	})

	test.beforeEach(() => {
		test.skip(!skeletonEnabled, `the skeleton extension app ${SKELETON_APP} is not enabled on this instance`)
	})

	test.afterAll(async ({ request }) => {
		for (const id of messages.splice(0)) {
			await request.delete(`${OR_BASE}/intake_message/${id}`, { failOnStatusCode: false })
		}
		if (consumer === null) {
			return
		}
		if (consumer.created) {
			await request.delete(`${OR_BASE}/consumer/${consumer.id}`, { failOnStatusCode: false })
		} else {
			await request.patch(`${OR_BASE}/consumer/${consumer.id}`, {
				data: { authorizationConfiguration: consumer.previous },
				failOnStatusCode: false,
			})
		}
		consumer = null
	})

	// @e2e connector-extension-kit::a-third-party-adds-a-chat-channel-without-touching-integriq
	test('the contributed channel is described next to the built-ins and offered in the rule editor', async ({
		page,
		request,
	}) => {
		const resp = await request.get(`${API_BASE}/intake/channels`)
		expect(resp.status()).toBe(200)
		const channels = ((await resp.json()).channels ?? []) as Array<Record<string, any>>
		const ids = channels.map((channel) => String(channel.channelId))
		expect(ids, 'the built-in channels are still there').toContain('teams')
		expect(ids, 'the extension adds its channel').toContain(SKELETON_CHANNEL)
		expect(channels.find((channel) => channel.channelId === SKELETON_CHANNEL)?.label).toBe(SKELETON_CHANNEL_LABEL)

		const dialog = await openNewRoutingRule(page)
		await dialog.getByTestId('intake-rule-channel').locator('input').click()
		await expect(
			page.getByRole('option', { name: `${SKELETON_CHANNEL_LABEL} (${SKELETON_CHANNEL})` }),
			'a rule can be written for the contributed channel like for a built-in one',
		).toBeVisible({ timeout: 10_000 })
		await page.keyboard.press('Escape')
	})

	// @e2e connector-extension-kit::a-third-party-adds-a-chat-channel-without-touching-integriq
	test('a delivery on the contributed channel is received and lands in the intake inbox', async ({
		page,
		request,
	}) => {
		consumer = await trustSkeletonChannel(request)

		const text = `${RUN} bericht via het voorbeeldkanaal`
		const answer = await deliverToSkeleton(request, {
			id: `${RUN}-1`,
			from: { name: 'E2E Afzender', email: 'afzender@example.org' },
			text,
		})
		messages.push(String(answer.id))
		expect(['held', 'routed'], 'routed like a message from a built-in channel').toContain(answer.status)

		const stored = await request.get(`${OR_BASE}/intake_message/${answer.id}`)
		expect(stored.status()).toBe(200)
		const message = await stored.json()
		expect(message.channelId).toBe(SKELETON_CHANNEL)
		expect(message.text).toBe(text)

		await page.goto(`${APP_BASE}/messages/intake`, { waitUntil: 'domcontentloaded' })
		await expect(
			page.getByText(text).first(),
			'the message is on the page somebody reads',
		).toBeVisible({ timeout: 20_000 })
	})
})
