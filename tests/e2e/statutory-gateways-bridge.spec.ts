/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md
 *
 * A bridge reaches a system behind a firewall, and an administrator can take
 * it away again. The call over a live bridge needs a second network and is
 * covered by PHPUnit with a loopback bridge; what a running instance can
 * answer is who may revoke one, and that a bridge listing never hands out the
 * secret a bridge authenticates with.
 */

import { expect, test } from '@playwright/test'

const API_BASE = '/index.php/apps/integriq/api/gateways'

test.describe('on-premise bridges', () => {
	// @e2e statutory-gateways::a-revoked-bridge-stops-answering
	test('an anonymous request cannot revoke a bridge', async ({
		playwright,
	}) => {
		const anonymous = await playwright.request.newContext()
		try {
			const resp = await anonymous.post(
				`${API_BASE}/bridges/pw-e2e-bridge/revoke`,
				{ failOnStatusCode: false },
			)

			expect(resp.status()).not.toBe(200)
			expect([401, 403, 412]).toContain(resp.status())
		} finally {
			await anonymous.dispose()
		}
	})

	// @e2e statutory-gateways::a-revoked-bridge-stops-answering
	test('revoking a bridge nobody registered says so rather than reporting success', async ({
		request,
	}) => {
		const resp = await request.post(
			`${API_BASE}/bridges/pw-e2e-no-such-bridge/revoke`,
			{ failOnStatusCode: false },
		)

		expect(resp.status()).not.toBe(200)
		if (resp.status() === 404) {
			expect(String((await resp.json()).error)).toContain(
				'pw-e2e-no-such-bridge',
			)
		}
	})

	// @e2e statutory-gateways::a-call-reaches-a-system-with-no-public-endpoint
	test('the bridge listing never hands out the secret a bridge authenticates with', async ({
		request,
	}) => {
		const resp = await request.get(`${API_BASE}/bridges`, {
			failOnStatusCode: false,
		})

		test.skip(
			resp.status() !== 200,
			'this account may not administer gateways on this instance',
		)

		for (const row of (await resp.json()).results) {
			expect(row.tokenHash).toBeUndefined()
			expect(row.token).toBeUndefined()
		}
	})
})
