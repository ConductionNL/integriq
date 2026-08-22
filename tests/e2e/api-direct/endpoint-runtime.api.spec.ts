/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * API-direct HTTP-contract assertions for the endpoint-runtime surface.
 *
 * These are NOT UI-driving Playwright tests — they assert raw HTTP status
 * codes against the openconnector / OpenRegister REST surfaces. The canonical
 * home for API/contract assertions is the Newman suite
 * (tests/postman/openconnector.postman_collection.json); this file is excluded
 * from the gate-19 UI run via the `**​/api-direct/**` testIgnore in
 * playwright.config.ts (gate-19: API-direct → Newman).
 *
 * The UI scenarios for endpoint-runtime (list/modal/detail/logs) carry @e2e
 * tags and remain in tests/e2e/spec-coverage/endpoint-runtime.spec.ts.
 *
 * APP STATE: the endpoint gateway dispatch path returns a clean 404 on a
 * no-match path. The former 500 (the post-OR-cutover SynchronizationService
 * injected the removed OCA\OpenConnector\Db\SynchronizationMapper, so the
 * service — and any controller depending on it — could not be constructed) is
 * fixed: synchronizations now resolve through OpenRegister.
 */

import { test, expect } from '@playwright/test'

const API_BASE = '/index.php/apps/integriq/api'
const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'

test.describe('Endpoint dispatch HTTP surface — no-match', () => {
	test('GET /api/endpoint/{path} with no matching endpoint is routable (not a 2xx)', async ({
		request,
	}) => {
		const resp = await request.get(
			`${API_BASE}/endpoint/pw-e2e-no-match-${Date.now()}`,
			{
				failOnStatusCode: false,
			},
		)
		// The dispatch pipeline is healthy: a no-match returns a clean 404.
		expect(resp.status()).not.toBe(500)
		expect([404]).toContain(resp.status())
	})

	test('PUT /api/endpoint/{path} with no matching endpoint is routable (not a 2xx)', async ({
		request,
	}) => {
		const resp = await request.put(
			`${API_BASE}/endpoint/pw-e2e-no-match-put-${Date.now()}`,
			{
				data: {},
				failOnStatusCode: false,
			},
		)
		expect(resp.status()).not.toBe(500)
		expect([404]).toContain(resp.status())
	})
})

test.describe('Endpoints OR API — list', () => {
	test('OR returns endpoint objects for the openconnector register', async ({
		request,
	}) => {
		const resp = await request.get(`${OR_BASE}/endpoint?_limit=10`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})
})
