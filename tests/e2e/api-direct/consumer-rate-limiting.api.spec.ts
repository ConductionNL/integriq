/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * API-direct HTTP-contract assertions for inbound per-consumer rate limiting
 * (consumer-management REQ-CON-RL-002 / RL-003).
 *
 * These are NOT UI-driving Playwright tests — they assert raw HTTP status
 * codes + RateLimit-* response headers against the integriq endpoint
 * gateway. They are excluded from the gate-19 UI run via the `**​/api-direct/**`
 * testIgnore in playwright.config.ts (API-direct → Newman home).
 *
 * LIVE-RUN STATUS: DEFERRED. Exercising the 429 path end-to-end requires a
 * provisioned instance with (a) a `consumer` object carrying
 * `rateLimit {requestsPerWindow, windowSeconds}` and a JWT `authorizationType`
 * with a registered public key, (b) an endpoint with an `authentication` rule
 * bound to that consumer, and (c) a signed JWT for each call. The enforcement
 * logic itself is fully covered by the unit suite
 * (tests/Unit/Service/RateLimit/InboundRateLimitServiceTest.php — 7 cases:
 * under/over limit, headers, quota, atomic admit-exactly-the-limit, distinct
 * keys, unlimited-when-unconfigured). This file documents the observable HTTP
 * contract the enforcement produces and provisions what it can; the JWT-signing
 * prerequisite is why the live assertion is deferred rather than run here.
 */

import { test, expect } from '@playwright/test'

const API_BASE = '/index.php/apps/integriq/api'

test.describe('Inbound consumer rate limiting — HTTP contract', () => {
	// The gateway dispatch path stays healthy (never 500) regardless of
	// rate-limit configuration — a smoke assertion that is safe on any instance.
	test('endpoint dispatch remains routable (never 500) under rate-limit wiring', async ({
		request,
	}) => {
		const resp = await request.get(
			`${API_BASE}/endpoint/pw-rl-smoke-${Date.now()}`,
			{
				failOnStatusCode: false,
			},
		)
		expect(resp.status()).not.toBe(500)
	})

	// CONTRACT (documented; live-run deferred — see file header):
	// GIVEN a consumer with rateLimit {requestsPerWindow: 2, windowSeconds: 60}
	//   bound to an authenticated endpoint,
	// WHEN it makes 3 rapid authenticated calls in the same window,
	// THEN calls 1 and 2 return their normal status with headers
	//   RateLimit-Limit: 2, RateLimit-Remaining: {1,0}, RateLimit-Reset: <s>,
	// AND call 3 returns HTTP 429 with a Retry-After header and reason "rate_limit".
	// Rate limiting runs strictly AFTER authentication: an unauthenticated caller
	// receives 401/403, never a 429 (REQ-CON-RL-002).
	test.fixme(
		true,
		'live provisioning of a JWT-authenticated rate-limited consumer required — see file header',
	)
})
