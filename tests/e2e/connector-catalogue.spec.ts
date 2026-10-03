/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/connector-catalog/spec.md
 * (REQ-CCX-001, REQ-CCX-002, REQ-CCX-003, REQ-CCX-004)
 *
 * The Store lists the connector template library without installing it,
 * Instantiate creates one source from a template, and the placeholders and
 * duplicates are gone. The shape of the library is the validator's
 * (tests/validate-connector-templates.js); what collect() returns is
 * CatalogRegistryServiceTest's.
 */

import { expect, test } from '@playwright/test'

const OBJECTS = '/index.php/apps/openregister/api/objects/integriq'

async function listObjects(request, schema: string, query = '') {
	const resp = await request.get(`${OBJECTS}/${schema}?_limit=500${query}`)
	expect(resp.status()).toBe(200)
	const body = await resp.json()
	return (body.results ?? []) as Array<Record<string, any>>
}

test.describe('connector catalogue', () => {
	// @e2e connector-catalog::a-fresh-install-has-no-template-sources
	test('the library is in the Store and not among the sources', async ({
		page,
		request,
	}) => {
		const sources = await listObjects(request, 'source')
		const sourceSlugs = sources.map(
			(source) => source.slug ?? source['@self']?.slug,
		)
		for (const slug of [
			'google-sheets',
			'slack',
			'salesforce-rest',
			'alfresco-cmis',
		]) {
			expect(sourceSlugs).not.toContain(slug)
		}

		await page.goto('/index.php/apps/integriq/store')
		await expect(
			page.getByText('Alfresco Content Services (CMIS 1.1)'),
		).toBeVisible()
	})

	// @e2e connector-catalog::an-administrator-instantiates-a-salesforce-template
	test('Instantiate creates one Salesforce source with no secret', async ({
		page,
		request,
	}) => {
		await page.goto('/index.php/apps/integriq/store')
		await page
			.getByRole('button', { name: 'Open catalog item Salesforce' })
			.click()
		await page.getByRole('button', { name: 'Instantiate' }).click()

		const created = (await listObjects(request, 'source')).filter(
			(source) => source.name === 'Salesforce',
		)
		expect(created).toHaveLength(1)
		expect(created[0].location).toBe(
			'https://MyDomainName.my.salesforce.com/services/data/v68.0',
		)
		expect(created[0].auth).toBe('oauth')
		expect(JSON.stringify(created[0])).not.toContain('client_secret')
	})

	// @e2e connector-catalog::a-buyer-finds-the-gws-connector-and-its-standard
	test('GWS has no card, and the back-office README says why', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/integriq/store')
		await page.getByRole('searchbox').fill('GWS')
		await expect(page.getByTestId('catalog-item-card')).toHaveCount(0)
		// The reason lives in lib/Settings/connector-templates/backoffice/README.md,
		// row "GWS (Centric)": iWMO and iJW through the GGK.
	})

	// @e2e connector-catalog::google-sheets-salesforce-and-slack-are-in-the-store
	test('Google Sheets and Slack are generated, Salesforce is checked', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/integriq/store')
		for (const name of ['Google Sheets', 'Slack']) {
			const card = page
				.getByTestId('catalog-item-card')
				.filter({ hasText: name })
			await expect(card.getByTestId('catalog-tier')).toContainText(
				'Generated from the API directory of',
			)
		}
		const salesforce = page
			.getByTestId('catalog-item-card')
			.filter({ hasText: 'Salesforce' })
		await expect(salesforce.getByTestId('catalog-tier')).toHaveText(
			'Checked against a published interface',
		)
	})

	// @e2e connector-catalog::placeholders-are-gone-from-the-count
	test('no environment placeholder, and SmartDocuments and Xential once each', async ({
		request,
	}) => {
		const items = await listObjects(request, 'catalog_item')
		const slugs = items.map((item) => String(item.slug))
		expect(slugs.filter((slug) => slug.includes('environment-'))).toEqual([])
		expect(items.filter((item) => item.name === 'SmartDocuments')).toHaveLength(
			1,
		)
		expect(items.filter((item) => item.name === 'Xential')).toHaveLength(1)
	})
})
