/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/archive/2026-11-24-connectors-endpoint-workspace-adapters/specs/endpoint-workspace-connectors/spec.md
 *   REQ-EWC-008, task 2: the Devices and Sessions widgets on a source page.
 *
 * A source whose `configuration.workspaceAdapter` names a device manager
 * (Intune, Jamf Pro) shows a Devices widget; one naming a virtual desktop
 * (Azure Virtual Desktop, Citrix DaaS) shows a Sessions widget; a source
 * without an adapter shows neither. Both read
 * GET /api/sources/{id}/workspace-entries.
 *
 * The scenario "a source without a credential lists nothing" runs here as
 * written: a Jamf Pro source with no brokered credential answers
 * `credentialMissing: true` with no entries, and the widget shows its empty
 * text. The two scenarios that list real devices and sessions (Intune owners,
 * a Citrix session for an unknown account) need a vendor answering, so they
 * are covered by PHPUnit against recorded vendor answers
 * (tests/Unit/Service/Adapter/EndpointWorkspaceAdaptersTest.php).
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const APP_API = '/index.php/apps/integriq/api'
const RUN = `e2e-workspace-${Date.now()}`

/** Source ids this file created, removed again in afterAll. */
const createdSources: string[] = []

/**
 * The id of an OpenRegister object as the object API returns it.
 *
 * @param body The object body.
 * @return The id.
 */
function idOf(body: Record<string, unknown>): string {
	return String(body.id ?? (body['@self'] as Record<string, string> | undefined)?.id ?? body.uuid ?? '')
}

/**
 * Seed a source through the object API, without a credential.
 *
 * @param request The admin request context.
 * @param slug A slug unique to the calling test.
 * @param workspaceAdapter The adapter id, or null for a source without one.
 * @return The created source's id.
 */
async function seedSource(request: APIRequestContext, slug: string, workspaceAdapter: string | null): Promise<string> {
	const configuration: Record<string, string> = {}
	if (workspaceAdapter !== null) {
		configuration.workspaceAdapter = workspaceAdapter
	}
	const resp = await request.post(`${OR_BASE}/source`, {
		failOnStatusCode: false,
		headers: await withRequestToken(request),
		data: {
			name: slug,
			slug,
			description: 'Seeded by tests/e2e/endpoint-workspace.spec.ts',
			type: 'api',
			isEnabled: true,
			location: 'https://workspace.example.invalid',
			configuration,
		},
	})
	expect(resp.status(), `seeding source ${slug}: ${await resp.text()}`).toBeLessThan(300)
	const id = idOf((await resp.json()) as Record<string, unknown>)
	expect(id).not.toBe('')
	createdSources.push(id)
	return id
}

test.describe('connectors-endpoint-workspace-adapters: sessions and devices on a source', () => {
	test.afterAll(async ({ request }) => {
		const headers = await withRequestToken(request)
		for (const id of createdSources) {
			await request.delete(`${OR_BASE}/source/${id}`, { headers, failOnStatusCode: false })
		}
	})

	test('a source without a credential calls nothing and says so', async ({ request }) => {
		const id = await seedSource(request, `${RUN}-jamf`, 'jamf-pro')

		const resp = await request.get(`${APP_API}/sources/${id}/workspace-entries`, {
			headers: await withRequestToken(request),
			failOnStatusCode: false,
		})
		expect(resp.status(), await resp.text()).toBe(200)
		const body = (await resp.json()) as Record<string, unknown>
		expect(body.adapter).toBe('jamf-pro')
		expect(body.kind).toBe('device')
		expect(body.credentialMissing).toBe(true)
		expect(body.entries).toEqual([])
		expect(String(body.message)).toContain('no credential')
	})

	test('a source that is not a workspace manager is refused', async ({ request }) => {
		const id = await seedSource(request, `${RUN}-plain`, null)

		const resp = await request.get(`${APP_API}/sources/${id}/workspace-entries`, {
			headers: await withRequestToken(request),
			failOnStatusCode: false,
		})
		expect(resp.status(), await resp.text()).toBe(400)
	})

	test('a device manager source shows the Devices widget with its empty text', async ({ page }) => {
		test.setTimeout(90_000)
		const id = await seedSource(page.request, `${RUN}-intune`, 'intune')

		const answered = page.waitForResponse((res) => res.url().includes(`/api/sources/${id}/workspace-entries`))
		await gotoAppRoute(page, `/sources/${id}`)
		await expectRouteMatched(page, `/sources/${id}`)
		expect((await answered).status()).toBe(200)

		await expect(page.getByText('Devices', { exact: true }).first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText(/^No devices listed\./).first()).toBeVisible()
		await expect(page.getByText('Sessions', { exact: true })).toHaveCount(0)
	})

	test('a virtual desktop source shows the Sessions widget, not Devices', async ({ page }) => {
		test.setTimeout(90_000)
		const id = await seedSource(page.request, `${RUN}-citrix`, 'citrix-daas')

		await gotoAppRoute(page, `/sources/${id}`)
		await expectRouteMatched(page, `/sources/${id}`)

		await expect(page.getByText('Sessions', { exact: true }).first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText(/^No sessions listed\./).first()).toBeVisible()
		await expect(page.getByText('Devices', { exact: true })).toHaveCount(0)
	})

	test('a source without a workspace adapter shows neither widget', async ({ page }) => {
		test.setTimeout(90_000)
		const id = await seedSource(page.request, `${RUN}-none`, null)

		await gotoAppRoute(page, `/sources/${id}`)
		await expectRouteMatched(page, `/sources/${id}`)

		await expect(page.getByText(/^No devices listed\./)).toHaveCount(0)
		await expect(page.getByText(/^No sessions listed\./)).toHaveCount(0)
	})
})
