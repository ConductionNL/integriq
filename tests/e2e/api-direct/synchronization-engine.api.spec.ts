/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * API-direct HTTP-contract assertions for the synchronization-engine surface.
 *
 * NOT UI-driving Playwright tests — raw HTTP status assertions covered
 * canonically by the Newman suite
 * (tests/postman/openconnector.postman_collection.json). Excluded from the
 * gate-19 UI run via the `**​/api-direct/**` testIgnore (gate-19: API-direct →
 * Newman). The UI scenarios for synchronization-engine carry @e2e tags and
 * remain in tests/e2e/spec-coverage/synchronization-engine.spec.ts.
 *
 * APP STATE: POST run on a non-existent sync UUID returns a clean 404. The
 * former 500 (caused by the removed OCA\OpenConnector\Db\SynchronizationMapper
 * still being injected into SynchronizationService) is fixed: the service now
 * resolves synchronizations through OpenRegister (register `openconnector`,
 * schema `synchronization`).
 */

import { test, expect } from '@playwright/test'

const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'
const API_BASE = '/index.php/apps/openconnector/api'

test.describe('Synchronizations OR API — list', () => {
	test('GET synchronizations list from OR returns synchronization objects', async ({
		request,
	}) => {
		const resp = await request.get(`${OR_BASE}/synchronization?_limit=20`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})

	test('POST run on a non-existent sync UUID returns a clean 404 (no 500)', async ({
		request,
	}) => {
		const resp = await request.post(
			`${API_BASE}/synchronizations/00000000-0000-0000-0000-000000000000/run`,
			{ failOnStatusCode: false },
		)
		// The synchronization engine resolves the sync via OpenRegister and the
		// controller maps DoesNotExistException to 404; a server error here would
		// signal the SynchronizationMapper-removal regression has returned.
		expect(resp.status()).not.toBe(500)
		expect([400, 404]).toContain(resp.status())
	})
})
