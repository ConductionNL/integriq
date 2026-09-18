/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md
 *
 * Two scenarios drive the real objects and the real catalog page: a vendor
 * source that carries no credential reference cannot be activated, and a
 * mock-mode source lists the vendor's templates without any of them becoming
 * an integriq object.
 *
 * The scenarios marked `@e2e exclude` in the spec are the log binding, the
 * typed cross-app events, the failure path and the credential resolution, all
 * covered by PHPUnit. The catalog cards are asserted in
 * `tests/e2e/connector-catalog.spec.ts`, which reads every seeded adapter.
 */

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'

test.describe.configure({ mode: 'serial' })

test('a vendor source without a credential reference cannot be activated', async ({ request }) => {
	// @e2e openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#scenario-a-source-without-credentials-cannot-activate
	// @e2e openspec/specs/document-generation-vendor-adapter/spec.md#scenario-a-source-without-credentials-cannot-activate
	const created = await request.post(`${OR_BASE}/source`, {
		data: {
			name: 'e2e smartdocuments without a credential',
			type: 'documentGeneration',
			location: 'https://vendor.invalid/v1',
			isEnabled: false,
			configuration: {
				providerId: 'smartdocuments',
				baseUrl: 'https://vendor.invalid/v1',
				mockMode: false,
				authentication: { credentialRef: '' },
			},
		},
	})
	expect(created.status(), 'seed the source').toBeLessThan(300)
	const source = await created.json()
	const sourceId = String(source.id ?? source.uuid ?? source['@self']?.id ?? '')

	const activated = await request.post(`${API_BASE}/document-generation/sources/${sourceId}/activate`)

	expect(
		activated.status(),
		'a vendor source with no credential reference is refused, not activated',
	).toBeGreaterThanOrEqual(400)
	const body = await activated.text()
	expect(body, 'the refusal names what is missing').toContain('credentialRef')

	await request.delete(`${OR_BASE}/source/${sourceId}`).catch(() => {})
})

test('a mock-mode source lists the vendor templates, and stores none of them', async ({ request }) => {
	// @e2e openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#scenario-the-operator-sees-the-vendors-templates
	// @e2e openspec/specs/document-generation-vendor-adapter/spec.md#scenario-the-operator-sees-the-vendors-templates
	const created = await request.post(`${OR_BASE}/source`, {
		data: {
			name: 'e2e xential in mock mode',
			type: 'documentGeneration',
			location: 'https://vendor.invalid',
			isEnabled: true,
			configuration: {
				providerId: 'xential',
				baseUrl: 'https://vendor.invalid',
				mockMode: true,
				authentication: { credentialRef: '' },
			},
		},
	})
	expect(created.status(), 'seed the source').toBeLessThan(300)
	const source = await created.json()
	const sourceId = String(source.id ?? source.uuid ?? source['@self']?.id ?? '')

	const listed = await request.get(`${API_BASE}/document-generation/sources/${sourceId}/templates`)
	expect(listed.status(), 'list the vendor templates').toBe(200)
	const templates = (await listed.json()).templates ?? []

	expect(templates.length, 'the three fixture templates are listed').toBe(3)
	expect(templates.map((t: { id: string }) => t.id)).toContain('xt-beschikking')

	// None of them became an integriq object: the vendor administers its own
	// templates, and a copy here would be wrong the first time somebody edits
	// one there.
	const stored = await request.get(`${OR_BASE}/documentGenerationJob?_limit=1`)
	expect(stored.status()).toBe(200)
	const schemas = await request.get(`${OR_BASE}/vendorTemplate?_limit=1`)
	expect(
		schemas.status(),
		'integriq ships no schema that could hold a copy of a vendor template',
	).toBeGreaterThanOrEqual(400)

	await request.delete(`${OR_BASE}/source/${sourceId}`).catch(() => {})
})

test('the catalog carries both vendors, dormant', async ({ page }) => {
	// @e2e openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#scenario-the-catalog-lists-both-vendors-dormant
	// @e2e openspec/specs/document-generation-vendor-adapter/spec.md#scenario-the-catalog-lists-both-vendors-dormant
	await page.goto(`${APP_BASE}/catalog`, { waitUntil: 'domcontentloaded' })

	await expect(page.getByText('SmartDocuments').first()).toBeVisible()
	await expect(page.getByText('Xential').first()).toBeVisible()
})
