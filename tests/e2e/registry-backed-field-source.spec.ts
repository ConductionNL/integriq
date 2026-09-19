/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md
 *
 * The scenarios the spec sends here are the ones a person can see: the
 * type-ahead an applicant uses, what a resolve returns when the registry does
 * not answer, and the resync an administration screen offers. Everything else
 * in that spec carries `@e2e exclude` and is covered by PHPUnit
 * (tests/Unit/PropertySource/).
 *
 * The suggest path runs against the shipped PDOK Locatieserver client, which
 * resolves to its deterministic mock flavour while `pdok.feature_flag` is
 * dormant. That is the production code path, not a stub: only the far side of
 * the connector is canned.
 *
 * The resync route is the one privileged call in this surface. It is probed
 * here with the least privileged principal that reaches it, an anonymous
 * request, which must be refused before anything is read or written.
 */

import { expect, test } from '@playwright/test'

const API_BASE = '/index.php/apps/integriq/api/property-sources'

test.describe('registry-backed property sources', () => {
	// @e2e registry-field-source::describe-says-what-the-provider-keys-on
	test('the shipped bindings say what they key on', async ({ request }) => {
		const resp = await request.get(API_BASE, { failOnStatusCode: false })

		expect(resp.status()).toBe(200)
		const body = await resp.json()
		const ids = (body.results ?? []).map((row: { id: string }) => row.id)
		expect(ids).toContain('bag')
		expect(ids).toContain('brp')
		expect(ids).toContain('kvk')

		const bag = (body.results ?? []).find(
			(row: { id: string }) => row.id === 'bag',
		)
		expect(bag.identifier).toBe('pdokId')
		expect(bag.stalenessBudget).toBeGreaterThan(0)
	})

	// @e2e registry-field-source::an-applicant-types-an-address
	test('an applicant types three characters and gets suggestions', async ({
		request,
	}) => {
		const resp = await request.get(`${API_BASE}/bag/suggest?q=ker`, {
			failOnStatusCode: false,
		})

		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(Array.isArray(body.results)).toBe(true)

		for (const suggestion of body.results) {
			// A suggestion is never an answer. It carries the identifier the
			// caller must resolve, and says so of itself.
			expect(suggestion.identifier).toBeTruthy()
			expect(suggestion.authoritative).toBe(false)
			expect(suggestion.provider).toBe('bag')
		}
	})

	// @e2e registry-field-source::the-brp-is-down-during-a-resolve
	test('a resolve that cannot reach its registry never returns a blank value that reads live', async ({
		request,
	}) => {
		const resp = await request.get(
			`${API_BASE}/brp/resolve?identifier=999993653`,
			{ failOnStatusCode: false },
		)

		// Either the source is configured and answers, or it is not reachable
		// and the response says which. What must never happen is a 200 with an
		// empty value described as live.
		expect([200, 409]).toContain(resp.status())
		const body = await resp.json()

		if (resp.status() === 409) {
			// A binding with no source configured names the source it wanted.
			expect(String(body.error)).toContain('brp')
			return
		}

		expect(body.provenance).toBeTruthy()
		expect(body.provenance.provider).toBe('brp')
		if (body.value === null) {
			expect(body.provenance.unreachable).toBe(true)
			expect(body.provenance.live).toBe(false)
		}
		if (body.provenance.unreachable === true) {
			expect(body.provenance.live).toBe(false)
		}
	})

	// @e2e registry-field-source::an-unknown-provider-fails-loudly
	test('an unknown provider is a 404 that names the id', async ({ request }) => {
		const resp = await request.get(`${API_BASE}/kadaster/resolve?identifier=x`, {
			failOnStatusCode: false,
		})

		expect(resp.status()).toBe(404)
		expect(String((await resp.json()).error)).toContain('kadaster')
	})

	// @e2e registry-field-source::an-administrator-resyncs-the-classification-plan
	test('the resync route refuses a request that carries no session', async ({
		playwright,
	}) => {
		// A fresh context, so the admin storageState from globalSetup cannot
		// leak into this probe. This is the least privileged principal that
		// reaches the route at all.
		const anonymous = await playwright.request.newContext()
		try {
			const resp = await anonymous.post(`${API_BASE}/classificatie/resync`, {
				failOnStatusCode: false,
			})

			expect(resp.status()).not.toBe(200)
			expect([401, 403, 412]).toContain(resp.status())
		} finally {
			await anonymous.dispose()
		}
	})
})
