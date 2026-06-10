/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, DATA-DEPENDENT e2e — the SYNCHRONIZATION workflow, which is the whole
 * point of openconnector: moving and transforming data end-to-end.
 *
 * WHAT THIS PROVES (the high-value check)
 * ---------------------------------------
 * We build a fully in-environment, headlessly-drivable data pipeline:
 *
 *   [OR register/schema  "source data"]   (seeded with 2 objects)
 *            │  served by OpenRegister's own REST API
 *            ▼
 *   [openconnector Source]  type=json, location = the OR objects endpoint
 *            │  linked into
 *            ▼
 *   [openconnector Synchronization]  sourceType=api  →  targetType=register/schema
 *            │  RUN  (POST /api/synchronizations/{id}/run)
 *            ▼
 *   [OR register/schema  "target"]    ← assert the synced objects land here
 *
 * No external network is needed: the Source points at OpenRegister's REST
 * endpoint (`http://localhost/index.php/apps/openregister/api/objects/...`),
 * which openconnector can reach in-container (verified: HTTP 200) and which
 * loopback-exempts from the TLS policy. This is a genuine api→register/schema
 * transfer, not a render smoke.
 *
 * SETUP + LINKING are asserted live (register/schema/source/sync created and
 * persisted, the run endpoint resolves the sync, etc).
 *
 * THE ACTUAL DATA-MOVEMENT ASSERTIONS ARE test.fixme — blocked by two real
 * bugs in the current `development` tree (see the BUG markers below). The
 * fixme bodies are written to PASS the moment the bugs are fixed, so they
 * double as regression guards.
 */
import { test, expect } from '@playwright/test'
import {
	makeApiClient, makeRunId, createObject, idOf, findAll, deleteObject,
	cleanupByPrefix, OR_BASE, OC_API, type ApiClient,
} from './_fixture'

const RUN = makeRunId()

interface SyncFixture {
	api: ApiClient
	registerId: number
	srcSchemaId: number
	tgtSchemaId: number
	sourceId: string
	mappingId: string
	syncId: string
}

let fx: SyncFixture | null = null
const createdRegisterIds: number[] = []
const createdSchemaIds: number[] = []

/**
 * Build the whole pipeline through the REST API and return its handles.
 * Throws (failing the test) if any setup step does not persist — setup is
 * itself an assertion that the data layer works.
 */
async function buildPipeline(api: ApiClient): Promise<SyncFixture> {
	// 1. Two schemas (source-data + target) with name/city fields.
	const schemaBody = (title: string) => ({
		title, description: `${RUN} ${title}`,
		properties: { name: { type: 'string' }, city: { type: 'string' } },
	})
	const mkSchema = async (title: string): Promise<number> => {
		const r = await api.request.post('/index.php/apps/openregister/api/schemas', { data: schemaBody(title), failOnStatusCode: false })
		expect(r.status(), `schema ${title} must be created`).toBeLessThan(300)
		const id = (await r.json()).id as number
		createdSchemaIds.push(id)
		return id
	}
	const srcSchemaId = await mkSchema(`${RUN}-srcschema`)
	const tgtSchemaId = await mkSchema(`${RUN}-tgtschema`)

	// 2. A register binding both schemas.
	const regResp = await api.request.post('/index.php/apps/openregister/api/registers', {
		data: { title: `${RUN}-reg`, description: RUN, schemas: [srcSchemaId, tgtSchemaId] },
		failOnStatusCode: false,
	})
	expect(regResp.status(), 'register must be created').toBeLessThan(300)
	const registerId = (await regResp.json()).id as number
	createdRegisterIds.push(registerId)

	// 3. Seed 2 source objects.
	const seed = [{ name: `${RUN}-alice`, city: 'Amsterdam' }, { name: `${RUN}-bob`, city: 'Rotterdam' }]
	for (const o of seed) {
		const r = await api.request.post(`/index.php/apps/openregister/api/objects/${registerId}/${srcSchemaId}`, { data: o, failOnStatusCode: false })
		expect(r.status(), `seed ${o.name} must persist`).toBeLessThan(300)
	}
	const seeded = await api.request.get(`/index.php/apps/openregister/api/objects/${registerId}/${srcSchemaId}?_limit=10`)
	expect((await seeded.json()).total, 'source register must hold 2 seeded objects').toBe(2)

	// 4. A Source pointing at the OR REST endpoint serving the seeded objects.
	const sourceLocation = `http://localhost/index.php/apps/openregister/api/objects/${registerId}/${srcSchemaId}`
	const source = await createObject(api, 'source', {
		name: `${RUN}-source`, description: RUN, location: sourceLocation, type: 'json', isEnabled: true,
	})
	const sourceId = idOf(source)
	expect(sourceId, 'source must persist with an id').toBeTruthy()

	// 5. A Mapping (identity pass-through — name/city carry over unchanged).
	const mapping = await createObject(api, 'mapping', {
		name: `${RUN}-mapping`, description: RUN, passThrough: true,
		mapping: { name: '{{ name }}', city: '{{ city }}' },
	})
	const mappingId = idOf(mapping)
	expect(mappingId, 'mapping must persist with an id').toBeTruthy()

	// 6. The Synchronization: api source -> register/schema target, linked to the mapping.
	//    NOTE the array fields (conditions/followUps/actions/configurations) are
	//    set explicitly: omitting them makes the OR object serialize them as null,
	//    which crashes Synchronization::hydrate() (BUG B below).
	const sync = await createObject(api, 'synchronization', {
		name: `${RUN}-sync`, description: RUN,
		sourceId, sourceType: 'api',
		sourceConfig: { endpoint: '', resultsPosition: 'results' },
		sourceTargetMapping: mappingId,
		targetType: 'register/schema',
		targetId: `${registerId}/${tgtSchemaId}`,
		conditions: [], followUps: [], actions: [], configurations: [],
	})
	const syncId = idOf(sync)
	expect(syncId, 'synchronization must persist with an id').toBeTruthy()

	return { api, registerId, srcSchemaId, tgtSchemaId, sourceId, mappingId, syncId }
}

test.beforeAll(async ({ browser, baseURL }) => {
	const api = await makeApiClient(browser, baseURL!)
	fx = await buildPipeline(api)
})

test.afterAll(async () => {
	if (!fx) return
	const { api } = fx
	// Delete the synchronization/source/mapping objects this run created.
	await cleanupByPrefix(api, 'synchronization', RUN)
	await cleanupByPrefix(api, 'source', RUN)
	await cleanupByPrefix(api, 'mapping', RUN)
	// Delete the seeded/target objects + the registers + schemas.
	for (const regId of createdRegisterIds) {
		for (const schemaId of createdSchemaIds) {
			const list = await api.request.get(`/index.php/apps/openregister/api/objects/${regId}/${schemaId}?_limit=200`, { failOnStatusCode: false })
			if (list.ok()) {
				const rows = (await list.json()).results ?? []
				for (const row of rows) {
					const id = row['@self']?.id ?? row.id
					if (id) await api.request.delete(`/index.php/apps/openregister/api/objects/${regId}/${schemaId}/${id}`, { failOnStatusCode: false })
				}
			}
		}
		await api.request.delete(`/index.php/apps/openregister/api/registers/${regId}`, { failOnStatusCode: false })
	}
	for (const schemaId of createdSchemaIds) {
		await api.request.delete(`/index.php/apps/openregister/api/schemas/${schemaId}`, { failOnStatusCode: false })
	}
	await api.dispose()
})

test.describe('Synchronization workflow — pipeline setup & linking', () => {
	test('the full source→sync→target pipeline persists and is linked', async () => {
		expect(fx).not.toBeNull()
		const { api, sourceId, mappingId, syncId, registerId, tgtSchemaId } = fx!

		// The synchronization persisted and links the source, mapping and target.
		const syncs = (await findAll(api, 'synchronization', { _search: RUN }))
			.filter((s: Record<string, unknown>) => s.name === `${RUN}-sync`)
		expect(syncs.length, 'the synchronization must be queryable').toBe(1)
		const sync = syncs[0]
		expect(String(sync.sourceId)).toBe(sourceId)
		expect(String(sync.sourceTargetMapping)).toBe(mappingId)
		expect(String(sync.targetId)).toBe(`${registerId}/${tgtSchemaId}`)
		expect(sync.sourceType).toBe('api')
		expect(sync.targetType).toBe('register/schema')

		// The run endpoint resolves the sync (does NOT 404) — it is reachable.
		const resp = await api.request.post(`${OC_API}/synchronizations/${syncId}/run`, { data: { test: true }, failOnStatusCode: false })
		expect(resp.status(), 'run endpoint must resolve the sync (not 404)').not.toBe(404)
	})
})

test.describe('Synchronization workflow — data movement (the high-value check)', () => {
	/*
	 * BUG A (BLOCKING — sync run 500): SynchronizationService always creates a
	 * SynchronizationLog first via SynchronizationLogMapper::createFromArray(),
	 * which does `new SynchronizationLog()`. The `SynchronizationLog` entity
	 * class was DELETED by commit 7df241bc ("feat(or-cutover): delete 15
	 * entities + 15 mappers") but the mapper + its injection into
	 * SynchronizationService were left behind, so every run throws
	 *   Error: Class "OCA\OpenConnector\Db\SynchronizationLog" not found
	 *          (lib/Db/SynchronizationLogMapper.php:107)
	 * and POST /api/synchronizations/{id}/run returns HTTP 500. The READ path
	 * for logs already migrated to OR (schema `synchronization_log`), but the
	 * WRITE path did not — the or-cutover for synchronization-log is incomplete.
	 *
	 * BUG B (robustness — hydrate 500): Synchronization::$conditions (and
	 * $followUps/$actions) are non-nullable `array` properties. When a sync OR
	 * object is created WITHOUT those keys, OR serializes them as null and
	 * Synchronization::hydrate() throws
	 *   Cannot assign null to property ...Synchronization::$conditions of type array
	 * → another 500 before the run even starts. (This fixture works around B by
	 * setting [] explicitly; A still blocks regardless.)
	 *
	 * Both make the core data-movement feature unrunnable headlessly today, so
	 * the transfer assertion is test.fixme. The body is written to PASS once the
	 * run succeeds, doubling as a regression guard.
	 */
	test.fixme('running the sync transfers the 2 source objects into the target register', async () => {
		const { api, syncId, registerId, tgtSchemaId } = fx!

		const resp = await api.request.post(`${OC_API}/synchronizations/${syncId}/run`, { data: {}, failOnStatusCode: false })
		expect(resp.status(), 'sync run must succeed once BUG A/B are fixed').toBe(200)
		const body = await resp.json()
		// The run reports objects found/created.
		const objects = body?.result?.objects ?? body?.objects ?? {}
		expect(Number(objects.found ?? 0)).toBeGreaterThanOrEqual(2)
		expect(Number(objects.created ?? 0) + Number(objects.updated ?? 0)).toBeGreaterThanOrEqual(2)

		// The target register now holds the 2 synced objects with the mapped values.
		const tgt = await api.request.get(`/index.php/apps/openregister/api/objects/${registerId}/${tgtSchemaId}?_limit=10`)
		const tgtBody = await tgt.json()
		expect(tgtBody.total, 'target register must gain 2 synced objects').toBe(2)
		const names = (tgtBody.results ?? []).map((o: Record<string, unknown>) => o.name).sort()
		expect(names).toEqual([`${RUN}-alice`, `${RUN}-bob`])
		const alice = (tgtBody.results ?? []).find((o: Record<string, unknown>) => o.name === `${RUN}-alice`)
		expect(alice?.city, 'mapped field "city" must carry over').toBe('Amsterdam')
	})

	/*
	 * Same blocking bugs (A/B): a run must record a SynchronizationLog with a
	 * success status, readable through the logs endpoint
	 * (GET /api/synchronizations/logs, backed by OR schema `synchronization_log`).
	 * Today the write-path crash (BUG A) means no log is ever recorded.
	 */
	test.fixme('the run records a synchronization log with a success status', async () => {
		const { api, syncId } = fx!

		await api.request.post(`${OC_API}/synchronizations/${syncId}/run`, { data: {}, failOnStatusCode: false })

		const logsResp = await api.request.get(`${OC_API}/synchronizations/logs?_limit=50`, { failOnStatusCode: false })
		expect(logsResp.ok(), 'logs endpoint must respond').toBeTruthy()
		const logsBody = await logsResp.json()
		const rows = logsBody.results ?? logsBody.logs ?? logsBody ?? []
		const ours = (Array.isArray(rows) ? rows : []).filter(
			(r: Record<string, unknown>) => String(r.synchronizationId ?? '') === String(syncId),
		)
		expect(ours.length, 'a run-log must be recorded for this synchronization').toBeGreaterThanOrEqual(1)
		// The most recent log must not carry an error message / must be marked done.
		const latest = ours[ours.length - 1]
		const message = String(latest.message ?? '').toLowerCase()
		expect(message).not.toContain('error')
	})

	/*
	 * ACTIVE regression guard for BUG A. While the bug is present this asserts
	 * the run currently 500s for the documented reason. When BUG A is fixed the
	 * run will no longer 500 and THIS test will fail loudly, prompting whoever
	 * fixed it to un-fixme the two transfer tests above. (Kept deliberately
	 * narrow: it only asserts the *current* broken contract.)
	 */
	test('REGRESSION GUARD: sync run currently 500s (missing SynchronizationLog entity — BUG A)', async () => {
		const { api, syncId } = fx!
		const resp = await api.request.post(`${OC_API}/synchronizations/${syncId}/run`, { data: {}, failOnStatusCode: false })
		expect(
			resp.status(),
			'If this is no longer 500, BUG A (missing SynchronizationLog entity) is fixed — ' +
			'remove this guard and un-fixme the data-movement tests above.',
		).toBe(500)
	})
})
