/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md
 *   (verified by openspec/changes/nextcloud-event-hub-verification, tasks 2 and 3)
 *
 * Task 2, the family grant and self-service subscribe. An administrator grants
 * `event.subscribe` and `event.subscribe-nextcloud-files` to a group by
 * ticking the two cells in the ActionAuthMatrix editor on integriq's admin
 * settings page and saving. A member of that group then subscribes to a Files
 * event on their own, and is refused (403) for a family nobody granted
 * (Calendar). The matrix is put back as it was afterwards. The matrix route
 * is an admin write surface without #[NoCSRFRequired], so every direct call
 * to it carries the session's request token.
 *
 * Task 3, the action-type picker in the subscription modal. On the Webhooks
 * page the "Add Webhook" modal offers the delivery actions. Picking
 * synchronization, job or flow shows the matching target picker, and webhook
 * shows none. A subscription saved with each action kind carries it:
 * `{kind: "synchronization", synchronizationId}`, `{kind: "job", jobId}`,
 * `{kind: "webhook"}` and `{kind: "flow", flowId}`. That half is checked
 * through the API, so it holds whether or not the instance has a
 * synchronization, job or flow to pick.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL } from '../support/baseUrl.ts'
import { appDialog } from '../support/dialogs.ts'
import { withRequestToken } from '../support/requestToken.ts'
import { APP_BASE, navTo } from './_helpers.ts'

const MATRIX = `${APP_BASE}/api/admin/action-matrix`
const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'
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
		await request.post('/ocs/v2.php/cloud/groups?format=json', { failOnStatusCode: false, headers: OCS, form: { groupid: GROUP } })
		await request.post('/ocs/v2.php/cloud/users?format=json', { failOnStatusCode: false, headers: OCS, form: { userid: MEMBER, password: PASSWORD } })
		await request.post(`/ocs/v2.php/cloud/users/${MEMBER}/groups?format=json`, { failOnStatusCode: false, headers: OCS, form: { groupid: GROUP } })

		const current = await request.get(MATRIX, { failOnStatusCode: false, headers: await withRequestToken(request) })
		expect(current.status(), 'an administrator reads the action matrix').toBe(200)
		savedMatrix = ((await current.json()).matrix ?? {}) as Record<string, unknown>
	})

	test.afterAll(async ({ request }) => {
		if (savedMatrix !== null) {
			await request.put(MATRIX, { failOnStatusCode: false, headers: await withRequestToken(request), data: { matrix: savedMatrix } })
		}
		for (const id of created) {
			await request.delete(`${OBJECTS}/${id}`, { failOnStatusCode: false })
		}
	})

	// @e2e nextcloud-event-triggers::a-non-admin-succeeds-once-their-group-is-granted-the-family-action
	test('a member of a group granted the Files family subscribes to a Files event', async ({ page, request, playwright }) => {
		test.setTimeout(90_000)

		// The administrator ticks the two cells in the matrix editor and saves.
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		const section = page.locator('[data-testid=admin-action-auth-section]')
		await expect(section, 'the Action authorization section renders').toBeVisible({ timeout: 20_000 })
		for (const action of ['event.subscribe', 'event.subscribe-nextcloud-files']) {
			// The aria-label is ActionAuthMatrix.vue's "Allow group {group} to perform {action}".
			const cell = section.getByRole('checkbox', { name: `Allow group ${GROUP} to perform ${action}`, exact: true })
			await expect(cell, `the matrix has a ${GROUP} cell for ${action}`).toHaveCount(1, { timeout: 20_000 })
			if (!(await cell.isChecked())) {
				// NcCheckboxRadioSwitch hides its input behind a styled label.
				await cell.check({ force: true })
			}
			await expect(cell).toBeChecked()
		}
		const saved = page.waitForResponse((resp) => resp.url().includes('/api/admin/action-matrix') && resp.request().method() === 'PUT')
		await section.getByTestId('admin-action-matrix-save').click()
		expect((await saved).status(), 'the administrator saves the grant').toBe(200)

		const stored = await request.get(MATRIX, { failOnStatusCode: false, headers: await withRequestToken(request) })
		expect(stored.status()).toBe(200)
		const matrix = ((await stored.json()).matrix ?? {}) as Record<string, string[]>
		for (const action of ['event.subscribe', 'event.subscribe-nextcloud-files']) {
			expect(matrix[action] ?? [], `${action} is granted to ${GROUP}`).toContain(GROUP)
		}
		expect(matrix['event.subscribe-nextcloud-calendar'] ?? [], 'the Calendar family stays ungranted').not.toContain(GROUP)

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
			// NcSelect may split a label into two spans, which breaks an exact accessible name.
			await page.getByRole('option').filter({ hasText: new RegExp(`^\\s*${name}\\s*$`) }).first().click()
			await expect(dialog.locator('#cn-subscription-action-target'), `${name} shows its target picker`).toBeVisible()
		}

		await kind.click()
		await page.getByRole('option').filter({ hasText: /^\s*Webhook\s*$/ }).first().click()
		await expect(dialog.locator('#cn-subscription-action-target'), 'a webhook posts to the sink and needs no target').toHaveCount(0)
	})

	// @e2e nextcloud-event-triggers::choosing-flow-persists-the-target
	// Task 3's second criterion for every kind: the saved subscription carries the chosen action.
	const kinds: Array<[string, Record<string, string>]> = [
		['synchronization', { kind: 'synchronization', synchronizationId: '00000000-0000-4000-8000-00000000e2e1' }],
		['job', { kind: 'job', jobId: '00000000-0000-4000-8000-00000000e2e2' }],
		['webhook', { kind: 'webhook' }],
		['flow', { kind: 'flow', flowId: '00000000-0000-4000-8000-00000000e2e3' }],
	]
	for (const [name, action] of kinds) {
		test(`a subscription saved with a ${name} action carries it`, async ({ request }) => {
			const subscribed = await request.post(SUBSCRIPTIONS, {
				failOnStatusCode: false,
				data: { style: 'push', sink: SINK, types: ['com.nextcloud.files.node.created'], action },
			})
			expect(subscribed.status(), await subscribed.text()).toBe(200)
			const id = idOf(await subscribed.json())
			created.push(id)

			const stored = await request.get(`${OBJECTS}/${id}`, { failOnStatusCode: false })
			expect(stored.status()).toBe(200)
			expect((await stored.json()).action).toEqual(action)
		})
	}
})
