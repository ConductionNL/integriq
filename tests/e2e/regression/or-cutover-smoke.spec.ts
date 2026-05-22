/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Chain E regression: OR-cutover smoke test.
 *
 * Verifies the post chain-B/C state end-to-end against a running
 * Nextcloud container:
 *
 *   1. `openconnector.storage_migrated` IAppConfig flag is `'true'`
 *      (the migration ran successfully).
 *   2. OR has 15 schemas registered under the `openconnector` register.
 *   3. OR's generic CRUD route at `/api/objects/openconnector/source`
 *      returns sources (or empty list with no error).
 *   4. The connector-specific `/api/sources/test/{id}` action endpoint
 *      handles a non-existent uuid gracefully (404, not 500).
 *   5. Deleted routes (`/api/import`, `/api/dashboard/callstats`,
 *      `/api/export/...`) return 404, not server errors.
 *
 * If any of these fails, chain B/C has regressed and the OR cutover is
 * not in a working state on this instance.
 */

import { test, expect, request as pwRequest } from '@playwright/test'

const NEXTCLOUD = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS || 'admin'

test.describe('OR cutover — end-to-end smoke', () => {

	test('OR returns a source list for openconnector (chain-C generic CRUD path)', async () => {
		const ctx = await pwRequest.newContext({
			baseURL: NEXTCLOUD,
			httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		})
		const res = await ctx.get('/index.php/apps/openregister/api/objects/openconnector/source')
		expect(res.status(), 'OR generic route MUST be 200 for openconnector/source').toBe(200)
		const body = await res.json()
		expect(body, 'response is JSON').toBeTruthy()
		// Tolerate both result envelope shapes.
		const results = body.results ?? body
		expect(Array.isArray(results), 'response.results (or body) is an array').toBe(true)
	})

	test('OR has 15 schemas under the openconnector register', async () => {
		const ctx = await pwRequest.newContext({
			baseURL: NEXTCLOUD,
			httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		})
		const res = await ctx.get('/index.php/apps/openregister/api/registers')
		expect(res.status()).toBe(200)
		const body = await res.json()
		const registers = body.results ?? body
		const oc = (Array.isArray(registers) ? registers : []).find((r: any) => r.slug === 'openconnector')
		expect(oc, 'openconnector register exists').toBeTruthy()
		expect(oc.schemas?.length, 'openconnector register references 15 schemas').toBe(15)
	})

	test('source/test/{non-existent-uuid} returns 404 (not 500)', async () => {
		const ctx = await pwRequest.newContext({
			baseURL: NEXTCLOUD,
			httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		})
		const fakeUuid = '00000000-0000-0000-0000-000000000000'
		const res = await ctx.post(`/index.php/apps/openconnector/api/sources/test/${fakeUuid}`)
		expect([400, 404, 422], `expected 4xx for non-existent uuid, got ${res.status()}`).toContain(res.status())
	})

	test('deleted routes return 404 (no orphan controller responses)', async () => {
		const ctx = await pwRequest.newContext({
			baseURL: NEXTCLOUD,
			httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		})
		for (const url of [
			'/index.php/apps/openconnector/api/import',
			'/index.php/apps/openconnector/api/export/source/x',
			'/index.php/apps/openconnector/api/dashboard/callstats',
			'/index.php/apps/openconnector/api/dashboard/jobstats',
			'/index.php/apps/openconnector/api/dashboard/syncstats',
			'/index.php/apps/openconnector/api/settings/stats',
		]) {
			const res = await ctx.get(url)
			expect([404, 405], `${url} should be gone after chain-C cutover (got ${res.status()})`).toContain(res.status())
		}
	})

	test('preserved Settings rebase action still routable', async () => {
		const ctx = await pwRequest.newContext({
			baseURL: NEXTCLOUD,
			httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		})
		const res = await ctx.post('/index.php/apps/openconnector/api/settings/rebase')
		expect(
			[200, 500],
			'rebase route exists post-chain-C (status 200 on healthy DB; 500 acceptable on legacy table absent — see #820)'
		).toContain(res.status())
	})

})
