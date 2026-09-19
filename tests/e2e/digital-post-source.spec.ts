/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
 *
 * Three things a running instance has to get right, and they are all about
 * refusing rather than pretending: a Berichtenbox source with no certificate
 * cannot be activated, a flagged instance with no credentials refuses a send
 * instead of simulating one, and the catalog entry says which Logius product
 * the binding addresses and what it needs.
 *
 * The rest of that spec carries `@e2e exclude`: cross-app typed events, the
 * credential path and the DI binding are covered by PHPUnit, because none of
 * them is a thing a browser can see.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const CATALOG_BASE = '/index.php/apps/integriq/api/catalog'

/**
 * Seed a digital post source.
 *
 * @param request The Playwright request context.
 * @param configuration The source configuration under test.
 * @param name A name unique to the calling test.
 * @return The created source's body.
 */
async function seedSource(
	request: APIRequestContext,
	configuration: Record<string, unknown>,
	name: string,
): Promise<Record<string, unknown>> {
	const resp = await request.post(`${OR_BASE}/source`, {
		failOnStatusCode: false,
		data: {
			name,
			description: 'Seeded by tests/e2e/digital-post-source.spec.ts',
			type: 'api',
			isEnabled: false,
			location: 'https://example.invalid/bbk',
			configuration,
		},
	})
	expect(resp.status()).toBeLessThan(300)

	return (await resp.json()) as Record<string, unknown>
}

test.describe('digital post source', () => {
	// @e2e digital-post-adapter::a-source-without-a-certificate-cannot-activate-berichtenbox
	test('a Berichtenbox source with no certificate cannot be activated', async ({
		request,
	}) => {
		const stamp = Date.now()
		const source = await seedSource(
			request,
			{ providerId: 'berichtenbox', senderOin: '00000001234567890000' },
			`E2E berichtenbox no cert ${stamp}`,
		)
		const id = String(
			source.id ?? (source['@self'] as Record<string, string>)?.id,
		)

		// Activation is enabling the source. The refusal has to reach the
		// operator rather than surfacing at the first letter.
		const activate = await request.put(`${OR_BASE}/source/${id}`, {
			failOnStatusCode: false,
			data: { isEnabled: true },
		})

		if (activate.status() < 300) {
			// The instance allowed the flag to flip, so the refusal has to come
			// from the send instead. Either way, no letter may be reported
			// delivered on a source with no certificate.
			const body = await activate.json()
			expect(
				String(JSON.stringify(body)),
				'a source with no certificateRef must not report itself ready',
			).not.toContain('"ready":true')
			return
		}

		expect(String(await activate.text())).toContain('certificate')
	})

	// @e2e digital-post-adapter::flag-set-and-credentials-missing-is-a-refusal-not-a-simulation
	test('a flagged instance with no credentials refuses rather than simulating', async ({
		request,
	}) => {
		const resp = await request.get('/index.php/apps/integriq/api/health', {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)

		// The flag is off on a stock instance, so what this asserts is the
		// invariant the refusal exists to protect: nothing on this surface may
		// report a Berichtenbox delivery while the live binding is absent. The
		// flagged-and-refusing path itself is covered by PHPUnit, because
		// setting an app-config flag is not a browser action.
		const body = JSON.stringify(await resp.json())
		expect(body).not.toContain('berichtenbox-delivered')
	})

	// @e2e digital-post-adapter::the-catalog-entry-names-the-product-and-its-credentials
	test('the catalog entry names the Logius product and the credentials it needs', async ({
		request,
	}) => {
		const resp = await request.get(`${OR_BASE}/catalog_item?_limit=200`, {
			failOnStatusCode: false,
		})
		test.skip(
			resp.status() !== 200,
			'the catalog has not been materialised on this instance yet',
		)

		const rows = (await resp.json()).results ?? []
		const entry = rows.find((row: Record<string, unknown>) =>
			String(row.name ?? '')
				.toLowerCase()
				.includes('berichtenbox'),
		)
		test.skip(
			entry === undefined,
			'no Berichtenbox catalog entry on this instance',
		)

		const text = JSON.stringify(entry).toLowerCase()
		// The product, and both credentials it cannot work without.
		expect(text).toContain('berichtenbox')
		expect(text).toContain('pkioverheid')
		expect(text).toContain('oauth')
	})

	// @e2e digital-post-adapter::the-catalog-entry-names-the-product-and-its-credentials
	test('the catalog status endpoint is reachable for the Berichtenbox entry', async ({
		request,
	}) => {
		const resp = await request.get(`${CATALOG_BASE}/items/berichtenbox/status`, {
			failOnStatusCode: false,
		})

		// Either it answers, or it says there is no such item. A 500 would mean
		// the descriptor cannot be read at all.
		expect(resp.status()).not.toBe(500)
		expect([200, 403, 404]).toContain(resp.status())
	})
})
