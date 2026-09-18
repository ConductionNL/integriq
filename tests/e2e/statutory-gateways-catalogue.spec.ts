/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md
 *
 * Three questions a running instance has to answer out loud: which laws it
 * reaches, what each gateway claims and on what evidence, and where data
 * leaves to. The rest of that spec carries `@e2e exclude`, because a statutory
 * route has no test instance, and is covered by PHPUnit.
 */

import { expect, test } from '@playwright/test'

const API_BASE = '/index.php/apps/integriq/api/gateways'

test.describe('statutory gateway catalogue', () => {
	// @e2e statutory-gateways::the-catalogue-answers-which-laws-the-instance-reaches
	test('every gateway shows its standard, its claim level and its evidence', async ({
		request,
	}) => {
		const resp = await request.get(API_BASE, { failOnStatusCode: false })
		expect(resp.status()).toBe(200)

		const body = await resp.json()
		expect(body.standards).toContain('Wmebv')
		expect(body.results.length).toBeGreaterThan(0)

		for (const row of body.results) {
			expect(row.standard, 'a gateway with no standard cannot register').toBeTruthy()
			expect(['conformant', 'partial', 'planned']).toContain(row.claim.level)
			expect(row.claim.evidence).toBeTruthy()
		}
	})

	// @e2e statutory-gateways::the-catalogue-answers-which-laws-the-instance-reaches
	test('the catalogue filters by standard', async ({ request }) => {
		const resp = await request.get(`${API_BASE}?standard=Wmebv`, {
			failOnStatusCode: false,
		})

		expect(resp.status()).toBe(200)
		const rows = (await resp.json()).results
		expect(rows.length).toBeGreaterThan(0)
		for (const row of rows) {
			expect(row.standard).toBe('Wmebv')
		}
	})

	// @e2e statutory-gateways::a-claim-is-not-a-certificate
	test('a claim reads as a claim and never as a certification', async ({
		request,
	}) => {
		const rows = (await (await request.get(API_BASE)).json()).results

		for (const row of rows) {
			expect(row.claim.certified).toBe(false)
			expect(String(row.claim.wording)).toContain('Self-declared claim')
			expect(String(row.claim.wording)).toContain('not a certification')
		}
	})

	// @e2e statutory-gateways::a-route-states-what-it-meets-and-what-it-hands-on
	test('an inbound route states what it meets and what it hands to its consumer', async ({
		request,
	}) => {
		const rows = (await (await request.get(API_BASE)).json()).results
		const berichtenbox = rows.find(
			(row: { id: string }) => row.id === 'berichtenbox',
		)

		expect(berichtenbox).toBeTruthy()
		expect(berichtenbox.wmebv.met.length).toBeGreaterThan(0)
		expect(berichtenbox.wmebv.handedToConsumer.length).toBeGreaterThan(0)
		// A handed obligation names the duty, so a consumer can be asked for it
		// rather than assumed to have done it.
		expect(berichtenbox.wmebv.handedToConsumer[0].consumerDuty).toBeTruthy()
	})

	// @e2e statutory-gateways::an-administrator-reads-where-data-goes
	test('the overview shows every gateway with its jurisdiction, and exports', async ({
		request,
	}) => {
		const resp = await request.get(`${API_BASE}/overview`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)

		const rows = (await resp.json()).results
		expect(rows.length).toBeGreaterThan(0)
		for (const row of rows) {
			expect(row.jurisdiction).toBeTruthy()
			if (row.jurisdictionDeclared === false) {
				// An undeclared jurisdiction reads unknown. It is never quietly
				// shown as local.
				expect(row.jurisdiction).toBe('unknown')
			}
		}

		const exported = await request.get(`${API_BASE}/overview/export`, {
			failOnStatusCode: false,
		})
		expect(exported.status()).toBe(200)
		expect(await exported.text()).toContain('jurisdiction')
	})
})
