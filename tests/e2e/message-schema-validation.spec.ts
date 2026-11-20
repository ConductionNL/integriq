/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md
 *
 * The message schemas page under Automation: both seeds are listed, a
 * partner's XSD is stored once and listed with its kind and version, and a
 * document that does not parse is refused with the parser's message and not
 * stored. Documents are written through OpenRegister's objects API, the same
 * save path the page's form uses, because the refusal lives on that path
 * (MessageSchemaDocumentListener). The inbound and outbound refusals are
 * covered by Newman (tests/postman, folder 18) and PHPUnit.
 *
 * Base URL and container come from the environment (tests/e2e/playwright.config.ts).
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { navTo } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

const PARTNER_XSD = `<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="https://partner.e2e.invalid/zaak" elementFormDefault="qualified">
  <xs:element name="zaak">
    <xs:complexType>
      <xs:sequence>
        <xs:element name="identificatie" type="xs:string"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
</xs:schema>
`

/**
 * The message schemas whose name is the given one.
 *
 * @param request The Playwright request context.
 * @param name The name.
 * @return The stored rows.
 */
async function messageSchemasNamed(request: APIRequestContext, name: string): Promise<Record<string, unknown>[]> {
	const resp = await request.get(`${OR_BASE}/message_schema`, { headers: HEADERS, params: { name } })
	expect(resp.status()).toBe(200)
	const body = (await resp.json()) as { results?: Record<string, unknown>[] }

	return (body.results ?? []).filter((row) => row.name === name)
}

test.describe('message schema validation', () => {
	test('the page lists both seeded message schemas', async ({ page }) => {
		await navTo(page, 'Message schemas', '/message-schemas')

		await expect(page.getByText('Example person (JSON Schema)').first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText('Example person (XSD)').first()).toBeVisible()
	})

	test("an administrator stores a partner's XSD", async ({ page }) => {
		// e2e: an-administrator-stores-a-partners-xsd
		const name = `E2E partner zaak ${Date.now()}`
		const resp = await page.request.post(`${OR_BASE}/message_schema`, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { name, kind: 'xsd', document: PARTNER_XSD, version: '2.1.0' },
		})
		expect(resp.status(), await resp.text()).toBeLessThan(300)

		await navTo(page, 'Message schemas', '/message-schemas')
		const row = page.getByRole('row').filter({ hasText: name })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row).toContainText('xsd')
		await expect(row).toContainText('2.1.0')
	})

	test('a broken document is refused', async ({ request }) => {
		// e2e: a-broken-document-is-refused
		const name = `E2E broken xsd ${Date.now()}`
		const resp = await request.post(`${OR_BASE}/message_schema`, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { name, kind: 'xsd', document: '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="a">' },
		})

		expect(resp.status()).toBe(400)
		expect(await resp.text(), "the parser's message reaches the caller").not.toBe('')
		expect(await messageSchemasNamed(request, name)).toHaveLength(0)
	})

	test('one bad object does not stop the run', async () => {
		// e2e: one-bad-object-does-not-stop-the-run
		test.skip(
			true,
			'Needs a source page of ten objects that the SERVER can fetch: no programmable stub source is reachable from the server on this runner. Covered by PHPUnit SynchronizationMessageValidationTest (refuse dead-letters 1 of 10 and writes 9) with the real SyncItemDeadLetterService.',
		)
	})
})
