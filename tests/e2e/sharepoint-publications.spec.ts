/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage:
 * openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md
 * (REQ-SPPI-001, REQ-SPPI-002, REQ-SPPI-003)
 *
 * The mock Graph is the browser boundary: the setup dialog reads sites,
 * libraries and folders through integriq's own GET /api/sharepoint/lookup, and
 * the brokered credentials through OpenRegister's credential list, so both are
 * answered here with canned Graph-shaped bodies. The save is NOT mocked: it
 * goes to POST /api/sharepoint/publication-intake and the objects it imports
 * are read back from OpenRegister.
 *
 * The dossier scenario runs the shipped demo mapping (the canned folder of
 * lib/Settings/integriq_mock_register.json) through the production mapping
 * test endpoint. The cron-context and public-visibility scenarios carry
 * `@e2e exclude` in the spec and are PHPUnit's.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { searchStore } from './support/store.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const MAPPING_TEST = '/index.php/apps/integriq/api/mappings/test'
const LOOKUP = '**/apps/integriq/api/sharepoint/lookup**'
const CREDENTIALS = '**/apps/openregister/api/credentials**'

const REPO = path.resolve(__dirname, '..', '..')

/** A brokered credential as OpenRegister's broker lists it; the secret never leaves the broker. */
const CREDENTIAL = {
	id: '5d3a0000-0000-4000-8000-0000000000e2',
	name: 'E2E Graph app registration',
	provider: 'microsoft-graph',
}

/** Canned Graph answers per lookup kind, in the shape SharePointOnlineAdapter::lookup() returns. */
const GRAPH = {
	sites: {
		items: [
			{ id: 'example.sharepoint.com,site-woo,web-woo', name: 'Woo verzoeken', webUrl: 'https://example.sharepoint.com/sites/woo-verzoeken' },
		],
	},
	drives: {
		items: [{ id: 'b!woo-dossiers', name: 'Dossiers', webUrl: 'https://example.sharepoint.com/sites/woo-verzoeken/Dossiers' }],
	},
	children: {
		items: [
			{ id: '01GEREED', name: 'Gereed', isFolder: true },
			{ id: '01README', name: 'Lees mij.docx', isFolder: false },
		],
	},
}

/**
 * The objects of one integriq schema.
 *
 * @param request The Playwright request context.
 * @param schema The schema slug.
 * @return The objects.
 */
async function listObjects(request: APIRequestContext, schema: string): Promise<Array<Record<string, any>>> {
	const resp = await request.get(`${OR_BASE}/${schema}?_limit=500`)
	expect(resp.status()).toBe(200)
	const body = await resp.json()

	return (body.results ?? []) as Array<Record<string, any>>
}

/**
 * The OpenRegister id of an object.
 *
 * @param object The object.
 * @return Its id.
 */
function idOf(object: Record<string, any>): string {
	return String(object.id ?? object.uuid ?? object['@self']?.id ?? '')
}

/**
 * Answer the credential list with one brokered credential.
 *
 * @param page The Playwright page.
 */
async function mockBroker(page: Page): Promise<void> {
	await page.route(CREDENTIALS, (route) => route.fulfill({ json: { results: [CREDENTIAL] } }))
}

/**
 * Open the SharePoint card in the Store and choose "Route to publications".
 *
 * @param page The Playwright page.
 */
async function openSetup(page: Page): Promise<void> {
	await searchStore(page, 'SharePoint')
	await page.getByRole('button', { name: 'Open catalog item SharePoint Online' }).click()
	await page.getByTestId('catalog-detail-route-to-publications').click()
	await expect(page.getByTestId('sharepoint-publication-setup-dialog')).toBeVisible({ timeout: 10_000 })
}

/**
 * Pick an option of an NcSelect by its visible label.
 *
 * @param page The Playwright page.
 * @param testId The select's data-testid.
 * @param label The option's label.
 */
async function pick(page: Page, testId: string, label: string): Promise<void> {
	await page.getByTestId(testId).locator('input').click()
	await page.getByRole('option', { name: label }).first().click()
}

/**
 * Pick the brokered credential and search for sites matching "Woo".
 *
 * @param page The Playwright page.
 */
async function searchWoo(page: Page): Promise<void> {
	await pick(page, 'sp-setup-credential', CREDENTIAL.name)
	await page.getByTestId('sp-setup-site-search').locator('input').fill('Woo')
	await page.getByTestId('sp-setup-site-search-button').click()
}

test.describe('REQ-SPPI-001: SharePoint to publication intake from the Store', () => {
	// @e2e document-cms-connectors::a-woo-coordinators-library-is-connected
	test('saving the four choices creates a source, a mapping and a synchronization, and no secret', async ({
		page,
		request,
	}) => {
		const marker = `E2E-${Date.now().toString(36)}`
		await mockBroker(page)
		const kinds: string[] = []
		await page.route(LOOKUP, (route) => {
			const kind = new URL(route.request().url()).searchParams.get('kind') ?? ''
			kinds.push(kind)
			return route.fulfill({ json: GRAPH[kind as keyof typeof GRAPH] ?? { items: [] } })
		})

		await openSetup(page)
		await searchWoo(page)
		await pick(page, 'sp-setup-site', 'Woo verzoeken')
		await pick(page, 'sp-setup-drive', 'Dossiers')
		await pick(page, 'sp-setup-folder', 'Gereed')

		// The catalogue list is OpenRegister's; with no catalogue on the test
		// instance the dialog asks for the id instead.
		const catalogSelect = page.getByTestId('sp-setup-catalog')
		if (await catalogSelect.isVisible().catch(() => false)) {
			await catalogSelect.locator('input').click()
			await page.getByRole('option').first().click()
		} else {
			await page.getByTestId('sp-setup-catalog-id').locator('input').fill(marker)
		}
		await page.getByTestId('sp-setup-approval').click()

		const saved = page.waitForResponse((resp) => resp.url().includes('/api/sharepoint/publication-intake'))
		await page.getByTestId('sp-setup-save').click()
		const intake = await saved
		expect(intake.status()).toBe(201)
		const created = (await intake.json()).created as Record<string, Array<{ slug: string, id: string | null }>>
		await expect(page.getByTestId('sp-setup-success')).toBeVisible()
		expect(kinds).toEqual(expect.arrayContaining(['sites', 'drives', 'children']))

		expect(created.sources ?? []).toHaveLength(1)
		expect(created.mappings ?? []).toHaveLength(1)
		expect(created.synchronizations ?? []).toHaveLength(1)

		const ids = (bucket: string) => (created[bucket] ?? []).map((entry) => String(entry.id))
		try {
			const source = (await listObjects(request, 'source')).find((row) => ids('sources').includes(idOf(row)))
			expect(source, 'the imported source is readable').toBeTruthy()
			expect(source?.configuration?.authentication?.credentialRef?.credentialId).toBe(CREDENTIAL.id)
			const stored = JSON.stringify(source)
			for (const secret of ['client_secret', 'clientSecret', 'password', 'access_token']) {
				expect(stored).not.toContain(secret)
			}

			const synchronization = (await listObjects(request, 'synchronization')).find((row) => ids('synchronizations').includes(idOf(row)))
			expect(synchronization, 'the imported synchronization is readable').toBeTruthy()
			// "Review before import" on: the first run pauses for approval.
			expect(synchronization?.sourceConfig?.requiresApproval).toBe(true)
		} finally {
			for (const [bucket, schema] of [['synchronizations', 'synchronization'], ['rules', 'rule'], ['mappings', 'mapping'], ['sources', 'source']]) {
				for (const id of ids(bucket)) {
					await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false })
				}
			}
		}
	})

	// @e2e document-cms-connectors::graph-refuses-the-site-search
	test('a Graph refusal names the missing permission and creates nothing', async ({
		page,
		request,
	}) => {
		await mockBroker(page)
		await page.route(LOOKUP, (route) => route.fulfill({
			status: 403,
			json: { error: 'graph_refused', permission: 'Sites.Read.All', graphMessage: 'Access denied' },
		}))
		const before = (await listObjects(request, 'source')).length

		await openSetup(page)
		await searchWoo(page)

		await expect(page.getByTestId('sp-setup-error')).toContainText('Sites.Read.All')
		await expect(page.getByTestId('sp-setup-site')).toHaveCount(0)
		await expect(page.getByTestId('sp-setup-save')).toBeDisabled()
		expect((await listObjects(request, 'source')).length).toBe(before)
	})
})

test.describe('REQ-SPPI-002: each dossier folder becomes a draft publication', () => {
	// @e2e document-cms-connectors::a-dossier-arrives-as-a-draft-with-two-documents
	test('the dossier maps to one draft publication with both documents and no publication date', async ({
		request,
	}) => {
		const seed = JSON.parse(fs.readFileSync(path.join(REPO, 'lib/Settings/integriq_mock_register.json'), 'utf8'))
		const objects = findObjects(seed)
		const mapping = objects.find((row) => row.slug === 'demo-sharepoint-folder-to-publication')
		const source = objects.find((row) => row.slug === 'demo-sharepoint-publications-source')
		expect(mapping, 'the shipped demo mapping').toBeTruthy()
		const dossier = source?.configuration?.mockResponse?.value?.find((item: Record<string, any>) => item.name === 'Woo-2026-014')
		expect(dossier, 'the canned Graph folder Woo-2026-014').toBeTruthy()

		// What the synchronization's extra-data call adds under `documents`:
		// the folder's children, a besluit and an inventarislijst.
		const inputObject = {
			...dossier,
			documents: {
				value: [
					{ id: '01BESLUIT', name: 'Besluit Woo-2026-014.pdf', file: { mimeType: 'application/pdf' }, parentReference: { driveId: 'b!woo-dossiers' } },
					{ id: '01INVENTARIS', name: 'Inventarislijst Woo-2026-014.xlsx', file: { mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }, parentReference: { driveId: 'b!woo-dossiers' } },
				],
			},
		}

		const run = await request.post(MAPPING_TEST, {
			data: { mapping, inputObject },
			failOnStatusCode: false,
		})
		expect(run.status()).toBe(200)
		const publication = (await run.json()).resultObject

		expect(publication.title).toBe('Besluit op het Woo-verzoek over de herinrichting van het Stationsplein')
		expect(publication.catalog).toBe('demo-woo-catalogus')
		expect(publication.source).toBe(dossier.webUrl)
		// The folder's "publicatiedatum" column is filled, and still nothing goes live.
		expect(publication).not.toHaveProperty('publicationDate')
		expect(publication.attachments).toEqual([
			{ filename: 'Besluit Woo-2026-014.pdf', endpoint: '/drives/b!woo-dossiers/items/01BESLUIT/content' },
			{ filename: 'Inventarislijst Woo-2026-014.xlsx', endpoint: '/drives/b!woo-dossiers/items/01INVENTARIS/content' },
		])
	})
})

test.describe('REQ-SPPI-003: the legacy SharePoint Woo template is deprecated', () => {
	// @e2e document-cms-connectors::an-administrator-is-steered-to-the-new-set
	test('importing the SharePoint Woo template warns and names the new set', async ({ page }) => {
		const legacy = JSON.parse(fs.readFileSync(path.join(REPO, 'configurations/sharepoint-woo/sources/sharepoint.json'), 'utf8'))

		await page.goto('/index.php/apps/integriq/store', { waitUntil: 'domcontentloaded' })
		const actions = page.getByTestId('cn-actions')
		await expect(actions).toBeVisible({ timeout: 15_000 })
		await actions.getByRole('button').first().click()
		await page.getByRole('menuitem', { name: /Import configuration/i }).first().click()
		await expect(page.getByTestId('import-preview-dialog')).toBeVisible({ timeout: 10_000 })

		await page.getByTestId('import-file-input').setInputFiles({
			name: 'sharepoint-woo.json',
			mimeType: 'application/json',
			buffer: Buffer.from(JSON.stringify({ components: { sources: { 'sharepoint-woo-e2e': { ...legacy, slug: 'sharepoint-woo-e2e' } } } })),
		})

		const warning = page.getByTestId('preview-deprecated')
		await expect(warning).toBeVisible({ timeout: 10_000 })
		await expect(warning).toContainText('sharepoint-woo-e2e')
		await expect(warning).toContainText('sharepoint-publications')
	})

	test('the demo SharePoint to publication synchronization is listed once demo data is installed', async ({
		page,
		request,
	}) => {
		const demo = (await listObjects(request, 'synchronization')).find((row) => (row.slug ?? row['@self']?.slug) === 'demo-sharepoint-publications-pull')
		test.skip(demo === undefined, 'demo data is not installed on this instance (demo-data-setup-step.spec.ts installs it)')

		await page.goto('/index.php/apps/integriq/synchronizations', { waitUntil: 'domcontentloaded' })
		await page.getByPlaceholder('Type to search…').fill('SharePoint to publications').catch(() => undefined)
		await expect(page.getByText('SharePoint to publications (Gereed (demo))').first()).toBeVisible({ timeout: 15_000 })
	})
})

/**
 * Every object carrying a `slug` anywhere in the seed document.
 *
 * @param node A node of the seed document.
 * @return The objects.
 */
function findObjects(node: unknown): Array<Record<string, any>> {
	if (Array.isArray(node)) {
		return node.flatMap(findObjects)
	}
	if (node === null || typeof node !== 'object') {
		return []
	}
	const object = node as Record<string, any>
	const own = typeof object.slug === 'string' && object['@self'] ? [object] : []

	return [...own, ...Object.values(object).flatMap(findObjects)]
}
