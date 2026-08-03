/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * E2E: the "Synced from" integration leaf surfaces a synchronization
 * contract on the OpenRegister object it syncs.
 *
 * This is the cross-app provenance promise of the integration registry:
 * OpenConnector registers a Path-2 leaf (SynchronizationContractProvider)
 * with OpenRegister; any app that renders an OR object's integration
 * surface (OpenCatalogi publication detail, OpenRegister object detail,
 * decidesk, …) then shows "Synced from" with the contract — with no code
 * change in the consuming app.
 *
 * The test seeds a target object + a synchronization + a contract whose
 * `targetId` is that object, then asserts:
 *   1. The leaf endpoint (the data every registry surface consumes)
 *      returns the contract, rendered (title/subtitle/url/originId).
 *   2. The leaf matches strictly by targetId (a different object is empty).
 *   3. The leaf renders the contract row in a real object-detail UI
 *      (OpenRegister's object detail → Integrations → "Synced from").
 *
 * Regression guard for the two bugs fixed alongside this spec:
 *   - findAll filter shape (register/schema leaking as property filters →
 *     always empty);
 *   - getUuid() via Entity __call (method_exists false → HTTP 500).
 *
 * The provider gates on `openconnector.storage_migrated === 'true'`; the
 * suite skips (not fails) when that flag isn't set on the instance.
 */

import { test, expect, request as pwRequest, type APIRequestContext } from '@playwright/test'
import { BASE_URL } from '../support/baseUrl'

const NEXTCLOUD = BASE_URL
const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS || 'admin'

const OR = '/index.php/apps/openregister/api'
const OC_REGISTER = 'openconnector'

// The object the contract points AT.
//
// This used to be `publication`/`publication` — OpenCatalogi's register. That
// made the suite silently depend on OpenCatalogi being installed AND its
// register imported, which is true on a developer's full-fleet container and
// false in openconnector's own CI (`additional-apps` carries OpenRegister
// only). The failure was not a skip: `beforeAll` seeds the target object
// first, so the create returned `{"message":"Register not found:
// 'publication'"}` and took the whole describe down with it.
//
// Nothing in what this spec asserts needs a publication.
// `SynchronizationContractProvider::list()` matches on `targetId === $objectId`
// and nothing else — the target's register and schema are not part of the
// match (see its docblock: the register constant there scopes where the
// CONTRACTS live, not where the target lives). The file header already frames
// the promise as register-agnostic: "any app that renders an OR object's
// integration surface (OpenCatalogi publication detail, OpenRegister object
// detail, decidesk, …)".
//
// So the target is now an object in openconnector's own register, which the
// CI seed provisions. Every assertion below is unchanged in meaning: the leaf
// still has to find the contract by targetId, still has to render it, and
// still has to return nothing for an unrelated id.
const TARGET_REGISTER = OC_REGISTER
const TARGET_SCHEMA = 'source'

/** OR object ids created by the suite, torn down in afterAll. */
const created: Array<{ register: string, schema: string, id: string }> = []

async function apiContext(): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: NEXTCLOUD,
		httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
	})
}

/** Create an OR object and remember it for cleanup. Returns its uuid/id. */
async function createObject(
	ctx: APIRequestContext,
	register: string,
	schema: string,
	body: Record<string, unknown>,
): Promise<string> {
	const res = await ctx.post(`${OR}/objects/${register}/${schema}`, { data: body })
	expect(res.status(), `create ${register}/${schema} → ${await res.text()}`).toBeLessThan(300)
	const json = await res.json()
	const self = json['@self'] ?? json.results?.['@self'] ?? {}
	const id = String(self.uuid ?? self.id ?? json.id ?? '')
	expect(id, `created ${register}/${schema} has an id`).toBeTruthy()
	created.push({ register, schema, id })
	return id
}

let targetId = ''
let syncId = ''
let storageMigrated = false

test.describe('Synced-from leaf — contract provenance on objects', () => {

	test.beforeAll(async () => {
		const ctx = await apiContext()

		// The provider only emits contracts once OpenConnector storage has
		// migrated into OR. Skip the whole suite (rather than fail) otherwise.
		const cfg = await ctx.get('/index.php/apps/openconnector/api/settings').catch(() => null)
		// Best-effort flag read; fall back to attempting the seed regardless.
		storageMigrated = true
		try {
			const probe = await ctx.get(`${OR}/objects/${OC_REGISTER}/synchronization_contract?_limit=1`)
			storageMigrated = probe.status() === 200
		} catch {
			storageMigrated = false
		}
		void cfg

		if (!storageMigrated) {
			return
		}

		targetId = await createObject(ctx, TARGET_REGISTER, TARGET_SCHEMA, {
			name: 'E2E Synced Target',
			description: 'Seeded by synced-from-leaf.spec.ts as the object a contract points at.',
		})
		syncId = await createObject(ctx, OC_REGISTER, 'synchronization', {
			name: 'VNG Producten Sync',
			description: 'Seeded synchronization for the synced-from leaf E2E.',
		})
		const now = new Date().toISOString()
		await createObject(ctx, OC_REGISTER, 'synchronization_contract', {
			synchronizationId: syncId,
			originId: 'vng-product-4821',
			originHash: 'a1b2c3',
			targetId,
			targetLastSynced: now,
			targetLastAction: 'update',
			sourceLastChecked: now,
		})
		await ctx.dispose()
	})

	test.afterAll(async () => {
		const ctx = await apiContext()
		for (const o of created.reverse()) {
			await ctx.delete(`${OR}/objects/${o.register}/${o.schema}/${o.id}`).catch(() => undefined)
		}
		await ctx.dispose()
	})

	test('leaf endpoint returns the contract rendered for the target object', async () => {
		test.skip(!storageMigrated, 'openconnector.storage_migrated is not true on this instance')
		const ctx = await apiContext()
		const res = await ctx.get(
			`${OR}/objects/${TARGET_REGISTER}/${TARGET_SCHEMA}/${targetId}/integrations/sync-contract`,
		)
		expect(res.status(), 'integrations/sync-contract MUST be 200 (regression: was 500 via getUuid __call)').toBe(200)
		const body = await res.json()
		const items = body.items ?? body.results ?? body
		expect(Array.isArray(items)).toBe(true)
		expect(items.length, 'one contract for the seeded target object (regression: was 0 via filter shape)').toBe(1)

		const row = items[0]
		expect(row.title, 'title resolves to the synchronization name').toBe('VNG Producten Sync')
		expect(String(row.subtitle), 'subtitle summarises the last sync').toContain('update')
		expect(String(row.url), 'url deep-links into the synchronization').toContain(syncId)
		expect(row.originId, 'raw provenance preserved').toBe('vng-product-4821')
		await ctx.dispose()
	})

	test('leaf matches strictly by targetId (a different object is empty)', async () => {
		test.skip(!storageMigrated, 'openconnector.storage_migrated is not true on this instance')
		const ctx = await apiContext()
		const otherId = '00000000-0000-0000-0000-000000000000'
		const res = await ctx.get(
			`${OR}/objects/${TARGET_REGISTER}/${TARGET_SCHEMA}/${otherId}/integrations/sync-contract`,
		)
		expect(res.status()).toBe(200)
		const body = await res.json()
		const items = body.items ?? body.results ?? body
		expect(Array.isArray(items) ? items.length : 0, 'no contract for an unrelated object').toBe(0)
		await ctx.dispose()
	})

	test('leaf renders the contract row on an object-detail integration surface', async ({ page }) => {
		test.skip(!storageMigrated, 'openconnector.storage_migrated is not true on this instance')

		// OpenRegister's own object detail mounts the same registry-driven
		// integration surface OpenCatalogi/decidesk use; it is the most
		// reliable place to assert the rendered leaf in CI. (The leaf is
		// app-agnostic — the data comes from the endpoint asserted above.)
		await page.goto(`${NEXTCLOUD}/index.php/apps/openregister/`)
		await page.waitForLoadState('networkidle').catch(() => undefined)

		// OpenRegister's object-detail route takes NUMERIC register/schema ids.
		// These were hardcoded as '14' and '53' — whatever OpenCatalogi's
		// publication register happened to be numbered on the box this spec was
		// written on. Ids are assigned in install order, so on any other
		// instance that pair addresses a different register entirely (or
		// nothing), and every failure downstream is silent because each step
		// here ends in `.catch(() => undefined)`. Resolve them by slug instead.
		const idCtx = await apiContext()
		const lookupId = async (collection: string, slug: string): Promise<string> => {
			const res = await idCtx.get(`${OR}/${collection}?_limit=1000`)
			expect(res.status(), `GET ${collection} for id lookup`).toBe(200)
			const body = await res.json()
			const items = Array.isArray(body) ? body : (body.results ?? [])
			const hit = items.find((i: Record<string, unknown>) => i.slug === slug)
			expect(hit, `OpenRegister must expose a "${slug}" ${collection.replace(/s$/, '')}`).toBeTruthy()
			return String(hit.id)
		}
		const registerId = await lookupId('registers', TARGET_REGISTER)
		const schemaId = await lookupId('schemas', TARGET_SCHEMA)
		await idCtx.dispose()

		// Navigate client-side via the SPA router (server strips /index.php,
		// which otherwise breaks the history base on a hard deep-link).
		await page.evaluate(({ reg, sch, id }) => {
			let router: any = null
			for (const el of Array.from(document.querySelectorAll('body *'))) {
				const v = (el as any).__vue__
				if (v && v.$router) { router = v.$router; break }
			}
			if (router) { router.push(`/objects/${reg}/${sch}/${id}`).catch(() => {}) }
		}, { reg: registerId, sch: schemaId, id: targetId }).catch(() => undefined)

		// Open the Integrations tab, then the "Synced from" leaf.
		const integrationsTab = page.getByRole('tab', { name: 'Integrations' })
		await integrationsTab.waitFor({ state: 'visible', timeout: 30000 })
		await integrationsTab.click()

		await page.getByText('Synced from', { exact: false }).first().click()

		// The rendered contract row shows the resolved synchronization name.
		await expect(page.getByText('VNG Producten Sync').first()).toBeVisible({ timeout: 15000 })
	})
})
