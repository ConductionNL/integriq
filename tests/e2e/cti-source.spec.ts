/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/archive/2026-11-24-kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md
 *   Task 4: the CTI provider picker and field mapping on the source form.
 *
 * An administrator creates a source of type Telephony (CTI): the raw
 * configuration editor gives way to the telephony settings, the provider
 * picker offers the two bindings CtiSourceResolver knows, and the webhook
 * binding shows its field and kind mapping. A seeded CTI source answers its
 * events URL: 401 without the shared secret, and a received count with it.
 *
 * The scenarios of REQ-005 to REQ-007 all carry `@e2e exclude` in the spec
 * (webhook authentication, idempotency, event dispatch, the push and the
 * retention job are covered by PHPUnit). This file covers the form, which no
 * scenario names, and checks the 401 once from outside as a smoke test.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { anonymousRequest } from './support/anonymous.ts'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { appDialog } from './support/dialogs.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const APP_API = '/index.php/apps/integriq/api'
const RUN = `e2e-cti-${Date.now()}`
const SHARED_SECRET = `${RUN}-secret`

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
 * Seed a CTI source on the sandbox binding through the object API.
 *
 * @param request The admin request context.
 * @param slug A slug unique to the calling test.
 * @return The created source's id.
 */
async function seedCtiSource(request: APIRequestContext, slug: string): Promise<string> {
	const resp = await request.post(`${OR_BASE}/source`, {
		failOnStatusCode: false,
		headers: await withRequestToken(request),
		data: {
			name: slug,
			slug,
			description: 'Seeded by tests/e2e/cti-source.spec.ts',
			type: 'cti',
			isEnabled: true,
			location: '',
			configuration: { provider: 'log', sharedSecret: SHARED_SECRET },
		},
	})
	expect(resp.status(), `seeding CTI source ${slug}: ${await resp.text()}`).toBeLessThan(300)
	const id = idOf((await resp.json()) as Record<string, unknown>)
	expect(id).not.toBe('')
	createdSources.push(id)
	return id
}

test.describe('kcc-cti-adapter: the CTI source form', () => {
	test.afterAll(async ({ request }) => {
		const headers = await withRequestToken(request)
		for (const id of createdSources) {
			await request.delete(`${OR_BASE}/source/${id}`, { headers, failOnStatusCode: false })
		}
	})

	test('a new Telephony (CTI) source shows the provider picker and the webhook mapping', async ({ page }) => {
		test.setTimeout(90_000)

		await gotoAppRoute(page, '/sources')
		await expectRouteMatched(page, '/sources')
		await page.getByRole('button', { name: /add source/i }).first().click()
		const dialog = appDialog(page)
		await expect(dialog, 'the create modal must open').toBeVisible({ timeout: 10_000 })

		await dialog.locator('#cn-source-form-type').click()
		await dialog.locator('#cn-source-form-type').fill('Telephony')
		await page.getByRole('option', { name: /^Telephony \(CTI\)$/ }).first().click()

		await expect(dialog.getByRole('heading', { name: 'Telephony settings' })).toBeVisible({ timeout: 10_000 })

		const picker = dialog.locator('#cn-cti-provider')
		await expect(picker).toBeVisible()
		await picker.click()
		await expect(page.getByRole('option', { name: /^Phone system webhook$/ }).first()).toBeVisible()
		await expect(page.getByRole('option', { name: /^Sandbox \(log only\)$/ }).first()).toBeVisible()
		await page.getByRole('option', { name: /^Phone system webhook$/ }).first().click()

		// The webhook binding reads a signature or shared secret, the events path and both mappings.
		for (const label of ['Signature secret', 'Shared secret', 'Events path', 'Call id', 'Caller number', 'Ringing', 'Ended']) {
			await expect(dialog.getByLabel(label, { exact: true }).first(), `the webhook shows ${label}`).toBeVisible()
		}
		await expect(dialog.getByText('No secret is set yet, so every call to this source is refused.')).toBeVisible()
		await expect(dialog.locator('#cn-cti-caller-lookup-source')).toBeVisible()
	})

	test('the events URL refuses a call without the shared secret and takes one with it', async ({ request }) => {
		const id = await seedCtiSource(request, `${RUN}-log`)
		const url = `${APP_API}/cti/${id}/events`
		const event = {
			kind: 'ringing',
			callId: `${RUN}-call-1`,
			callerNumber: '+31612345678',
			agentId: 'e2e-agent',
			at: new Date().toISOString(),
		}

		const outside = await anonymousRequest()
		try {
			const refused = await outside.post(url, { data: event, failOnStatusCode: false })
			expect(refused.status(), 'no X-Api-Key: refused, nothing processed').toBe(401)

			const accepted = await outside.post(url, {
				data: event,
				headers: { 'X-Api-Key': SHARED_SECRET },
				failOnStatusCode: false,
			})
			expect(accepted.status(), `with the shared secret: ${await accepted.text()}`).toBe(200)
			expect(await accepted.json()).toHaveProperty('received')
		} finally {
			await outside.dispose()
		}
	})
})
