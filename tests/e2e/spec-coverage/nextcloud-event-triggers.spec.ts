/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md
 *   (verified by openspec/changes/nextcloud-event-hub-verification, tasks 2 and 3)
 *
 * Task 2, the family grant and self-service subscribe. An administrator grants
 * `event.subscribe` and `event.subscribe-nextcloud-files` to a group in the
 * ADR-023 action matrix. A member of that group then subscribes to a Files
 * event on their own, and is refused (403) for a family nobody granted
 * (Calendar). The matrix is put back as it was afterwards.
 *
 * Task 3, the action-type picker in the subscription modal. On the Webhooks
 * page the "Add Webhook" modal offers the delivery actions. Picking
 * synchronization, job or flow shows the matching target picker, and webhook
 * shows none. A subscription saved with a flow action carries
 * `action: {kind: "flow", flowId}`; that half is checked through the API, so it
 * holds whether or not the instance has a flow to pick.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL } from '../support/baseUrl.ts'
import { appDialog } from '../support/dialogs.ts'
import { APP_BASE, navTo } from './_helpers.ts'

const MATRIX = `${APP_BASE}/api/admin/action-matrix`
const SUBSCRIPTIONS = `${APP_BASE}/api/events/subscriptions`
const OBJECTS = '/index.php/apps/openregister/api/objects/integriq/event_subscription'
const OCS = { 'OCS-APIRequest': 'true' }
const GROUP = 'e2e-event-subscribers'
const MEMBER = 'e2e-event-member'
const PASSWORD = process.env.INTEGRIQ_E2E_EVENT_PASS ?? 'Event-Member-e2e-1!'
const SINK = 'https://example.org/e2e-nextcloud-events'

/** The matrix as it was before this file changed it. */
let savedMatrix: Record<string, unknown> | null = null

/** Subscription ids this file created, removed again in afterAll. */
const created: string[] = []

/**
 * The id of a created subscription.
 *
 * @param body The subscribe answer.
 * @return The id.
 */
function idOf(body: Record<string, unknown>): string {
	return String(body.uuid ?? body.id ?? (body['@self'] as Record<string, string> | undefined)?.id ?? '')
}

/**
 * A request context for the group member: no cookies from the project config, its own login.
 *
 * @param playwright The `playwright` fixture.
 * @return A context the caller disposes.
 */
async function asMember(playwright: any): Promise<APIRequestContext> {
	return playwright.request.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: MEMBER, password: PASSWORD, send: 'always' },
		extraHTTPHeaders: OCS,
	})
}

test.describe('nextcloud-event-triggers: family grant and self-service subscribe', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		await request.post('/index.php/ocs/v2.php/cloud/groups?format=json', { failOnStatusCode: false, headers: OCS, form: { groupid: GROUP } })
		await request.post('/index.php/ocs/v2.php/cloud/users?format=json', { failOnStatusCode: false, headers: OCS, form: { userid: MEMBER, password: PASSWORD } })
		await request.post(`/index.php/ocs/v2.php/cloud/users/${MEMBER}/groups?format=json`, { failOnStatusCode: false, headers: OCS, form: { groupid: GROUP } })

		const current = await request.get(MATRIX, { failOnStatusCode: false })
		expect(current.status(), 'an administrator reads the action matrix').toBe(200)
		savedMatrix = ((await current.json()).matrix ?? {}) as Record<string, unknown>
	})

	test.afterAll(async ({ request }) => {
		if (savedMatrix !== null) {
			await request.put(MATRIX, { failOnStatusCode: false, data: { matrix: savedMatrix } })
		}
		for (const id of created) {
			await request.delete(`${OBJECTS}/${id}`, { failOnStatusCode: false })
		}
	})

	// @e2e nextcloud-event-triggers::a-non-admin-succeeds-once-their-group-is-granted-the-family-action
	test('a member of a group granted the Files family subscribes to a Files event', async ({ request, playwright }) => {
		const matrix: Record<string, unknown> = { ...(savedMatrix ?? {}) }
		for (const action of ['event.subscribe', 'event.subscribe-nextcloud-files']) {
			const groups = Array.isArray(matrix[action]) ? (matrix[action] as string[]) : ['admin']
			matrix[action] = Array.from(new Set([...groups, GROUP]))
		}
		const granted = await request.put(MATRIX, { failOnStatusCode: false, data: { matrix } })
		expect(granted.status(), 'the administrator grants the Files family to the group').toBe(200)

		const member = await asMember(playwright)
		try {
			const subscribed = await member.post(SUBSCRIPTIONS, {
				failOnStatusCode: false,
				data: { style: 'push', sink: SINK, types: ['com.nextcloud.files.node.created'] },
			})
			expect(subscribed.status(), `a granted member subscribes: ${await subscribed.text()}`).toBe(200)
			created.push(idOf(await subscribed.json()))
		} finally {
			await member.dispose()
		}
	})

	// @e2e nextcloud-event-triggers::a-non-admin-is-rejected-for-a-family-not-granted-to-their-groups
	test('the same member is refused for a family nobody granted', async ({ playwright }) => {
		const member = await asMember(playwright)
		try {
			const refused = await member.post(SUBSCRIPTIONS, {
				failOnStatusCode: false,
				data: { style: 'push', sink: SINK, types: ['com.nextcloud.calendar.object.created'] },
			})
			expect(refused.status(), 'the Calendar family was not granted').toBe(403)

			const mixed = await member.post(SUBSCRIPTIONS, {
				failOnStatusCode: false,
				data: { style: 'push', sink: SINK, types: ['com.nextcloud.files.node.created', 'com.nextcloud.calendar.object.created'] },
			})
			expect(mixed.status(), 'one ungranted family in types refuses the whole subscription').toBe(403)
		} finally {
			await member.dispose()
		}
	})
})

test.describe('nextcloud-event-triggers: the action-type picker', () => {
	test.afterAll(async ({ request }) => {
		for (const id of created) {
			await request.delete(`${OBJECTS}/${id}`, { failOnStatusCode: false })
		}
	})

	// Task 3's first criterion; the spec has no scenario of its own for it.
	test('picking synchronization, job or flow shows its target picker, and webhook shows none', async ({ page }) => {
		test.setTimeout(90_000)
		await navTo(page, 'Webhooks', '/webhooks')
		await page.getByRole('button', { name: /Add Webhook/i }).first().click()
		const dialog = appDialog(page)
		await expect(dialog, 'the create modal must open').toBeVisible({ timeout: 10_000 })

		const kind = dialog.locator('#cn-subscription-action-kind')
		await expect(kind).toBeVisible({ timeout: 10_000 })
		for (const name of ['Synchronization', 'Job', 'Flow']) {
			await kind.click()
			await page.getByRole('option', { name: new RegExp(`^${name}$`) }).first().click()
			await expect(dialog.locator('#cn-subscription-action-target'), `${name} shows its target picker`).toBeVisible()
		}

		await kind.click()
		await page.getByRole('option', { name: /^Webhook$/ }).first().click()
		await expect(dialog.locator('#cn-subscription-action-target'), 'a webhook posts to the sink and needs no target').toHaveCount(0)
	})

	// @e2e nextcloud-event-triggers::choosing-flow-persists-the-target
	test('a subscription saved with a flow action carries the flow id', async ({ request }) => {
		const flowId = 'e2e-flow-0000-0000-0000-000000000001'
		const subscribed = await request.post(SUBSCRIPTIONS, {
			failOnStatusCode: false,
			data: { style: 'push', sink: SINK, types: ['com.nextcloud.files.node.created'], action: { kind: 'flow', flowId } },
		})
		expect(subscribed.status(), await subscribed.text()).toBe(200)
		const id = idOf(await subscribed.json())
		created.push(id)

		const stored = await request.get(`${OBJECTS}/${id}`, { failOnStatusCode: false })
		expect(stored.status()).toBe(200)
		expect((await stored.json()).action).toEqual({ kind: 'flow', flowId })
	})
})
