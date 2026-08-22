/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, DATA-DEPENDENT e2e — the SYNCHRONIZATION workflow, which is the whole
 * point of integriq: moving and transforming data end-to-end.
 *
 * WHAT THIS PROVES (the high-value check)
 * ---------------------------------------
 * We build a fully in-environment, headlessly-drivable data pipeline:
 *
 *   [OR register/schema  "source data"]   (seeded with 2 objects)
 *            │  served by OpenRegister's own REST API
 *            ▼
 *   [integriqSource]  type=json, location = the OR objects endpoint
 *            │  linked into
 *            ▼
 *   [integriqSynchronization]  sourceType=api  →  targetType=register/schema
 *            │  RUN  (POST /api/synchronizations/{id}/run)
 *            ▼
 *   [OR register/schema  "target"]    ← assert the synced objects land here
 *
 * No external network is needed: the Source points at OpenRegister's REST
 * endpoint (`http://localhost/index.php/apps/openregister/api/objects/...`),
 * which integriq can reach in-container (verified: HTTP 200) and which
 * loopback-exempts from the TLS policy. This is a genuine api→register/schema
 * transfer, not a render smoke.
 *
 * ⚠️ THE FIXTURE'S SOURCE IS THE WRONG SHAPE (ocon#1190). That HTTP 200 is a
 * session/redirect page, not the seeded objects: the in-container fetch has no
 * admin session. So this fixture exercises Nextcloud's auth middleware, not
 * the sync engine. It needs an EXTERNAL-shaped source — one that answers
 * without a Nextcloud session — and `sourceType: 'register/schema'` is not the
 * way out either: reading FROM a register/schema is `@todo: implement` in
 * `getAllObjectsFromSource()`. Still open; see the note on the first fixme.
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
	makeApiClient,
	makeRunId,
	createObject,
	idOf,
	findAll,
	deleteObject,
	cleanupByPrefix,
	OR_BASE,
	OC_API,
	type ApiClient,
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
		title,
		description: `${RUN} ${title}`,
		properties: { name: { type: 'string' }, city: { type: 'string' } },
	})
	const mkSchema = async (title: string): Promise<number> => {
		const r = await api.request.post(
			'/index.php/apps/openregister/api/schemas',
			{ data: schemaBody(title), failOnStatusCode: false },
		)
		expect(r.status(), `schema ${title} must be created`).toBeLessThan(300)
		const id = (await r.json()).id as number
		createdSchemaIds.push(id)
		return id
	}
	const srcSchemaId = await mkSchema(`${RUN}-srcschema`)
	const tgtSchemaId = await mkSchema(`${RUN}-tgtschema`)

	// 2. A register binding both schemas.
	const regResp = await api.request.post(
		'/index.php/apps/openregister/api/registers',
		{
			data: {
				title: `${RUN}-reg`,
				description: RUN,
				schemas: [srcSchemaId, tgtSchemaId],
			},
			failOnStatusCode: false,
		},
	)
	expect(regResp.status(), 'register must be created').toBeLessThan(300)
	const registerId = (await regResp.json()).id as number
	createdRegisterIds.push(registerId)

	// 3. Seed 2 source objects.
	const seed = [
		{ name: `${RUN}-alice`, city: 'Amsterdam' },
		{ name: `${RUN}-bob`, city: 'Rotterdam' },
	]
	for (const o of seed) {
		const r = await api.request.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${srcSchemaId}`,
			{ data: o, failOnStatusCode: false },
		)
		expect(r.status(), `seed ${o.name} must persist`).toBeLessThan(300)
	}
	const seeded = await api.request.get(
		`/index.php/apps/openregister/api/objects/${registerId}/${srcSchemaId}?_limit=10`,
	)
	expect(
		(await seeded.json()).total,
		'source register must hold 2 seeded objects',
	).toBe(2)

	// 4. A Source pointing at the OR REST endpoint serving the seeded objects.
	const sourceLocation = `http://localhost/index.php/apps/openregister/api/objects/${registerId}/${srcSchemaId}`
	const source = await createObject(api, 'source', {
		name: `${RUN}-source`,
		description: RUN,
		location: sourceLocation,
		type: 'json',
		isEnabled: true,
	})
	const sourceId = idOf(source)
	expect(sourceId, 'source must persist with an id').toBeTruthy()

	// 5. A Mapping (identity pass-through — name/city carry over unchanged).
	const mapping = await createObject(api, 'mapping', {
		name: `${RUN}-mapping`,
		description: RUN,
		passThrough: true,
		mapping: { name: '{{ name }}', city: '{{ city }}' },
	})
	const mappingId = idOf(mapping)
	expect(mappingId, 'mapping must persist with an id').toBeTruthy()

	// 6. The Synchronization: api source -> register/schema target, linked to the mapping.
	//    NOTE the array fields (conditions/followUps/actions/configurations) are
	//    set explicitly: omitting them makes the OR object serialize them as null,
	//    which crashes Synchronization::hydrate() (BUG B below).
	const sync = await createObject(api, 'synchronization', {
		name: `${RUN}-sync`,
		description: RUN,
		sourceId,
		sourceType: 'api',
		sourceConfig: { endpoint: '', resultsPosition: 'results' },
		sourceTargetMapping: mappingId,
		targetType: 'register/schema',
		targetId: `${registerId}/${tgtSchemaId}`,
		conditions: [],
		followUps: [],
		actions: [],
		configurations: [],
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
			const list = await api.request.get(
				`/index.php/apps/openregister/api/objects/${regId}/${schemaId}?_limit=200`,
				{ failOnStatusCode: false },
			)
			if (list.ok()) {
				const rows = (await list.json()).results ?? []
				for (const row of rows) {
					const id = row['@self']?.id ?? row.id
					if (id)
						await api.request.delete(
							`/index.php/apps/openregister/api/objects/${regId}/${schemaId}/${id}`,
							{ failOnStatusCode: false },
						)
				}
			}
		}
		await api.request.delete(
			`/index.php/apps/openregister/api/registers/${regId}`,
			{ failOnStatusCode: false },
		)
	}
	for (const schemaId of createdSchemaIds) {
		await api.request.delete(
			`/index.php/apps/openregister/api/schemas/${schemaId}`,
			{ failOnStatusCode: false },
		)
	}
	await api.dispose()
})

test.describe('Synchronization workflow — pipeline setup & linking', () => {
	test('the full source→sync→target pipeline persists and is linked', async () => {
		expect(fx).not.toBeNull()
		const { api, sourceId, mappingId, syncId, registerId, tgtSchemaId } = fx!

		// The synchronization persisted and links the source, mapping and target.
		const syncs = (
			await findAll(api, 'synchronization', { _search: RUN })
		).filter((s: Record<string, unknown>) => s.name === `${RUN}-sync`)
		expect(syncs.length, 'the synchronization must be queryable').toBe(1)
		const sync = syncs[0]
		expect(String(sync.sourceId)).toBe(sourceId)
		expect(String(sync.sourceTargetMapping)).toBe(mappingId)
		expect(String(sync.targetId)).toBe(`${registerId}/${tgtSchemaId}`)
		expect(sync.sourceType).toBe('api')
		expect(sync.targetType).toBe('register/schema')

		// The run endpoint resolves the sync (does NOT 404) — it is reachable.
		const resp = await api.request.post(
			`${OC_API}/synchronizations/${syncId}/run`,
			{ data: { test: true }, failOnStatusCode: false },
		)
		expect(
			resp.status(),
			'run endpoint must resolve the sync (not 404)',
		).not.toBe(404)
	})
})

test.describe('Synchronization workflow — data movement (the high-value check)', () => {
	/*
	 * BUG A (FIXED): SynchronizationService created its run-log via the orphaned
	 * SynchronizationLogMapper (`new SynchronizationLog()` — entity deleted in the
	 * or-cutover 7df241bc), so every run threw a 500. The write path now goes
	 * through SynchronizationLogService → OpenRegister (schema `synchronization_log`,
	 * write-once because the schema is append-only), matching the read path.
	 *
	 * BUG B (FIXED): Synchronization::$conditions/$followUps/$actions are now
	 * nullable-safe and hydrate() coerces a null JSON field to []; a sync object
	 * created without those keys no longer 500s on hydrate.
	 *
	 * MAPPER CUTOVER (FIXED): the engine's surviving QBMappers (SourceMapper /
	 * MappingMapper / RuleMapper / SynchronizationContractMapper /
	 * SynchronizationContractLogMapper) were migrated onto the OpenRegister object
	 * API (registers/schemas `source` / `mapping` / `rule` /
	 * `synchronization_contract` / `synchronization_contract_log`). A real run now
	 * resolves its source/mapping (no more `relation "oc_openconnector_sources"
	 * does not exist`), executes the full engine and returns HTTP 200 with a
	 * `message: "Success"` run-log (verified live).
	 *
	 * REMAINING BLOCKER for this specific assertion (NOT the mapper cutover):
	 * ocon#1190. The Source in this fixture points at OpenRegister's own REST
	 * endpoint, and the in-container CallService request reaches it
	 * UNAUTHENTICATED (no admin session), so OR answers 200 with a ~322-byte
	 * session/redirect body instead of the 2 seeded objects.
	 *
	 * THE SYMPTOM HAS CHANGED, and this note with it. The engine no longer
	 * reads that body as an empty page: an unparseable 200 is now a FAILED
	 * page, logged as such and naming the HTML shape, so the run reports a
	 * failure instead of a success that found nothing. That was the product
	 * half of #1190 and it is fixed.
	 *
	 * What is still open is the FIXTURE half: this Source is the wrong shape.
	 * Pointing it at an authenticated localhost endpoint tests Nextcloud's auth
	 * middleware, not the sync engine, so it needs an external-shaped source
	 * that answers without a Nextcloud session. `sourceType: 'register/schema'`
	 * is not the way out — reading FROM a register/schema is `@todo: implement`
	 * in `getAllObjectsFromSource()`.
	 *
	 * The body below PASSES once the source fetch returns the seeded objects.
	 */
	test.fixme('running the sync transfers the 2 source objects into the target register', async () => {
		const { api, syncId, registerId, tgtSchemaId } = fx!

		const resp = await api.request.post(
			`${OC_API}/synchronizations/${syncId}/run`,
			{ data: {}, failOnStatusCode: false },
		)
		expect(resp.status(), 'sync run must succeed once BUG A/B are fixed').toBe(
			200,
		)
		const body = await resp.json()
		// The run reports objects found/created.
		const objects = body?.result?.objects ?? body?.objects ?? {}
		expect(Number(objects.found ?? 0)).toBeGreaterThanOrEqual(2)
		expect(
			Number(objects.created ?? 0) + Number(objects.updated ?? 0),
		).toBeGreaterThanOrEqual(2)

		// The target register now holds the 2 synced objects with the mapped values.
		const tgt = await api.request.get(
			`/index.php/apps/openregister/api/objects/${registerId}/${tgtSchemaId}?_limit=10`,
		)
		const tgtBody = await tgt.json()
		expect(tgtBody.total, 'target register must gain 2 synced objects').toBe(2)
		const names = (tgtBody.results ?? [])
			.map((o: Record<string, unknown>) => o.name)
			.sort()
		expect(names).toEqual([`${RUN}-alice`, `${RUN}-bob`])
		const alice = (tgtBody.results ?? []).find(
			(o: Record<string, unknown>) => o.name === `${RUN}-alice`,
		)
		expect(alice?.city, 'mapped field "city" must carry over').toBe('Amsterdam')
	})

	/*
	 * The run now completes and the SynchronizationService returns a
	 * `message: "Success"` run-log object for the run (verified live, HTTP 200).
	 * This assertion stays test.fixme because it reads the log back through GET
	 * /api/synchronizations/logs, which is subject to OpenRegister's in-request
	 * object-search visibility limit: an object written during the same request is
	 * not always returned by a subsequent search in that window (a known OR
	 * constraint being addressed separately). It PASSES once the just-written log
	 * is searchable.
	 *
	 * Tracked as ocon#1190 (second half). Recorded here rather than left as a
	 * floating comment: a fixme with an issue number is honest quarantine, one
	 * without is indistinguishable from a test somebody gave up on.
	 */
	test.fixme('the run records a synchronization log with a success status', async () => {
		const { api, syncId } = fx!

		await api.request.post(`${OC_API}/synchronizations/${syncId}/run`, {
			data: {},
			failOnStatusCode: false,
		})

		const logsResp = await api.request.get(
			`${OC_API}/synchronizations/logs?_limit=50`,
			{ failOnStatusCode: false },
		)
		expect(logsResp.ok(), 'logs endpoint must respond').toBeTruthy()
		const logsBody = await logsResp.json()
		const rows = logsBody.results ?? logsBody.logs ?? logsBody ?? []
		const ours = (Array.isArray(rows) ? rows : []).filter(
			(r: Record<string, unknown>) =>
				String(r.synchronizationId ?? '') === String(syncId),
		)
		expect(
			ours.length,
			'a run-log must be recorded for this synchronization',
		).toBeGreaterThanOrEqual(1)
		// The most recent log must not carry an error message / must be marked done.
		const latest = ours[ours.length - 1]
		const message = String(latest.message ?? '').toLowerCase()
		expect(message).not.toContain('error')
	})

	/*
	 * REGRESSION GUARD for BUG A (run-log write path) + BUG B (hydrate). These are
	 * fixed: the run no longer throws the missing-entity / null-array 500. The run
	 * still cannot complete a transfer because of the SEPARATE legacy-table gap
	 * documented above (it returns 400 "relation oc_openconnector_sources does not
	 * exist"), but it must NEVER 500 again on the run-log/hydrate path. This guard
	 * asserts exactly that: a 500 here is a regression of BUG A/B.
	 */
	test('REGRESSION GUARD: sync run no longer 500s on the run-log/hydrate path (BUG A/B fixed)', async () => {
		const { api, syncId } = fx!
		const resp = await api.request.post(
			`${OC_API}/synchronizations/${syncId}/run`,
			{ data: {}, failOnStatusCode: false },
		)
		expect(
			resp.status(),
			'A 500 means BUG A (orphaned SynchronizationLog write path) or BUG B (hydrate null arrays) has regressed.',
		).not.toBe(500)
	})
})
