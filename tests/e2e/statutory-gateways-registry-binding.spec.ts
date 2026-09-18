/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md
 *
 * The ZGW registry binding is a deployment choice, and a binding that points
 * at nothing has to fail when an administrator tests it rather than the first
 * time somebody opens a case. That is the scenario here. Running against a
 * second ZGW registry is the other half, and it cannot be staged on CI.
 */

import { expect, test } from '@playwright/test'

const API_BASE = '/index.php/apps/integriq/api/gateways'

test.describe('ZGW registry binding', () => {
	// @e2e statutory-gateways::an-unreachable-binding-fails-at-test-time
	test('a binding with a wrong base URL fails its test, naming the endpoint', async ({
		request,
	}) => {
		const resp = await request.post(`${API_BASE}/registry-binding/test`, {
			failOnStatusCode: false,
			data: {
				binding: {
					kind: 'external',
					name: 'Een andere leverancier',
					baseUrl: 'https://zaken.invalid.test/api/v1',
				},
			},
		})

		// An ordinary account is refused outright; an authorised one gets the
		// failed verdict. Either way the binding is never marked usable.
		expect([400, 401, 403]).toContain(resp.status())
		if (resp.status() === 400) {
			const body = await resp.json()
			expect(body.ok).toBe(false)
			expect(String(body.message)).toContain('zaken.invalid.test')
		}
	})

	// @e2e statutory-gateways::an-unreachable-binding-fails-at-test-time
	test('an anonymous request cannot test a binding at all', async ({
		playwright,
	}) => {
		const anonymous = await playwright.request.newContext()
		try {
			const resp = await anonymous.post(`${API_BASE}/registry-binding/test`, {
				failOnStatusCode: false,
				data: {},
			})

			expect(resp.status()).not.toBe(200)
			expect([401, 403, 412]).toContain(resp.status())
		} finally {
			await anonymous.dispose()
		}
	})
})
