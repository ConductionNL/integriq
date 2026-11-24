/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md
 * (REQ-PUOA-003, a user sees and disconnects their own accounts; the
 * Playwright half of task 3 in that change's tasks.md).
 *
 * What a user does here: open "Personal settings" from the app's settings
 * foldout, which opens the app's own settings dialog (not a Nextcloud
 * /settings/user page: as built in task 3, the overview is the "Connected
 * accounts" section, ConnectedAccountsSection.vue, slotted into CnAppRoot's
 * #user-settings-sections). They find their Microsoft account there by the
 * name the provider returned, press Disconnect, and the account is gone from
 * the list. Their next call to an endpoint on a per-user source then answers
 * the connect prompt: 401 with type `connect-account-required` and a
 * `connectUrl` at the credential broker.
 *
 * The connected account itself is answered in the browser, as
 * idp-registration-settings.spec.ts does for the broker's lists: a personal
 * `oauth2-token-set` is only written by the broker's connect flow, which
 * needs an outside OAuth provider a browser run cannot stage. So the section's
 * `GET /apps/openregister/api/credentials` returns one canned account, and its
 * `DELETE /apps/openregister/api/credentials/oauth2/{id}` is caught and
 * answered 204. The per-user source and its endpoint are real: they are
 * staged through OpenRegister's object API
 * (`/index.php/apps/openregister/api/objects/integriq/<schema>`, with
 * `OCS-APIRequest: true`), and the 401 comes from integriq itself, because
 * the admin this suite runs as has no account at this run's provider.
 *
 * Left to PHPUnit and Vitest: the broker revoking the token upstream and
 * disabling it (the broker's own suite), the listing filter and the
 * disconnect URL (tests/vitest/connectedAccounts.spec.js), and the prompt's
 * shape (ConnectAccountPromptTest). A run against a mock provider through the
 * real connect flow is still owed, as task 3 says.
 *
 * Runs as the admin the global setup logs in. The source and the endpoint are
 * made under this run's marker and deleted afterwards; nothing instance-wide
 * is changed, since the broker calls never leave the browser.
 */

import type { APIRequestContext, Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute } from './support/appRoot.ts'
import { appDialog } from './support/dialogs.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-puoa-${Date.now().toString(36)}`

/**
 * The provider slug the source names. Made for this run, so the admin can
 * never already hold a real account at it.
 */
const PROVIDER = `e2e-microsoft-${RUN}`

/** The gateway path, below `/api/endpoint/`. */
const ENDPOINT_PATH = `${RUN}/me/calendar`

/** The connected account the broker lists, as the section reads it. */
const ACCOUNT = {
	id: `e2e0acc0-0000-4000-8000-${Date.now().toString(16).padStart(12, '0').slice(-12)}`,
	kind: 'oauth2-token-set',
	scope: 'personal',
	status: 'active',
	provider: PROVIDER,
	account: { displayName: `J. Bakker ${RUN}` },
	'@self': { created: '2026-11-01T09:00:00Z' },
}

/** The broker's listing of the caller's credentials (no query string). */
const CREDENTIALS_ROUTE = /\/apps\/openregister\/api\/credentials(\?.*)?$/

/** The broker's disconnect endpoint for one account. */
const DISCONNECT_ROUTE = /\/apps\/openregister\/api\/credentials\/oauth2\/[^/?]+/

const cleanup: Array<{ schema: string, id: string }> = []

/**
 * Create one object through OpenRegister's object API and remember it for cleanup.
 *
 * @param request The request fixture.
 * @param schema  The schema slug.
 * @param data    The object.
 * @return The object's OpenRegister id.
 */
async function seedObject(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, { failOnStatusCode: false, headers: HEADERS, data })
	expect([200, 201], `seeding a ${schema}: ${await resp.text()}`).toContain(resp.status())
	const body = await resp.json()
	const id = body.id ?? body.uuid ?? body['@self']?.id
	expect(typeof id, `the seeded ${schema} has an id`).toBe('string')
	cleanup.push({ schema, id: id as string })

	return id as string
}

/**
 * Answer the broker's listing with the canned account until it is
 * disconnected, and catch the disconnect.
 *
 * @param page The page.
 * @return The ids the section asked the broker to disconnect.
 */
async function stageBroker(page: Page): Promise<string[]> {
	const disconnected: string[] = []
	await page.route(DISCONNECT_ROUTE, async (route: Route) => {
		if (route.request().method() !== 'DELETE') {
			await route.fallback()
			return
		}
		const url = new URL(route.request().url())
		disconnected.push(decodeURIComponent(url.pathname.split('/').pop() ?? ''))
		await route.fulfill({ status: 204, body: '' })
	})
	await page.route(CREDENTIALS_ROUTE, async (route: Route) => {
		if (route.request().method() !== 'GET') {
			await route.fallback()
			return
		}
		const results = disconnected.includes(ACCOUNT.id) ? [] : [ACCOUNT]
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ results }) })
	})

	return disconnected
}

/**
 * Open the app's settings dialog through "Personal settings" in the
 * navigation's settings foldout.
 *
 * @param page The page.
 * @return Nothing; the dialog is open afterwards.
 */
async function openPersonalSettings(page: Page): Promise<void> {
	await gotoAppRoute(page, '/')
	const nav = page.getByTestId('cn-nav')
	const entry = nav.getByTestId('cn-nav-personal-settings')
	await expect(nav.getByTestId('cn-nav-settings')).toBeAttached({ timeout: 30_000 })
	if ((await entry.isVisible().catch(() => false)) === false) {
		// The foldout's own toggle; its label is the library's "Advanced".
		await nav.getByTestId('cn-nav-settings').getByRole('button').first().click()
	}
	await entry.click()
	await expect(appDialog(page), 'the settings dialog opens').toBeVisible({ timeout: 15_000 })
}

/**
 * Call the per-user endpoint as the logged-in admin, as a page in another app would.
 *
 * @param request The request fixture, which carries the admin session.
 * @return The status and the problem document.
 */
async function callAsLoggedInUser(request: APIRequestContext): Promise<{ status: number, body: Record<string, any> }> {
	const resp = await request.get(`${API_BASE}/endpoint/${ENDPOINT_PATH}`, { headers: HEADERS, failOnStatusCode: false })
	const text = await resp.text()
	let body: Record<string, any>
	try {
		body = JSON.parse(text)
	} catch {
		body = { raw: text }
	}

	return { status: resp.status(), body }
}

test.describe('sources-per-user-oauth: a user\'s connected accounts', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		const sourceId = await seedObject(request, 'source', {
			name: `E2E calendar ${RUN}`,
			description: 'Seeded by tests/e2e/personal-connected-accounts.spec.ts',
			type: 'api',
			location: 'https://calendar.invalid',
			isEnabled: true,
			configuration: { authentication: { credentialRef: { provider: PROVIDER, perUser: true } } },
		})
		await seedObject(request, 'endpoint', {
			name: `E2E my calendar ${RUN}`,
			description: 'Seeded by tests/e2e/personal-connected-accounts.spec.ts',
			endpoint: ENDPOINT_PATH,
			upstreamPath: '/me/calendar',
			method: 'GET',
			targetType: 'api',
			targetId: sourceId,
			rules: [],
			conditions: [],
		})
	})

	test.afterAll(async ({ request }) => {
		// The endpoint first, then the source it calls.
		for (const { schema, id } of cleanup.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}
	})

	// @e2e http-call-engine::a-user-disconnects-an-account
	test('a user disconnects their account in the settings dialog, and the next call asks them to connect it', async ({ page, request }) => {
		const disconnected = await stageBroker(page)
		await openPersonalSettings(page)
		const dialog = appDialog(page)

		await expect(dialog.getByText('Connected accounts', { exact: true }).first()).toBeVisible({ timeout: 15_000 })
		await expect(dialog.getByText(PROVIDER, { exact: true }), 'the account names its provider').toBeVisible({ timeout: 15_000 })
		await expect(dialog.getByText(ACCOUNT.account.displayName, { exact: true }), 'and the identity the provider returned').toBeVisible()

		await dialog.getByRole('button', { name: `Disconnect ${ACCOUNT.account.displayName}` }).click()

		await expect.poll(() => disconnected, { message: 'the section asked the broker to disconnect this account' }).toEqual([ACCOUNT.id])
		await expect(dialog.getByText(ACCOUNT.account.displayName, { exact: true }), 'the account is gone from the list').toHaveCount(0)
		await expect(dialog.getByText('You have not connected an account yet.')).toBeVisible()

		const next = await callAsLoggedInUser(request)
		expect(next.status, `the next call answers the connect prompt: ${JSON.stringify(next.body)}`).toBe(401)
		expect(String(next.body.type ?? '')).toMatch(/connect-account-required$/)
		expect(next.body.provider).toBe(PROVIDER)
		expect(String(next.body.connectUrl ?? ''), 'the prompt links to the broker\'s connect start').toContain(encodeURIComponent(PROVIDER))
	})
})
