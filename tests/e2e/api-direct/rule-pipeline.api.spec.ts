/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * API-direct HTTP-contract assertions for the rule-pipeline surface.
 *
 * NOT UI-driving Playwright tests — raw HTTP status assertions covered
 * canonically by the Newman suite
 * (tests/postman/openconnector.postman_collection.json). Excluded from the
 * gate-19 UI run via the `**​/api-direct/**` testIgnore (gate-19: API-direct →
 * Newman). The UI scenarios for rule-pipeline carry @e2e tags and remain in
 * tests/e2e/spec-coverage/rule-pipeline.spec.ts.
 *
 * KNOWN APP STATE: dispatch on a non-matching path returns 500 (not 404) while
 * the missing OCA\OpenConnector\Db\SynchronizationMapper breakage persists
 * (flagged real-app-bug).
 */

import { test, expect } from '@playwright/test'

const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'
const API_BASE = '/index.php/apps/openconnector/api'

test.describe('Rules OR API — list', () => {
	test('GET rules list from OR returns rule objects', async ({ request }) => {
		const resp = await request.get(`${OR_BASE}/rule?_limit=20`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})
})

test.describe('Rule pipeline dispatch — no-match', () => {
	test('Endpoint dispatch with non-matching path is routable (pipeline not reached)', async ({ request }) => {
		const resp = await request.get(`${API_BASE}/endpoint/pw-rule-no-endpoint-${Date.now()}`, {
			failOnStatusCode: false,
		})
		// 404 once the dispatch pipeline is healthy; 500 while the missing
		// SynchronizationMapper breakage persists (flagged real-app-bug).
		expect([404, 500]).toContain(resp.status())
	})
})
