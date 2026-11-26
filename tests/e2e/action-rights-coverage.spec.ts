/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md
 *
 * The action authorization screen lists every enforced action under its area,
 * a delegated adapter push lets a member through and refuses a non-member,
 * and an object right on the mapping schema lets a team edit mappings while
 * sources stay admin-only.
 *
 * The scenarios carrying `@e2e exclude` (the unseeded-action build guard, the
 * before-and-after posture comparison, the upgrade path) are covered by
 * PHPUnit ActionSeedCoverageTest, the ObjectRightsPostureTest integration test
 * and ApplyObjectRightsTest.
 *
 * Base URL from the Playwright config (NEXTCLOUD_URL / BASE_URL); the admin
 * from NC_ADMIN_USER / NC_ADMIN_PASS. The member and the non-member each get
 * their own context with an empty storage state.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'

const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'
const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'
const MATRIX_URL = '/index.php/apps/integriq/api/admin/action-matrix'
const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const GROUP = 'integratie-beheer'
const RUN = Date.now().toString(36)
const MEMBER = { username: `arc-member-${RUN}`, password: `Arc-member-${RUN}-pw!` }
const OUTSIDER = { username: `arc-outsider-${RUN}`, password: `Arc-outsider-${RUN}-pw!` }

let originalMatrix: Record<string, string[]> = {}
const createdObjects: Array<{ schema: string, id: string }> = []

/**
 * A request context signed in as one user, with an empty storage state.
 *
 * @param baseURL The instance.
 * @param user The user.
 * @param user.username The user name.
 * @param user.password The password.
 * @return The context.
 */
async function as(baseURL: string, user: { username: string, password: string }): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL,
		storageState: { cookies: [], origins: [] },
		// `send: 'always'`: Nextcloud answers an unauthenticated OCS call 401 without a
		// Basic challenge, so credentials sent only on a challenge are never sent.
		httpCredentials: { ...user, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
}

/**
 * Create one OpenRegister object as the admin and remember it for cleanup.
 *
 * @param admin The admin context.
 * @param schema The schema slug.
 * @param data The object.
 * @return Its id.
 */
async function createObject(admin: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const resp = await admin.post(`${OR_BASE}/${schema}`, { data })
	expect(resp.status(), `the admin creates a ${schema}`).toBeLessThan(300)
	const body = await resp.json()
	const id = String(body.id ?? body['@self']?.id ?? body.uuid ?? '')
	expect(id, `the created ${schema} has an id`).not.toBe('')
	createdObjects.push({ schema, id })
	return id
}

/**
 * Store a matrix with some rows changed.
 *
 * @param admin The admin context.
 * @param rows Action to groups.
 */
async function grant(admin: APIRequestContext, rows: Record<string, string[]>): Promise<void> {
	const current = await (await admin.get(MATRIX_URL)).json()
	const resp = await admin.put(MATRIX_URL, { data: { matrix: { ...current.matrix, ...rows } } })
	expect(resp.status()).toBe(200)
	expect((await resp.json()).notApplied ?? []).toEqual([])
}

test.describe('action rights coverage', () => {
	test.describe.configure({ mode: 'serial' })

	let admin: APIRequestContext

	test.beforeAll(async ({ baseURL }) => {
		admin = await as(baseURL as string, { username: ADMIN_USER, password: ADMIN_PASS })
		originalMatrix = (await (await admin.get(MATRIX_URL)).json()).matrix ?? {}

		await admin.post('/ocs/v2.php/cloud/groups', { form: { groupid: GROUP } })
		for (const user of [MEMBER, OUTSIDER]) {
			const resp = await admin.post('/ocs/v2.php/cloud/users', { form: { userid: user.username, password: user.password } })
			expect(resp.status(), `user ${user.username} is created`).toBe(200)
		}
		const added = await admin.post(`/ocs/v2.php/cloud/users/${MEMBER.username}/groups`, { form: { groupid: GROUP } })
		expect(added.status()).toBe(200)
	})

	test.afterAll(async () => {
		await admin.put(MATRIX_URL, { data: { matrix: originalMatrix } })
		for (const { schema, id } of createdObjects) {
			await admin.delete(`${OR_BASE}/${schema}/${id}`)
		}
		for (const user of [MEMBER, OUTSIDER]) {
			await admin.delete(`/ocs/v2.php/cloud/users/${user.username}`)
		}
		await admin.dispose()
	})

	test('an adapter action is listed under its area with a label', async ({ page }) => {
		const answer = await (await admin.get(MATRIX_URL)).json()
		expect(answer.actions).toContain('sms.send')
		const area = answer.areas['sms.send']
		expect(area).toBeTruthy()

		await page.goto(ADMIN_SETTINGS_URL)
		const section = page.getByTestId(`admin-action-area-${area}`)
		const row = section.locator('tr', { has: page.locator('code', { hasText: /^sms\.send$/ }) })
		await expect(row).toBeVisible()
		await expect(row.getByText(answer.labels['sms.send'], { exact: true })).toBeVisible()
	})

	test('a group ticked for stuf-zkn.push lets a member push and refuses a non-member', async ({ page, baseURL }) => {
		await page.goto(ADMIN_SETTINGS_URL)
		const answer = await (await admin.get(MATRIX_URL)).json()
		const section = page.getByTestId(`admin-action-area-${answer.areas['stuf-zkn.push']}`)
		const box = section.getByRole('checkbox', { name: `Allow group ${GROUP} to perform stuf-zkn.push` })
		await expect(box).toBeVisible()
		if (!(await box.isChecked())) {
			await box.check({ force: true })
		}
		const saved = page.waitForResponse((resp) => resp.url().includes('/api/admin/action-matrix') && resp.request().method() === 'PUT')
		await page.getByTestId('admin-action-matrix-save').click()
		expect((await saved).status()).toBe(200)

		const stored = await (await admin.get(MATRIX_URL)).json()
		expect(stored.matrix['stuf-zkn.push']).toContain(GROUP)

		// An empty body passes the right check and is then refused as incomplete (400).
		const member = await as(baseURL as string, MEMBER)
		const outsider = await as(baseURL as string, OUTSIDER)
		const memberPush = await member.post('/index.php/apps/integriq/api/stuf-zkn/kennisgevingen', { data: {} })
		const outsiderPush = await outsider.post('/index.php/apps/integriq/api/stuf-zkn/kennisgevingen', { data: {} })
		expect(memberPush.status()).not.toBe(403)
		expect(outsiderPush.status()).toBe(403)
		await member.dispose()
		await outsider.dispose()
	})

	test('a team edits mappings but not sources', async ({ page, baseURL }) => {
		const mapping = await createObject(admin, 'mapping', { name: `arc-mapping-${RUN}`, mapping: {} })
		const source = await createObject(admin, 'source', { name: `arc-source-${RUN}`, location: 'https://example.invalid', type: 'api' })

		await grant(admin, { 'object.mapping.update': ['admin', GROUP], 'object.source.update': ['admin'] })

		await page.goto(ADMIN_SETTINGS_URL)
		const objects = page.getByTestId('admin-action-area-Configuration objects')
		await expect(objects.getByRole('columnheader', { name: 'Every signed-in user' })).toBeVisible()
		await expect(objects.getByRole('checkbox', { name: `Allow group ${GROUP} to perform object.mapping.update` })).toBeChecked()

		const member = await as(baseURL as string, MEMBER)
		const outsider = await as(baseURL as string, OUTSIDER)

		const mappingEdit = await member.patch(`${OR_BASE}/mapping/${mapping}`, { data: { description: 'edited by the team' } })
		expect(mappingEdit.status(), 'the member edits the mapping').toBeLessThan(300)

		const sourceEdit = await member.patch(`${OR_BASE}/source/${source}`, { data: { description: 'edited by the team' } })
		expect([403, 404], 'the member may not edit the source').toContain(sourceEdit.status())

		const outsiderEdit = await outsider.patch(`${OR_BASE}/mapping/${mapping}`, { data: { description: 'edited by an outsider' } })
		expect([403, 404], 'a non-member may no longer edit mappings').toContain(outsiderEdit.status())

		await member.dispose()
		await outsider.dispose()
	})
})
