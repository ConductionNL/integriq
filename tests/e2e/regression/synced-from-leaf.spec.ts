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

import {
	test,
	expect,
	request as pwRequest,
	type APIRequestContext,
} from '@playwright/test'
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
const created: Array<{ register: string; schema: string; id: string }> = []

async function apiContext(): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: NEXTCLOUD,
		httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},
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
	expect(
		res.status(),
		`create ${register}/${schema} → ${await res.text()}`,
	).toBeLessThan(300)
	const json = await res.json()
	const self = json['@self'] ?? json.results?.['@self'] ?? {}
	const id = String(self.uuid ?? self.id ?? json.id ?? '')
	expect(id, `created ${register}/${schema} has an id`).toBeTruthy()
	created.push({ register, schema, id })
	return id
}

let targetId = ''
/**
 * A second REAL object that no contract points at.
 *
 * The strict-matching test used `00000000-0000-0000-0000-000000000000` and
 * expected 200 with an empty list. That conflates two different things: an
 * object that EXISTS but has no contract, and an id that matches nothing at
 * all. The second is a 404 — correctly, since the endpoint refuses to say
 * whether an object it cannot resolve exists.
 *
 * It only ever passed by accident, because the endpoint used to answer 500 for
 * a missing object and this suite never ran (openconnector.storage_migrated
 * was never set on a fresh install, ocon#1180). With that 500 fixed in
 * ConductionNL/openregister#2441 the honest answer arrived and the assertion
 * had nothing left to stand on.
 *
 * Matching strictly by targetId is what the test is FOR, so it needs a real
 * neighbour to not match.
 */
let unrelatedId = ''
let syncId = ''
let storageMigrated = false
let skipReason = 'openconnector.storage_migrated is not true on this instance'

test.describe('Synced-from leaf — contract provenance on objects', () => {
	test.beforeAll(async () => {
		const ctx = await apiContext()

		// The provider only emits contracts once OpenConnector storage has
		// migrated into OR (`SynchronizationContractProvider::isEnabled()`
		// returns `openconnector.storage_migrated === 'true'`). The file header
		// says this suite skips, rather than fails, when that flag isn't set.
		//
		// It did not, because the gate measured the wrong thing: it probed
		// `GET /objects/openconnector/synchronization_contract` and treated a
		// 200 as "migrated". That endpoint returns 200 whenever the register
		// exists, which says nothing about the flag — so on any instance with
		// the register seeded the suite ran anyway and failed on assertions
		// whose precondition was false.
		//
		// OpenRegister publishes the authoritative answer:
		// `GET /api/integrations` lists every registered provider with its
		// `enabled` state and, when disabled, a reason. For this one it reads
		// "OpenConnector storage migration has not yet run on this instance.
		// Sync contract leaves will appear after `occ upgrade` runs the
		// chain-C cutover." Read that.
		//
		// ⚠️ NOTE FOR WHOEVER PICKS THIS UP: on a FRESH install that flag is
		// never set, so this leaf is permanently disabled there.
		// `Version2Date20260520000001` only sets `storage_migrated=true` after
		// it successfully copies all 15 entities out of the legacy
		// `oc_openconnector_*` tables — and a fresh install has none to copy.
		// A brand-new openconnector therefore never surfaces "Synced from" on
		// any OpenRegister object. That is a product defect, not a test
		// problem, and it is why this suite skips in CI rather than passing.
		let disabledReason =
			'openconnector.storage_migrated is not true on this instance'
		try {
			const probe = await ctx.get(
				'/index.php/apps/openregister/api/integrations',
			)
			const body = await probe.json()
			const list: Array<Record<string, unknown>> =
				body.integrations ?? body.results ?? []
			const leaf = list.find((i) => i.id === 'sync-contract')
			storageMigrated = leaf?.enabled === true
			const authStatus = leaf?.authStatus as { message?: string } | undefined
			if (authStatus?.message) {
				disabledReason = authStatus.message
			}
		} catch {
			storageMigrated = false
		}

		if (!storageMigrated) {
			// eslint-disable-next-line no-console
			console.warn(`[synced-from-leaf] skipping: ${disabledReason}`)
			skipReason = disabledReason
			return
		}

		targetId = await createObject(ctx, TARGET_REGISTER, TARGET_SCHEMA, {
			name: 'E2E Synced Target',
			description:
				'Seeded by synced-from-leaf.spec.ts as the object a contract points at.',
		})
		unrelatedId = await createObject(ctx, TARGET_REGISTER, TARGET_SCHEMA, {
			name: 'E2E Unsynced Neighbour',
			description:
				'Seeded by synced-from-leaf.spec.ts as an object NO contract points at.',
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
			await ctx
				.delete(`${OR}/objects/${o.register}/${o.schema}/${o.id}`)
				.catch(() => undefined)
		}
		await ctx.dispose()
	})

	test('leaf endpoint returns the contract rendered for the target object', async () => {
		test.skip(!storageMigrated, skipReason)
		const ctx = await apiContext()
		const res = await ctx.get(
			`${OR}/objects/${TARGET_REGISTER}/${TARGET_SCHEMA}/${targetId}/integrations/sync-contract`,
		)
		expect(
			res.status(),
			'integrations/sync-contract MUST be 200 (regression: was 500 via getUuid __call)',
		).toBe(200)
		const body = await res.json()
		const items = body.items ?? body.results ?? body
		expect(Array.isArray(items)).toBe(true)
		expect(
			items.length,
			'one contract for the seeded target object (regression: was 0 via filter shape)',
		).toBe(1)

		const row = items[0]
		expect(row.title, 'title resolves to the synchronization name').toBe(
			'VNG Producten Sync',
		)
		expect(String(row.subtitle), 'subtitle summarises the last sync').toContain(
			'update',
		)
		expect(String(row.url), 'url deep-links into the synchronization').toContain(
			syncId,
		)
		expect(row.originId, 'raw provenance preserved').toBe('vng-product-4821')
		await ctx.dispose()
	})

	test('leaf matches strictly by targetId (a different object is empty)', async () => {
		test.skip(!storageMigrated, skipReason)
		const ctx = await apiContext()
		// A REAL neighbour, not a made-up uuid: the claim under test is that the
		// leaf matches by targetId, and only an object that exists can fail to
		// match. An id matching nothing answers 404 — which says the object was
		// not found, not that it has no contracts.
		const res = await ctx.get(
			`${OR}/objects/${TARGET_REGISTER}/${TARGET_SCHEMA}/${unrelatedId}/integrations/sync-contract`,
		)
		expect(res.status(), 'an existing object must answer, not 404').toBe(200)
		const body = await res.json()
		const items = body.items ?? body.results ?? body
		expect(
			Array.isArray(items) ? items.length : 0,
			'no contract for an unrelated object',
		).toBe(0)
		await ctx.dispose()
	})

	test('an object that does not exist is refused, not reported as empty', async () => {
		test.skip(!storageMigrated, skipReason)
		const ctx = await apiContext()
		// The counterpart, and the reason the test above needed a real object:
		// "no contracts" and "no such object" are different answers, and the
		// endpoint must not collapse them. It used to answer 500 here — see
		// ConductionNL/openregister#2441 — which is how this whole family went
		// unnoticed while the suite was skipped.
		const res = await ctx.get(
			`${OR}/objects/${TARGET_REGISTER}/${TARGET_SCHEMA}/00000000-0000-0000-0000-000000000000/integrations/sync-contract`,
		)
		expect(
			res.status(),
			'a missing object is 404, never 200 and never 500',
		).toBe(404)
		await ctx.dispose()
	})

	/*
	 * QUARANTINED — ocon#1228. Its SELECTORS have never been validated.
	 *
	 * This assertion has never executed: the whole suite skipped unless
	 * `openconnector.storage_migrated` was true, and no fresh install set it
	 * (ocon#1180). So `getByRole('tab', { name: 'Integrations' })` was written
	 * speculatively and has never once matched anything.
	 *
	 * Everything reachable from here HAS now been fixed and is green: the
	 * openregister 500 (ConductionNL/openregister#2441), the two API-level leaf
	 * assertions above, and the navigation — which walked every element for
	 * `el.__vue__.$router`, a Vue 2 internal, in a Vue 3 app. The route itself
	 * is confirmed correct against OpenRegister's manifest
	 * (`/objects/:register/:schema/:id`, `createWebHashHistory()`).
	 *
	 * What remains unknown is only what the rendered sidebar actually calls
	 * that tab, and with which ARIA role. That cannot be settled from here: the
	 * dev instance carries 1000+ schemas from earlier runs and has no
	 * `slug: 'source'` schema to address, so the fixture this test needs cannot
	 * be reproduced locally.
	 *
	 * Guessing selectors against a surface nobody has seen is how the original
	 * ones got here. Quarantined with an issue rather than left red or
	 * "fixed" by another guess.
	 */
	test.fixme('leaf renders the contract row on an object-detail integration surface', async ({
		page,
	}) => {
		test.skip(!storageMigrated, skipReason)

		// OpenRegister's own object detail mounts the same registry-driven
		// integration surface OpenCatalogi/decidesk use; it is the most
		// reliable place to assert the rendered leaf in CI. (The leaf is
		// app-agnostic — the data comes from the endpoint asserted above.)
		await page.goto(`${NEXTCLOUD}/index.php/apps/openregister/`)
		// ADR-074 rule 4: networkidle never settles on Nextcloud, so this only
		// ever burned its timeout and swallowed it. The assertions below do the
		// waiting.
		await page.waitForLoadState('domcontentloaded').catch(() => undefined)

		// OpenRegister's object-detail route takes NUMERIC register/schema ids.
		// These were hardcoded as '14' and '53' — whatever OpenCatalogi's
		// publication register happened to be numbered on the box this spec was
		// written on. Ids are assigned in install order, so on any other
		// instance that pair addresses a different register entirely (or
		// nothing), and every failure downstream is silent because each step
		// here ends in `.catch(() => undefined)`. Resolve them by slug instead.
		const idCtx = await apiContext()
		const lookupId = async (
			collection: string,
			slug: string,
		): Promise<string> => {
			const res = await idCtx.get(`${OR}/${collection}?_limit=1000`)
			expect(res.status(), `GET ${collection} for id lookup`).toBe(200)
			const body = await res.json()
			const items = Array.isArray(body) ? body : (body.results ?? [])
			const hit = items.find((i: Record<string, unknown>) => i.slug === slug)
			expect(
				hit,
				`OpenRegister must expose a "${slug}" ${collection.replace(/s$/, '')}`,
			).toBeTruthy()
			return String(hit.id)
		}
		const registerId = await lookupId('registers', TARGET_REGISTER)
		const schemaId = await lookupId('schemas', TARGET_SCHEMA)
		await idCtx.dispose()

		// Navigate by HASH, not by reaching into the app's router instance.
		//
		// This used to walk every element looking for `el.__vue__.$router`.
		// That is a VUE 2 internal and OpenRegister is Vue 3 (`"vue": "^3.5.0"`,
		// `createRouter` in src/main.js), so the walk found nothing, `router`
		// stayed null, and no navigation happened — silently, because the call
		// ended in `.catch(() => undefined)`. The first visible symptom was the
		// Integrations tab timing out 30s later on a page that had never left
		// the index. Exactly the failure mode the comment above this one warns
		// about for the old hardcoded ids.
		//
		// `createWebHashHistory()` means the route lives in the fragment, so a
		// plain goto IS the client-side navigation — no router handle needed,
		// and nothing to fall silent.
		await page.goto(
			`${NEXTCLOUD}/index.php/apps/openregister/#/objects/${registerId}/${schemaId}/${targetId}`,
		)
		await page.waitForLoadState('domcontentloaded').catch(() => undefined)

		// Open the Integrations tab, then the "Synced from" leaf.
		const integrationsTab = page.getByRole('tab', { name: 'Integrations' })
		await integrationsTab.waitFor({ state: 'visible', timeout: 30000 })
		await integrationsTab.click()

		await page.getByText('Synced from', { exact: false }).first().click()

		// The rendered contract row shows the resolved synchronization name.
		await expect(page.getByText('VNG Producten Sync').first()).toBeVisible({
			timeout: 15000,
		})
	})
})
