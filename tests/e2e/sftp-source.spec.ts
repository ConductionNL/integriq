/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md
 * (REQ-SFTP-001, a partner's SFTP or FTPS server is a source; the scenario
 * "an administrator pins a partner's server").
 *
 * What an administrator does here: open a new SFTP source for
 * `sftp.example.nl` on its page, where the "Host key" panel
 * (SourceHostKeyPanel) says no key is pinned yet. They press "Check the host
 * key", read the SHA-256 fingerprint the server presents, tick that they
 * checked it with the partner, and pin it. A source that was saved with a pin
 * shows that fingerprint when it is opened again.
 *
 * The SFTP source is staged through OpenRegister's object API
 * (`/index.php/apps/openregister/api/objects/integriq/source` with
 * `OCS-APIRequest: true`), the same row the Sources page writes: the source
 * form has no SFTP-specific fields, and the type, location and configuration
 * keys are the ones RemoteServerConfig reads. A browser run has no SFTP
 * server to present a key, so the server's answer to the host key routes
 * (GET and POST /api/sources/{id}/host-key) is stubbed in the browser in the
 * first test; the test asserts what the panel sends and what it shows. The
 * third test calls the real route against a host that does not resolve and
 * shows the panel reports the failure and pins nothing.
 *
 * Left to PHPUnit and the SFTP container run: reading the real host key and
 * its fingerprint (HostKeyPin, SftpSession), the controller refusing to pin
 * when the server meanwhile presents another key and saving the pin on the
 * source (SourceHostKeyController), and a changed host key stopping the
 * connection (the scenario excluded from e2e).
 *
 * Runs as the admin the global setup logs in. Everything is seeded under this
 * run's marker and deleted afterwards.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

// CnBodySections sets its own data-testid on a body widget's root element,
// which replaces SourceHostKeyPanel's `source-host-key-panel`.
const PANEL = 'cn-body-section-component-src-host-key'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-sftp-${Date.now().toString(36)}`

/** The host-key route of the source page, for any source id. */
const HOST_KEY_ROUTE = /\/apps\/integriq\/api\/sources\/[^/]+\/host-key$/

/** A fingerprint in the OpenSSH form HostKeyPin produces. */
const PRESENTED = 'SHA256:nThbg6kXUpJWGl7E1IGOCspRomTxdCARLviKw6E5SY8'

const ids: Record<string, string> = {}
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
 * An SFTP source as RemoteServerConfig reads it.
 *
 * @param name     The source name.
 * @param location The server's host name.
 * @param pin      The pinned fingerprint, or '' for none.
 * @return The source object.
 */
function sftpSource(name: string, location: string, pin: string): Record<string, unknown> {
	const configuration: Record<string, unknown> = { port: 22, username: 'partner', rootPath: '/outgoing' }
	if (pin !== '') {
		configuration.hostKeyFingerprint = pin
	}

	return { name, type: 'sftp', location, isEnabled: false, configuration }
}

/**
 * Open a source's page and wait for its host key panel.
 *
 * @param page The page.
 * @param id   The source id.
 * @return The panel.
 */
async function openHostKeyPanel(page: Page, id: string): Promise<Locator> {
	await gotoAppRoute(page, `/sources/${id}`)
	await expectRouteMatched(page, `/sources/${id}`)
	const panel = page.getByTestId(PANEL)
	await expect(panel, 'an SFTP source shows its host key panel').toBeVisible({ timeout: 30_000 })

	return panel
}

test.describe('sources-sftp-adapter: pinning a partner server\'s host key', () => {
	test.beforeAll(async ({ request }) => {
		ids.unpinned = await seedObject(request, 'source', sftpSource(`E2E partner ${RUN}`, 'sftp.example.nl', ''))
		ids.pinned = await seedObject(request, 'source', sftpSource(`E2E pinned partner ${RUN}`, 'sftp.example.nl', PRESENTED))
		ids.unreachable = await seedObject(request, 'source', sftpSource(`E2E unreachable ${RUN}`, `sftp.${RUN}.invalid`, ''))
		ids.api = await seedObject(request, 'source', { name: `E2E api ${RUN}`, type: 'api', location: 'https://api.invalid' })
	})

	test.afterAll(async ({ request }) => {
		for (const { schema, id } of cleanup.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}
	})

	// @e2e data-infra-connectors::an-administrator-pins-a-partner-s-server
	test('the presented fingerprint is shown and pinned once the administrator confirms it', async ({ page }) => {
		const pinRequests: Array<Record<string, unknown>> = []
		await page.route(HOST_KEY_ROUTE, async (route) => {
			if (route.request().method() === 'POST') {
				pinRequests.push(route.request().postDataJSON() as Record<string, unknown>)
				return route.fulfill({ json: { pinned: PRESENTED } })
			}
			return route.fulfill({ json: { pinned: null, presented: PRESENTED, matches: false } })
		})

		const panel = await openHostKeyPanel(page, ids.unpinned)
		await expect(panel.getByTestId('source-host-key-unpinned')).toBeVisible()
		await expect(panel.getByTestId('source-host-key-pinned')).toHaveCount(0)

		await panel.getByTestId('source-host-key-check').click()
		await expect(panel.getByTestId('source-host-key-presented')).toContainText(PRESENTED)

		// The key cannot be pinned before the administrator says they checked it.
		const pin = panel.getByTestId('source-host-key-pin')
		await expect(pin).toBeDisabled()
		await panel.getByText('I checked this fingerprint with the partner').click()
		await expect(pin).toBeEnabled()
		await pin.click()

		// The panel sent the fingerprint it showed, and now shows it pinned.
		await expect(panel.getByTestId('source-host-key-pinned')).toContainText(PRESENTED)
		expect(pinRequests).toEqual([{ fingerprint: PRESENTED }])
		await expect(panel.getByTestId('source-host-key-matches')).toBeVisible()
		await expect(panel.getByTestId('source-host-key-pin')).toHaveCount(0)
	})

	test('a source saved with a pin shows its fingerprint when opened', async ({ page }) => {
		const panel = await openHostKeyPanel(page, ids.pinned)
		await expect(panel.getByTestId('source-host-key-pinned')).toContainText(PRESENTED)
		await expect(panel.getByTestId('source-host-key-unpinned')).toHaveCount(0)
	})

	test('a server that cannot be reached shows why and pins nothing', async ({ page, request }) => {
		const panel = await openHostKeyPanel(page, ids.unreachable)
		const answered = page.waitForResponse((res) => HOST_KEY_ROUTE.test(new URL(res.url()).pathname) && res.request().method() === 'GET')
		await panel.getByTestId('source-host-key-check').click()
		const response = await answered
		expect(response.status(), 'the controller says the key could not be read').toBe(502)

		await expect(panel.getByRole('alert')).toBeVisible({ timeout: 30_000 })
		await expect(panel.getByTestId('source-host-key-presented')).toHaveCount(0)
		await expect(panel.getByTestId('source-host-key-unpinned')).toBeVisible()

		const stored = await request.get(`${OR_BASE}/source/${ids.unreachable}`, { headers: HEADERS })
		expect(stored.status()).toBe(200)
		const data = await stored.json()
		expect(data.configuration?.hostKeyFingerprint ?? '').toBe('')
	})

	test('a source of another type has no host key panel', async ({ page }) => {
		await gotoAppRoute(page, `/sources/${ids.api}`)
		await expectRouteMatched(page, `/sources/${ids.api}`)
		await expect(page.getByText(`E2E api ${RUN}`).first()).toBeVisible({ timeout: 30_000 })
		await expect(page.getByTestId(PANEL)).toHaveCount(0)
	})
})
