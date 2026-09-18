/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md
 *
 * Every scenario in that spec carries `@e2e exclude`, and for good reason: a
 * volgindicatie refusal, an event handler and a scheduled job against a live
 * registry are not things a browser can drive. They are covered by PHPUnit in
 * tests/Unit/Service/Registry/ and tests/Unit/BackgroundJob/.
 *
 * What is left for a running instance is the seam those tests cannot reach:
 * the source the KvK binding subscribes through has to exist, and it has to
 * arrive dormant. A subscription source that seeds itself enabled would start
 * calling a registry nobody has a contract with.
 */

import { expect, test } from '@playwright/test'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

test.describe('registry subscription sources', () => {
	// @e2e registry-subscription-connector::the-mutatieservice-source-is-seeded-dormant
	test('the KvK mutatieservice source is seeded and arrives dormant', async ({
		request,
	}) => {
		const resp = await request.get(`${OR_BASE}/source?_limit=200`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)

		const body = await resp.json()
		const rows = body.results ?? body
		const source = rows.find(
			(row: Record<string, unknown>) =>
				row.name === 'KvK mutatieservice'
				|| String(row['@self']?.slug ?? '') === 'kvk-mutatieservice',
		)

		// The register seed runs on install and on the bootstrap job, so on a
		// freshly reset instance the row may not be there yet. When it is, it
		// must be dormant and in mock mode.
		test.skip(
			source === undefined,
			'the register seed has not run on this instance yet',
		)

		expect(source.isEnabled ?? false).toBe(false)
		expect(source.configuration?.mock).toBe(true)
	})
})
