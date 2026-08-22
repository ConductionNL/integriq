/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * flow-native-synchronization tasks 2.3 + 2.4 — the DECOMPOSED synchronization,
 * end to end, on real state.
 *
 * WHAT THIS FILE PROVES, AND WHY EACH ASSERTION IS THE ONE IT IS
 * -------------------------------------------------------------
 * `openconnector.synchronization-run` is a black box. The change replaces it
 * with a drawn pipeline of page-level steps, and
 * `SynchronizationFlowGenerator` renders an existing Synchronization into that
 * pipeline:
 *
 *   trigger-manual
 *     -> openconnector.source-paginate   (one item per PAGE)
 *     -> openregister.explode            (page.results -> one item per record)
 *     -> openconnector.apply-mapping
 *     -> openconnector.contract          (create | update | skip | invalid)
 *     -> openregister.set-fields         (targetUuid, defaulted to "")
 *     -> openregister.object-write       (upsert; skipWhen contract.outcome)
 *     -> openregister.set-fields         (syncedId: written uuid, else contract targetId)
 *     -> openconnector.contract-commit
 *     -> openconnector.contract-sweep
 *     -> openregister.end
 *
 * A spec that asserted "the flow page rendered" would pass against every one
 * of the failure modes this pipeline actually has, so nothing here is a render
 * smoke:
 *
 *   1. the generator's document is preflighted by the live node registry, and
 *      the SAME endpoint is shown refusing a neighbouring mistake in the same
 *      test — a `valid: true` from an endpoint never observed saying `false`
 *      is not evidence;
 *   2. the flow is created, RUN, and the queued run is driven to a terminal
 *      status by executing OpenRegister's FlowRunWorker, after which the
 *      TARGET REGISTER and the CONTRACT TABLE are read back. Both are asserted
 *      to be EMPTY first, so "N rows afterwards" is a measured delta rather
 *      than a coincidence;
 *   3. the flow is run a SECOND time and the target objects are diffed by
 *      identity and by `@self.updated`.
 *
 * ⚠️ THE `explode` STEP IS LOAD-BEARING and is asserted by type, not by count.
 * `source-paginate` emits one item per PAGE whose json holds the whole
 * `results` list, while every downstream node is per-ITEM. Without the explode
 * the `contract` node finds no single origin id, decides `invalid`, contracts
 * nothing — and `contract-sweep` then sees zero synchronised target ids and
 * treats every existing object as a deletion candidate. Preflight cannot catch
 * that: it validates VOCABULARY, not the SHAPE of the item a node is handed.
 * See openspec/changes/flow-native-synchronization/reference-flow.md.
 *
 * ⚠️ THE SOURCE MUST ANSWER WITHOUT A NEXTCLOUD SESSION. The neighbouring
 * `workflows/synchronization-workflow.spec.ts` fixture points a Source at the
 * OpenRegister objects endpoint with no credentials; that returns a session
 * page, not the seeded objects (ocon#1190), so it exercises NC's auth
 * middleware rather than the sync engine. The Source here carries an explicit
 * `Authorization: Basic` header in its `configuration`, which CallService
 * merges into the Guzzle request — verified in-container: the response body
 * carries the seeded run marker. The origin is PROBED through
 * `POST /api/sources/test/{id}` and accepted only when the marker comes back,
 * because the server's own view of itself differs between environments
 * (Apache on :80 inside the dev container, `php -S` on :8080 in CI) and a
 * "first candidate that answers 200" probe would pick the wrong one silently.
 *
 * SPEC ANCHORS. The decomposed pipeline's requirement lives in
 * `openspec/changes/flow-native-synchronization/` and has no capability spec
 * of its own yet, so the `@e2e` anchors below name the EXISTING scenarios this
 * file genuinely covers rather than inventing anchors that resolve to nothing.
 */
import type { Browser, Page } from '@playwright/test'
import type { ApiClient } from '../workflows/_fixture'

import { execFileSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'
import { expect, test } from '@playwright/test'
import { createObject, deleteObject, makeApiClient } from '../workflows/_fixture'
import { expectRouteMatched, resolveAppRoot } from '../support/appRoot'

/** OpenRegister's API root — registers, schemas, objects, flows and preflight. */
const OR = '/index.php/apps/openregister/api'

/** Integriq's own API root — used only for the Source connectivity probe. */
const OC = '/index.php/apps/integriq/api'

/** The register/schema pair integriq's own entities live in. */
const OC_REGISTER = 'openconnector'

/** The contract table, as an OpenRegister schema slug. */
const CONTRACT_SCHEMA = 'synchronization_contract'

/** Unique per-run marker so every read can be scoped to this run's rows. */
const RUN = `fns-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`

/** The admin credentials the seeded Source authenticates back to NC with. */
const ADMIN_USER = process.env.NC_ADMIN_USER ?? process.env.ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? process.env.ADMIN_PASS ?? 'admin'

/** The three source records this run synchronises. */
const SEED = [
	{ key: 'alpha', city: 'Amsterdam' },
	{ key: 'beta', city: 'Rotterdam' },
	{ key: 'gamma', city: 'Utrecht' },
]

/* ===========================================================================
 * occ — resolved, never guessed
 * ======================================================================== */

/**
 * How to invoke `occ`, resolved once for the whole file.
 *
 * Two environments, two answers, and neither is a default:
 *
 *  - CI checks this repository out INSIDE the Nextcloud server tree
 *    (`server/apps/integriq`) and serves it with `php -S`, so `occ` is a
 *    real file a few directories up and is invoked directly.
 *  - The docker dev container mounts the checkout from OUTSIDE the server
 *    tree, so no `occ` exists above this file and the command has to cross the
 *    container boundary.
 *
 * Both are POSITIVE detections — an `occ` that ANSWERS, or a container that
 * answers `docker inspect`. When neither resolves, the helper THROWS: a spec
 * that quietly skipped its own subject would be an invisible pass.
 *
 * CORRECTION, found by running this spec from the dev checkout: this comment
 * used to say the file EXISTING made a wrong answer "impossible". It does not.
 * `server/occ` exists on this box and its Nextcloud is not installed, so it
 * answers every app command with `There are no commands defined in the
 * "integriq" namespace` — a present instrument wired to nothing, while a
 * working container sat one branch further down and was never reached. An
 * existence check is not a functional check, so candidate 2 now has to prove
 * it can see the app before it is accepted.
 */
interface OccRunner {
	/** Human-readable description of how occ is reached, for failure messages. */
	how: string
	/** Run occ with these arguments and return stdout. */
	run: (args: string[]) => string
}

let occRunner: OccRunner | null = null

/**
 * Does this `occ` actually reach an installed Nextcloud with the app enabled?
 *
 * An uninstalled Nextcloud still ships a working `occ` — it just exposes a
 * reduced command set and says so. Asking it to list the app's own namespace
 * is the cheapest question whose answer distinguishes "the instrument works"
 * from "the instrument is present", and it is the exact capability every
 * caller here depends on.
 *
 * @param candidate Absolute path to the occ file.
 * @param dir       The directory to run it from.
 *
 * @return Whether it can see the app's commands.
 */
function occAnswers(candidate: string, dir: string): boolean {
	try {
		const listed = execFileSync('php', [candidate, 'list', 'integriq'], {
			encoding: 'utf8',
			cwd: dir,
			maxBuffer: 32 * 1024 * 1024,
			stdio: ['ignore', 'pipe', 'pipe'],
		})
		return listed.includes('integriq:synchronization-to-flow')
	} catch {
		// A non-zero exit is the uninstalled case ("There are no commands
		// defined in the \"integriq\" namespace") and also the
		// no-PHP/broken-config case. All of them mean: not this one.
		return false
	}
}

/**
 * Resolve the occ invocation for this environment.
 *
 * @return The runner.
 */
function occ(): OccRunner {
	if (occRunner !== null) {
		return occRunner
	}

	// 1. An explicit override always wins, so an unusual harness can say so
	//    rather than being guessed at. `OCC_CMD` is a full command prefix,
	//    e.g. `docker exec -u www-data mync php occ`.
	const override = (process.env.OCC_CMD ?? '').trim()
	if (override !== '') {
		const parts = override.split(/\s+/)
		occRunner = {
			how: `OCC_CMD=${override}`,
			run: (args) =>
				execFileSync(parts[0], [...parts.slice(1), ...args], {
					encoding: 'utf8',
					maxBuffer: 32 * 1024 * 1024,
				}),
		}
		return occRunner
	}

	// 2. An `occ` above this checkout that ANSWERS — the CI layout.
	let dir = path.resolve(__dirname, '..', '..', '..')
	for (let up = 0; up < 6; up++) {
		const candidate = path.join(dir, 'occ')
		if (
			fs.existsSync(candidate) === true
			&& occAnswers(candidate, dir) === true
		) {
			occRunner = {
				how: `php ${candidate}`,
				run: (args) =>
					execFileSync('php', [candidate, ...args], {
						encoding: 'utf8',
						cwd: dir,
						maxBuffer: 32 * 1024 * 1024,
					}),
			}
			return occRunner
		}
		dir = path.dirname(dir)
	}

	// 3. A running container — the dev-container layout.
	const container = process.env.NC_CONTAINER ?? 'nextcloud'
	let running = ''
	try {
		running = execFileSync(
			'docker',
			['inspect', '-f', '{{.State.Running}}', container],
			{ encoding: 'utf8' },
		).trim()
	} catch {
		running = ''
	}
	if (running === 'true') {
		occRunner = {
			how: `docker exec -u www-data ${container} php occ`,
			run: (args) =>
				execFileSync(
					'docker',
					['exec', '-u', 'www-data', container, 'php', 'occ', ...args],
					{ encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 },
				),
		}
		return occRunner
	}

	throw new Error(
		'occ could not be resolved: no OCC_CMD, no `occ` file within six levels above '
			+ `${__dirname}, and no running container named "${container}". This spec drives the `
			+ "migration generator and OpenRegister's FlowRunWorker through occ, so there is nothing "
			+ 'left to assert without it. Set OCC_CMD or NC_CONTAINER rather than skipping.',
	)
}

/**
 * Decode occ's JSON output, tolerating anything it printed around it.
 *
 * occ writes global warnings (maintenance mode, a pending upgrade, a missing
 * cron) to the same stream as the payload, and which of them appear depends on
 * the instance rather than on this test. Slicing from the first opening
 * delimiter is not a guess — occ's json output is a single document, so there
 * is exactly one candidate — and it keeps a warning from turning a working
 * command into an unreadable one.
 *
 * @param raw     The raw stdout.
 * @param command What produced it, for the failure message.
 *
 * @return The decoded document.
 */
function parseOccJson(raw: string, command: string): unknown {
	const start = raw.search(/[[{]/)
	const end = Math.max(raw.lastIndexOf(']'), raw.lastIndexOf('}'))
	if (start < 0 || end < start) {
		throw new Error(
			`\`occ ${command}\` produced no JSON document (via ${occ().how}). `
				+ `It said: ${raw.slice(0, 800)}`,
		)
	}
	return JSON.parse(raw.slice(start, end + 1))
}

/**
 * The scheduled job id of OpenRegister's FlowRunWorker.
 *
 * Asked for BY CLASS through occ's json output rather than grepped out of the
 * table: a table row is matched by a substring, and `FlowRunWorker` is also a
 * substring of nothing else today — which is exactly the kind of thing that
 * stops being true without warning.
 *
 * @return The job id.
 */
function flowRunWorkerJobId(): string {
	const raw = occ().run([
		'background-job:list',
		'--class=OCA\\OpenRegister\\Cron\\FlowRunWorker',
		'--output=json',
	])
	const rows = parseOccJson(raw, 'background-job:list') as Array<{
		id: string
		class: string
	}>
	expect(
		rows.length,
		`exactly one FlowRunWorker job must be scheduled (via ${occ().how}); `
			+ `occ reported ${rows.length}. Without it a queued run is never picked up and every `
			+ 'assertion below would be about a run that had not happened.',
	).toBe(1)
	return String(rows[0].id)
}

/**
 * Execute the FlowRunWorker once, ignoring its (chatty) stdout.
 *
 * @param jobId The scheduled job id.
 *
 * @return Nothing.
 */
function executeFlowRunWorker(jobId: string): void {
	occ().run(['background-job:execute', jobId, '--force-execute'])
}

/* ===========================================================================
 * The first-open support dialog
 * ======================================================================== */

/**
 * Retire `CnSupportDialog` for this page BEFORE the first navigation.
 *
 * `useSupportDialog` treats `localStorage['cn-support-dialog-shown:<appId>']
 * === '1'` as "already seen". Every Playwright browser context starts with an
 * empty store, so a fresh context meets the dialog on EVERY run — and it is a
 * full-screen mask with a focus trap over the page CENTRE, which is precisely
 * where the flow canvas lives. It hides nothing a visibility assertion looks
 * at, so `toBeVisible()` keeps passing while clicks and keys are swallowed.
 *
 * The shim patches `Storage.prototype.getItem` on the PREFIX rather than
 * writing one concrete key: the app id is a slug the harness would otherwise
 * have to guess, and a guess that misses fails silently and looks exactly like
 * a suppression that worked. `page.addInitScript` runs before any page script,
 * so the composable's very first read already answers `'1'`.
 *
 * `global-setup.ts` seeds the same flag into `storageState` for the whole run;
 * this is deliberately independent of it, so a regression there surfaces as a
 * failure in the specs that own the canvas rather than everywhere at once.
 *
 * @param page The page about to navigate.
 *
 * @return Nothing.
 */
async function suppressSupportDialog(page: Page): Promise<void> {
	await page.addInitScript(() => {
		const PREFIX = 'cn-support-dialog-shown:'
		const original = Storage.prototype.getItem
		Storage.prototype.getItem = function patched(key: string) {
			if (typeof key === 'string' && key.startsWith(PREFIX) === true) {
				return '1'
			}
			return original.call(this, key)
		}
	})
}

/**
 * Dismiss any overlay that got through anyway, then confirm none is left.
 *
 * The Escape fallback is not belt-and-braces for its own sake: the shim above
 * only covers the localStorage path, and the composable's server mode can
 * re-open the dialog when the preferences endpoint answers non-2xx.
 *
 * @param page The page under test.
 *
 * @return Nothing.
 */
async function dismissLeftoverOverlays(page: Page): Promise<void> {
	const overlay = page.locator(
		'.cn-support-dialog, [data-testid-modal="cn-support-dialog"], #firstrunwizard',
	)
	if ((await overlay.count()) > 0) {
		await page.keyboard.press('Escape')
	}
	await expect(
		overlay,
		'a first-open overlay is still mounted over the canvas: it traps focus and swallows '
			+ 'canvas clicks while every visibility assertion keeps passing',
	).toHaveCount(0)
}

/* ===========================================================================
 * Fixture — built through the API, torn down completely
 * ======================================================================== */

interface Pipeline {
	registerId: number
	sourceSchemaId: number
	targetSchemaId: number
	sourceId: string
	mappingId: string
	syncId: string
}

let api: ApiClient
let pipeline: Pipeline
let flowId = ''
let generated: Record<string, unknown> | null = null
/** The UNMAPPED synchronization and the flow generated from it. */
let unmappedSyncId = ''

/** Target-object `@self.updated` stamps, captured either side of the second run. */
let updatedAfterFirstRun: Record<string, string> = {}
let updatedAfterSecondRun: Record<string, string> = {}

/**
 * POST JSON and fail loudly with the body when the server refuses.
 *
 * @param url   The URL to post to.
 * @param data  The JSON body.
 * @param label What this call is, for the failure message.
 *
 * @return The decoded response body.
 */
async function post(
	url: string,
	data: unknown,
	label: string,
): Promise<Record<string, any>> {
	const resp = await api.request.post(url, { data, failOnStatusCode: false })
	const text = await resp.text()
	expect(
		resp.status(),
		`${label} must succeed — ${resp.status()}: ${text.slice(0, 600)}`,
	).toBeLessThan(300)
	return JSON.parse(text)
}

/**
 * GET JSON and fail loudly with the body when the server refuses.
 *
 * @param url   The URL to read.
 * @param label What this call is, for the failure message.
 *
 * @return The decoded response body.
 */
async function get(url: string, label: string): Promise<Record<string, any>> {
	const resp = await api.request.get(url, { failOnStatusCode: false })
	const text = await resp.text()
	expect(
		resp.status(),
		`${label} must succeed — ${resp.status()}: ${text.slice(0, 600)}`,
	).toBeLessThan(300)
	return JSON.parse(text)
}

/** Run statuses that mean the engine will not touch this run again. */
const TERMINAL_STATUSES = ['completed', 'stopped', 'finished', 'failed', 'error']

/** Terminal statuses that mean it finished the pipeline rather than died in it. */
const SUCCESSFUL_STATUSES = ['completed', 'stopped', 'finished']

/**
 * Drive a QUEUED run to a terminal status by executing the worker.
 *
 * `POST /flows/{id}/run` only enqueues; OpenRegister's FlowRunWorker is what
 * executes a queued run. The worker is therefore driven explicitly instead of
 * waiting for cron — a scheduler this test does not control is not a signal,
 * and a spec that waited on it would be measuring the dev container's cron
 * configuration rather than the flow.
 *
 * @param queued The run record returned by the run endpoint.
 * @param label  What this run is, for the failure message.
 *
 * @return The final run record.
 */
async function driveRunToTerminal(
	queued: Record<string, any>,
	label: string,
): Promise<Record<string, any>> {
	const runUuid = String(queued.uuid)
	const jobId = flowRunWorkerJobId()
	let run = queued
	for (let attempt = 0; attempt < 6; attempt++) {
		if (TERMINAL_STATUSES.includes(String(run.status)) === true) {
			break
		}
		executeFlowRunWorker(jobId)
		run = await get(`${OR}/flow-runs/${runUuid}`, `reading ${label}`)
	}
	expect(
		TERMINAL_STATUSES,
		`${label} must reach a terminal status; it is "${run.status}" with marking `
			+ `${JSON.stringify(run.marking)} and error ${JSON.stringify(run.error)}`,
	).toContain(String(run.status))
	expect(
		SUCCESSFUL_STATUSES,
		`${label} must end successfully — status "${run.status}", error `
			+ `${JSON.stringify(run.error)}`,
	).toContain(String(run.status))
	expect(run.error, `${label} must not carry an error`).toBeFalsy()
	return run
}

/**
 * Every object currently in the target register/schema, keyed by uuid.
 *
 * @return uuid => { name, city, updated }.
 */
async function targetObjects(): Promise<
	Record<string, { name: string; city: string; updated: string }>
> {
	const body = await get(
		`${OR}/objects/${pipeline.registerId}/${pipeline.targetSchemaId}?_limit=200`,
		'reading the target register',
	)
	const out: Record<string, { name: string; city: string; updated: string }> = {}
	for (const row of body.results ?? []) {
		out[String(row['@self']?.id ?? row.id)] = {
			name: String(row.name ?? ''),
			city: String(row.city ?? ''),
			updated: String(row['@self']?.updated ?? ''),
		}
	}
	return out
}

/**
 * Every synchronization contract belonging to THIS run's synchronization.
 *
 * Scoped by `synchronizationId` rather than by a run marker: the contract row
 * carries no name to mark, and the shared instance holds thousands of other
 * rows whose presence must not be able to satisfy an assertion here.
 *
 * @return The contract rows.
 */
async function contracts(): Promise<
	Array<{ id: string; originId: string; originHash: string; targetId: string }>
> {
	const body = await get(
		`${OR}/objects/${OC_REGISTER}/${CONTRACT_SCHEMA}`
			+ `?_limit=200&synchronizationId=${pipeline.syncId}`,
		'reading the contract table',
	)
	return (body.results ?? []).map((row: Record<string, any>) => ({
		id: String(row['@self']?.id ?? row.id),
		originId: String(row.originId ?? ''),
		originHash: String(row.originHash ?? ''),
		targetId: String(row.targetId ?? ''),
	}))
}

/**
 * Build the whole pipeline through the API.
 *
 * Setup is itself an assertion: every step is checked, so a fixture that did
 * not persist fails here rather than turning into a mysterious empty result
 * three tests later.
 *
 * @return The pipeline handles.
 */
async function buildPipeline(): Promise<Pipeline> {
	const mkSchema = async (
		title: string,
		properties: Record<string, unknown>,
	): Promise<number> => {
		const body = await post(
			`${OR}/schemas`,
			{ title, description: RUN, properties },
			`schema ${title}`,
		)
		return Number(body.id)
	}

	// The source schema carries `id` explicitly: it is the ORIGIN id the
	// contract node reads (`idPosition`), and pinning it to a readable value
	// makes every contract row traceable back to the record that produced it.
	const sourceSchemaId = await mkSchema(`${RUN}-src`, {
		id: { type: 'string' },
		name: { type: 'string' },
		city: { type: 'string' },
	})
	const targetSchemaId = await mkSchema(`${RUN}-tgt`, {
		name: { type: 'string' },
		city: { type: 'string' },
	})

	const register = await post(
		`${OR}/registers`,
		{
			title: `${RUN}-reg`,
			description: RUN,
			schemas: [sourceSchemaId, targetSchemaId],
		},
		'register',
	)
	const registerId = Number(register.id)

	for (const seed of SEED) {
		await post(
			`${OR}/objects/${registerId}/${sourceSchemaId}`,
			{
				id: `${RUN}-${seed.key}`,
				name: `${RUN} ${seed.key}`,
				city: seed.city,
			},
			`seeding ${seed.key}`,
		)
	}
	const seeded = await get(
		`${OR}/objects/${registerId}/${sourceSchemaId}?_limit=10`,
		'reading back the seeded source objects',
	)
	expect(seeded.total, 'the source register must hold the seeded records').toBe(
		SEED.length,
	)

	// The Source. Its credentials go in `configuration.headers`, which
	// CallService merges into the Guzzle request — see the file header.
	const authorization =
		'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	const sourcePath = `/index.php/apps/openregister/api/objects/${registerId}/${sourceSchemaId}`
	const source = await createObject(api, 'source', {
		name: `${RUN}-source`,
		description: RUN,
		location: `http://localhost${sourcePath}`,
		type: 'json',
		isEnabled: true,
		configuration: {
			headers: {
				Authorization: authorization,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		},
	})
	const sourceId = String(source.id ?? source.uuid)
	expect(sourceId, 'the source must persist with an id').toBeTruthy()

	// Probe the origin the SERVER can reach, discriminating on the run marker.
	// `http://localhost/…` is the Apache dev container; `http://localhost:8080/…`
	// is CI's `php -S`. A 200 alone does not distinguish them — an NC login
	// page is also a 200 — so the seeded marker is the acceptance test.
	const marker = `${RUN}-${SEED[0].key}`
	let reachable = ''
	for (const origin of ['http://localhost', 'http://localhost:8080']) {
		if (reachable === '') {
			await api.request.put(
				`${OR}/objects/${OC_REGISTER}/source/${sourceId}`,
				{
					data: { ...source, location: `${origin}${sourcePath}` },
					failOnStatusCode: false,
				},
			)
			const probe = await api.request.post(`${OC}/sources/test/${sourceId}`, {
				data: { method: 'GET', endpoint: '' },
				failOnStatusCode: false,
			})
			if (probe.ok() && (await probe.text()).includes(marker)) {
				reachable = origin
			}
		}
	}
	expect(
		reachable,
		'no candidate origin returned the seeded records to the SERVER. The synchronization '
			+ 'cannot fetch anything, so every downstream assertion would be about an empty page. '
			+ 'A 200 is not enough here — a Nextcloud login page is also a 200, which is exactly '
			+ 'how ocon#1190 hid.',
	).not.toBe('')

	// A NON-passThrough mapping: the generator refuses a pass-through mapping,
	// because `object-write` enumerates its `fields` and would silently drop
	// every property the mapping did not name.
	const mapping = await createObject(api, 'mapping', {
		name: `${RUN}-mapping`,
		description: RUN,
		passThrough: false,
		mapping: { name: '{{ name }}', city: '{{ city }}' },
		unset: [],
		cast: {},
	})
	const mappingId = String(mapping.id ?? mapping.uuid)

	const sync = await createObject(api, 'synchronization', {
		name: `${RUN}-sync`,
		description: RUN,
		sourceId,
		sourceType: 'api',
		sourceConfig: { resultsPosition: 'results', idPosition: 'id' },
		sourceTargetMapping: mappingId,
		targetType: 'register/schema',
		targetId: `${registerId}/${targetSchemaId}`,
		conditions: [],
		followUps: [],
		actions: [],
		configurations: [],
	})
	const syncId = String(sync.id ?? sync.uuid)

	return {
		registerId,
		sourceSchemaId,
		targetSchemaId,
		sourceId,
		mappingId,
		syncId,
	}
}

// SERIAL, and not as a style choice. Each test consumes the state the previous
// one produced — the generated document, then the flow created from it, then
// the objects that flow wrote — so a parallel split would run the re-run test
// against a target register nothing had written to yet and report a green
// idempotency result for a synchronization that never ran.
test.describe.configure({ mode: 'serial' })

test.describe('The decomposed synchronization — generated, run, re-run', () => {
	test.beforeAll(async ({ browser, baseURL }) => {
		test.setTimeout(180_000)
		api = await makeApiClient(browser as Browser, baseURL!)
		pipeline = await buildPipeline()
	})

	test.afterAll(async () => {
		if (api === undefined) {
			return
		}
		// Teardown is ordered from the most derived row outwards, and every step
		// is best-effort: the instance is shared, so a half-finished cleanup is
		// worse than a noisy one.
		if (flowId !== '') {
			await api.request.delete(`${OR}/flows/${flowId}`, {
				failOnStatusCode: false,
			})
		}
		if (pipeline !== undefined) {
			for (const contract of await contracts().catch(() => [])) {
				await deleteObject(api, CONTRACT_SCHEMA, contract.id)
			}
			for (const schemaId of [
				pipeline.targetSchemaId,
				pipeline.sourceSchemaId,
			]) {
				const body = await get(
					`${OR}/objects/${pipeline.registerId}/${schemaId}?_limit=200`,
					'listing objects for teardown',
				).catch(() => ({ results: [] }))
				for (const row of body.results ?? []) {
					await api.request.delete(
						`${OR}/objects/${pipeline.registerId}/${schemaId}/`
							+ String(row['@self']?.id ?? row.id),
						{ failOnStatusCode: false },
					)
				}
			}
			// The unmapped synchronization first: it shares the source and target
			// this block is about to remove.
			if (unmappedSyncId !== '') {
				await deleteObject(api, 'synchronization', unmappedSyncId)
			}
			await deleteObject(api, 'synchronization', pipeline.syncId)
			await deleteObject(api, 'mapping', pipeline.mappingId)
			await deleteObject(api, 'source', pipeline.sourceId)
			await api.request.delete(`${OR}/registers/${pipeline.registerId}`, {
				failOnStatusCode: false,
			})
			for (const schemaId of [
				pipeline.sourceSchemaId,
				pipeline.targetSchemaId,
			]) {
				await api.request.delete(`${OR}/schemas/${schemaId}`, {
					failOnStatusCode: false,
				})
			}
		}
		await api.dispose()
	})

	/* -------------------------------------------------------------------
	 * 1. The generated document, and the preflight that judges it
	 * ---------------------------------------------------------------- */

	// @e2e synchronization-engine::mapping-transforms-source-into-target-shape
	test('the generator renders the synchronization as the decomposed pipeline, and the live node registry accepts it — while refusing a neighbouring mistake', async () => {
		test.setTimeout(120_000)

		const raw = occ().run([
			'integriq:synchronization-to-flow',
			pipeline.syncId,
			'--json',
		])
		generated = parseOccJson(raw, 'integriq:synchronization-to-flow') as Record<
			string,
			unknown
		>

		// SHAPE FIRST. A document that preflights green while missing `explode`
		// is the documented failure this change exists to prevent, so the node
		// TYPES are pinned in pipeline order rather than counted.
		const types = (generated.nodes as Array<Record<string, unknown>>).map(
			(node) => String(node.type),
		)
		expect(
			types,
			'the generated flow must be the decomposed pipeline, explode included — '
				+ 'without it every item is a whole PAGE, the contract node decides `invalid`, '
				+ 'and contract-sweep then treats every existing object as a deletion candidate',
		).toEqual([
			'openregister.trigger-manual',
			'openconnector.source-paginate',
			'openregister.explode',
			'openconnector.apply-mapping',
			'openconnector.contract',
			'openregister.set-fields',
			'openregister.object-write',
			'openregister.set-fields',
			'openconnector.contract-commit',
			'openconnector.contract-sweep',
			'openregister.end',
		])
		expect(
			generated.enabled,
			'a generated flow ships DISABLED — "named, disabled until reviewed"',
		).toBe(false)
		expect(
			(generated.edges as unknown[]).length,
			'the pipeline is chained end to end',
		).toBe(types.length - 1)

		// PREFLIGHT. `valid: true` on its own is worth nothing, so the negative
		// control runs against the SAME endpoint in the SAME test.
		const verdict = await post(
			`${OR}/flow/validate`,
			generated,
			'preflighting the generated flow',
		)
		expect(
			verdict.blocking,
			`the generated flow must carry nothing this instance cannot run: `
				+ JSON.stringify(verdict.blocking),
		).toEqual([])
		expect(verdict.valid, 'and the verdict itself is positive').toBe(true)

		// The control: strip the mapping reference the apply-mapping node reads.
		// Same document, same endpoint, one field removed.
		const broken = JSON.parse(JSON.stringify(generated)) as Record<string, any>
		for (const node of broken.nodes) {
			if (node.type === 'openconnector.apply-mapping') {
				delete node.config.mapping
			}
		}
		const refusal = await post(
			`${OR}/flow/validate`,
			broken,
			'preflighting the deliberately broken flow',
		)
		expect(
			refusal.valid,
			'the preflight endpoint must be capable of saying no — a `valid: true` from an '
				+ 'endpoint never observed refusing anything is not evidence that the document is good',
		).toBe(false)
		expect(
			(refusal.blocking as Array<Record<string, unknown>>).map((entry) =>
				String(entry.type),
			),
			'and it must refuse the node whose config was removed, not something else',
		).toContain('openconnector.apply-mapping')
	})

	/* -------------------------------------------------------------------
	 * 2. It runs, and objects + contracts appear
	 * ---------------------------------------------------------------- */

	// @e2e flow-orchestration::graph-editing-if-offered-reuses-the-shared-canvas
	// @e2e flow-orchestration::a-completed-runs-log-reflects-every-step-outcome
	// @e2e synchronization-engine::or-target-write-records-a-contract
	test('the generated flow is drawn on the canvas, runs to a terminal status, and writes both objects and contracts', async ({
		page,
	}) => {
		test.setTimeout(300_000)
		expect(
			generated,
			'the generator test must have produced a document',
		).not.toBeNull()

		// BASELINES. Both are asserted empty first, so the counts after the run
		// are a measured delta and not a number that happened to be right.
		expect(
			Object.keys(await targetObjects()).length,
			'the target register starts empty',
		).toBe(0)
		expect(
			(await contracts()).length,
			'and this synchronization has no contracts yet',
		).toBe(0)

		// The document is created verbatim apart from the two fields a
		// generated flow deliberately does NOT carry: `app` (which scopes it to
		// integriq's Flows list) and `enabled` (false by design — a review
		// gate, which this test is the review of).
		const created = await post(
			`${OR}/flows`,
			{ ...generated, app: 'openconnector', enabled: true },
			'creating the generated flow',
		)
		flowId = String(created.id ?? created.uuid)
		expect(flowId, 'the flow must persist with an id').toBeTruthy()

		// THE CANVAS. Drawn before the run, because task 2.4 asks for the flow
		// in the EDITOR and a run that succeeded would not prove it renders.
		await suppressSupportDialog(page)
		const root = await resolveAppRoot(page)
		await page.goto(`${root}/flows/${flowId}`, {
			waitUntil: 'domcontentloaded',
		})
		await expectRouteMatched(page, `/flows/${flowId}`)
		await dismissLeftoverOverlays(page)

		await expect(
			page
				.locator('.cn-flow-sidebar')
				.getByText(String(generated!.name))
				.first(),
			'the canvas must mount bound to THIS flow, not an empty new-flow shell',
		).toBeVisible({ timeout: 30_000 })

		// Each step type, identified by its per-type modifier class rather than
		// by a total: a count of eleven is satisfied by eleven copies of the
		// wrong node, and `explode` being present is the whole point.
		//
		// `set-fields` appears TWICE and the expected count says so. Both are
		// load-bearing and neither is a duplicate: `target-uuid` resolves the
		// uuid the write matches on (empty for a create, which makes the match
		// MISS rather than throw), and `synced-id` names the target id this
		// pass REACHED — written or skipped — because `contract-sweep` deletes
		// whatever its items do not name and a skipped item has no `written`
		// block at all.
		for (const [type, expected] of [
			['openregister-trigger-manual', 1],
			['openconnector-source-paginate', 1],
			['openregister-explode', 1],
			['openconnector-apply-mapping', 1],
			['openconnector-contract', 1],
			['openregister-set-fields', 2],
			['openregister-object-write', 1],
			['openconnector-contract-commit', 1],
			['openconnector-contract-sweep', 1],
			['openregister-end', 1],
		] as Array<[string, number]>) {
			await expect(
				page.locator(`.cn-flow-detail__node--${type}`),
				`the canvas must draw ${expected} ${type} step(s)`,
			).toHaveCount(expected)
		}

		// ⚠️ toHaveCount, never toBeVisible. An edge is an SVG <path>, and a
		// straight vertical connector has a ZERO-WIDTH bounding box, which
		// Playwright reports as "not visible" even though it renders.
		await expect(
			page.locator('.cn-flow-detail__edge'),
			'every consecutive pair of steps must be connected',
		).toHaveCount((generated!.edges as unknown[]).length)

		// THE RUN.
		const run = await driveRunToTerminal(
			await post(`${OR}/flows/${flowId}/run`, {}, 'starting the first run'),
			'the first run',
		)

		// EVERY step ran. A run that stopped after the trigger is also
		// "terminal", and its log would be short rather than red.
		const executed = (run.log ?? []).map((entry: Record<string, unknown>) =>
			String(entry.transition),
		)
		expect(
			executed,
			'the run log must show every decomposed step executing, in order',
		).toEqual([
			'trigger',
			'fetch',
			'explode',
			'map',
			'contract',
			'target-uuid',
			'write',
			'synced-id',
			'commit',
			'sweep',
			'end',
		])

		// THE OBJECTS.
		const written = await targetObjects()
		expect(
			Object.keys(written).length,
			'one target object per source record',
		).toBe(SEED.length)
		for (const seed of SEED) {
			const match = Object.values(written).find(
				(row) => row.name === `${RUN} ${seed.key}`,
			)
			expect(
				match,
				`the mapped record for ${seed.key} must exist in the target register`,
			).toBeTruthy()
			expect(
				match!.city,
				'and the mapping must have carried its properties across',
			).toBe(seed.city)
		}

		// THE CONTRACTS — the half a "the objects are there" assertion misses.
		const rows = await contracts()
		expect(rows.length, 'one contract per synchronised record').toBe(SEED.length)
		for (const row of rows) {
			expect(
				row.originHash,
				`contract ${row.originId} must carry the origin hash the re-run compares against`,
			).not.toBe('')
			expect(
				row.targetId,
				`contract ${row.originId} must name the object it wrote`,
			).not.toBe('')
			expect(
				Object.keys(written),
				'and that target id must be an object that actually exists',
			).toContain(row.targetId)
		}

		updatedAfterFirstRun = Object.fromEntries(
			Object.entries(written).map(([uuid, row]) => [uuid, row.updated]),
		)
	})

	/* -------------------------------------------------------------------
	 * 3. Re-run (task 2.3)
	 * ---------------------------------------------------------------- */

	// @e2e synchronization-engine::or-target-write-records-a-contract
	test('a second run of the same flow duplicates nothing: the same objects, the same contracts, the same origin hashes', async () => {
		test.setTimeout(300_000)
		expect(flowId, 'the first run must have created the flow').not.toBe('')

		const before = await targetObjects()
		const contractsBefore = await contracts()

		await driveRunToTerminal(
			await post(`${OR}/flows/${flowId}/run`, {}, 'starting the second run'),
			'the second run',
		)

		const after = await targetObjects()
		const contractsAfter = await contracts()

		// NO DUPLICATES. This is the property the contract's targetId buys: the
		// upsert resolves its match, so the second pass updates in place rather
		// than inserting a second copy of everything.
		expect(
			Object.keys(after).sort(),
			'the second run must not create a single new object — the same uuids, and no more',
		).toEqual(Object.keys(before).sort())

		// THE HASHES DID NOT MOVE. Nothing changed at the source, so the
		// contract's stored origin hash must compare equal — the mechanism the
		// zero-write claim rests on.
		const hashes = (
			rows: Array<{ originId: string; originHash: string }>,
		): Record<string, string> =>
			Object.fromEntries(rows.map((row) => [row.originId, row.originHash]))
		expect(
			hashes(contractsAfter),
			'the origin hashes must be unchanged: the source is byte-identical, so a moved '
				+ 'hash would mean the hash itself is unstable and change detection can never work',
		).toEqual(hashes(contractsBefore))
		expect(contractsAfter.length, 'and no contract was duplicated').toBe(
			contractsBefore.length,
		)

		updatedAfterSecondRun = Object.fromEntries(
			Object.entries(after).map(([uuid, row]) => [uuid, row.updated]),
		)
	})

	// @e2e synchronization-engine::or-target-write-records-a-contract
	//
	// Task 2.3's actual acceptance: a second run of an unchanged
	// synchronization writes NOTHING.
	//
	// This was KNOWN FAILING and encoded as `test.fail()` — the body ran, so
	// the day it started passing it reported "expected to fail but passed" and
	// forced its own removal. That day has come; this is the removal.
	//
	// It took two fixes, because there were two independent causes:
	//
	//  1. `ContractMatchNode::isUnchanged()` requires a non-empty `targetHash`
	//     as well as an equal `originHash`, and NOTHING in lib/Flow/ ever wrote
	//     targetHash — `contract-commit` persisted originHash, targetId and the
	//     sourceLast*/targetLast* stamps only. So `skip` was UNREACHABLE and the
	//     contract-hash short-circuit never fired. Fixed by
	//     `contract-commit`'s `targetHashPosition`, which stores
	//     md5(serialize(mapped)) — the legacy recipe, deliberately NOT ksorted,
	//     so a contract written by either engine compares equal.
	//  2. Even once `skip` fired, nothing stopped a skipped item reaching
	//     `object-write`, and `SaveObject::updateObject()` stamps `updated`
	//     unconditionally. Dropping those items was NOT an option: a skipped
	//     item that never reaches the write also never reaches
	//     `contract-sweep`, which deletes whatever its items do not name — so
	//     filtering skips would have DELETED the unchanged objects. Fixed by
	//     `object-write`'s `skipWhen` (pass through, do not drop) plus a
	//     `synced-id` step that gives the sweep an id which survives a skip.
	//
	// The body deliberately touches no network: it compares two maps captured
	// by the tests above. That keeps the only way to fail the assertion itself,
	// so an infrastructure hiccup cannot masquerade as a pass.
	test('task 2.3 — a second run performs ZERO writes: no target object is touched', async () => {
		expect(
			Object.keys(updatedAfterFirstRun).length,
			'the first run must have captured at least one object, or this test proves nothing',
		).toBeGreaterThan(0)

		const moved = Object.keys(updatedAfterFirstRun).filter(
			(uuid) => updatedAfterFirstRun[uuid] !== updatedAfterSecondRun[uuid],
		)
		expect(
			moved,
			're-running an unchanged synchronization must write nothing: these objects were '
				+ 'rewritten with identical content, which costs a full object write, a new '
				+ 'revision and an audit entry per record per run',
		).toEqual([])
	})

	/* -------------------------------------------------------------------
	 * 4. Resumability after a mid-run suspension
	 * ---------------------------------------------------------------- */

	// eslint-disable-next-line playwright/no-skipped-test
	test.skip('a run suspended mid-page resumes at the page cursor rather than refetching', async () => {
		// NOT WRITTEN, and named rather than silently dropped.
		//
		// The suspension path this would exercise is `source-paginate`'s
		// rate-limit branch (FlowSuspension, 60s-3600s bounds). Triggering it
		// deterministically needs the SOURCE to answer 429 with a
		// Retry-After/X-RateLimit-Reset header at a chosen page boundary, and
		// this fixture's source is OpenRegister's own objects endpoint, which
		// has no way to be told to rate-limit a specific request. A test that
		// ran the flow and asserted "it did not suspend" would pass against a
		// suspension path that does not exist at all, which is worse than
		// nothing.
		//
		// WHAT WOULD MAKE IT WRITABLE, in increasing order of cost:
		//  - a controllable stub source in the harness (a tiny HTTP responder
		//    the spec can program to 429 on page 2), reachable from the SERVER
		//    rather than from the browser — the same origin problem
		//    buildPipeline() probes for;
		//  - or an occ/test seam that forces a FlowSuspension on a named run,
		//    so the RESUME half can be asserted without provoking a 429 at all.
		// Both are `flow-native-synchronization` task 1.1 work (the rate-limit
		// suspension moves into source-paginate there), so this is blocked on
		// that task rather than on the harness alone.
	})

	/* -------------------------------------------------------------------
	 * 5. A synchronization with NO mapping — generated, and preflighted
	 *    against the LIVE node registry
	 * ---------------------------------------------------------------- */

	// @e2e synchronization-engine::mapping-transforms-source-into-target-shape
	// @e2e synchronization-engine::or-target-write-records-a-contract
	test('a synchronization with NO mapping generates, and the live registry accepts payloadFrom', async () => {
		test.setTimeout(120_000)

		// THIS TEST EXISTS BECAUSE THE UNIT SUITE CANNOT DO IT.
		// SynchronizationFlowGeneratorTest constructs the five INTEGRIQ
		// nodes and hands them their generated config, so it catches a key that
		// drifts out of THEIR vocabulary. `openregister.object-write` is not
		// among them, so a `payloadFrom` that the deployed node does not read
		// would pass every unit test and fail at flow-save time on a real
		// instance. Only the live preflight closes that, which is why this
		// asserts against the instance rather than a double.
		const unmapped = await createObject(api, 'synchronization', {
			name: `${RUN}-unmapped`,
			description: RUN,
			sourceId: pipeline.sourceId,
			sourceType: 'api',
			sourceConfig: { resultsPosition: 'results', idPosition: 'id' },
			// No sourceTargetMapping. Before openregister #2684 + integriq #1334
			// this was refused outright: "sourceTargetMapping is not set". That
			// one refusal accounted for 98 of 99 measured refusals.
			targetType: 'register/schema',
			targetId: `${pipeline.registerId}/${pipeline.targetSchemaId}`,
			conditions: [],
			followUps: [],
			actions: [],
			configurations: [],
		})
		unmappedSyncId = String(unmapped.id ?? unmapped.uuid)
		expect(
			unmappedSyncId,
			'the unmapped synchronization must persist',
		).toBeTruthy()

		const doc = parseOccJson(
			occ().run([
				'integriq:synchronization-to-flow',
				unmappedSyncId,
				'--json',
			]),
			'integriq:synchronization-to-flow (unmapped)',
		) as Record<string, unknown>

		const nodes = doc.nodes as Array<Record<string, unknown>>
		const types = nodes.map((node) => String(node.type))

		// No mapping means no map STEP. `edgesFor()` chains on array order, so
		// the pipeline has to close around the gap rather than leave a dangling
		// reference to a node that was never emitted.
		expect(
			types,
			'an unmapped synchronization generates the same pipeline WITHOUT apply-mapping',
		).toEqual([
			'openregister.trigger-manual',
			'openconnector.source-paginate',
			'openregister.explode',
			'openconnector.contract',
			'openregister.set-fields',
			'openregister.object-write',
			'openregister.set-fields',
			'openconnector.contract-commit',
			'openconnector.contract-sweep',
			'openregister.end',
		])

		const edges = doc.edges as Array<Record<string, unknown>>
		const fromExplode = edges.filter((edge) => String(edge.from) === 'explode')
		expect(
			fromExplode.map((edge) => String(edge.to)),
			'explode must chain straight to contract with no map step between',
		).toEqual(['contract'])

		const nodeById = (id: string): Record<string, unknown> =>
			nodes.find((node) => String(node.id) === id) as Record<string, unknown>
		const writeConfig = nodeById('write').config as Record<string, unknown>

		expect(
			writeConfig.payloadFrom,
			'the write step writes the SOURCE object whole',
		).toBe('source')
		expect(
			writeConfig.fields,
			'`fields` and `payloadFrom` are alternatives — object-write refuses both together',
		).toBeUndefined()

		// The commit must hash what was WRITTEN. Hashing a `target` that never
		// existed would leave targetHash empty and make the skip test
		// unreachable — the defect task 2.3 already had to fix once.
		const commitConfig = nodeById('commit').config as Record<string, unknown>
		expect(
			commitConfig.targetHashPosition,
			'with no mapping the commit hashes the source, not a target that was never produced',
		).toBe('source')

		// THE ASSERTION THIS TEST IS FOR: the LIVE registry, not a double.
		const verdict = await post(
			`${OR}/flow/validate`,
			doc,
			'preflighting the unmapped generated flow',
		)
		expect(
			verdict.blocking,
			'the deployed object-write must READ payloadFrom — a node that ignores a '
				+ 'configured key is a step that reports success having done nothing: '
				+ JSON.stringify(verdict.blocking),
		).toEqual([])
		expect(verdict.valid, 'and the verdict itself is positive').toBe(true)

		// NEGATIVE CONTROL, same endpoint, same document, one key renamed. Without
		// it a green verdict could mean the preflight simply accepts anything.
		const bogus = JSON.parse(JSON.stringify(doc)) as Record<string, unknown>
		const bogusWrite = (bogus.nodes as Array<Record<string, unknown>>).find(
			(node) => String(node.id) === 'write',
		) as Record<string, unknown>
		const bogusConfig = bogusWrite.config as Record<string, unknown>
		delete bogusConfig.payloadFrom
		bogusConfig.payloadFromm = 'source'

		const refused = await post(
			`${OR}/flow/validate`,
			bogus,
			'preflighting a deliberately misspelled payloadFrom',
		)
		expect(
			refused.valid,
			'a misspelled config key must be REFUSED, or the positive verdict above proves nothing',
		).toBe(false)
	})

	/* -------------------------------------------------------------------
	 * 6. A run-log entry links back to the synchronization it acted on
	 * ---------------------------------------------------------------- */

	// @e2e synchronization-engine::or-target-write-records-a-contract
	test('the deployed nodes offer a run-log link back to the synchronization', async () => {
		// Unit tests prove the trait; only the live registry can prove the
		// DEPLOYED node is registered as an IFlowNodeLogActions. A node whose
		// interface is missing returns [] from FlowNodeRegistry rather than
		// erroring, so the failure mode here is a silently linkless log — the
		// same shape as a step that reports success having done nothing.
		const linked = [
			'openconnector.source-paginate',
			'openconnector.contract-commit',
			'openconnector.contract-sweep',
			'openconnector.synchronization-run',
		]

		for (const type of linked) {
			const verdict = await post(
				`${OR}/flow/log-actions`,
				{
					entry: {
						type,
						// Deliberately NOT the default output key: the payload
						// sits wherever the step's config put it, and a log
						// entry carries no config, so a node that addressed a
						// fixed path would pass a fixture and fail in a real
						// run whose author renamed the key.
						output: {
							items: [
								{
									json: {
										renamedByTheAuthor: {
											synchronization: pipeline.syncId,
										},
									},
								},
							],
						},
					},
				},
				`log actions for ${type}`,
			)

			expect(
				verdict.results,
				`${type} must offer exactly one link back to the synchronization`,
			).toHaveLength(1)
			expect(String(verdict.results[0].href)).toContain(
				`#/synchronizations/${encodeURIComponent(pipeline.syncId)}`,
			)
		}

		// NEGATIVE CONTROL. Same endpoint, same node, an entry with nothing to
		// point at. Without it, "one link came back" could just mean the
		// endpoint always answers.
		const empty = await post(
			`${OR}/flow/log-actions`,
			{
				entry: {
					type: 'openconnector.contract-sweep',
					output: { items: [{ json: { count: 3 } }] },
				},
			},
			'log actions for an entry with no reference',
		)
		expect(
			empty.results,
			'an entry with nothing to point at must earn NO link, not a link to the list page',
		).toEqual([])

		// And the two nodes that stamp no reference stay silent by design.
		for (const type of [
			'openconnector.apply-mapping',
			'openconnector.contract',
		]) {
			const none = await post(
				`${OR}/flow/log-actions`,
				{
					entry: {
						type,
						output: {
							items: [{ json: { synchronization: pipeline.syncId } }],
						},
					},
				},
				`log actions for ${type}`,
			)
			expect(
				none.results,
				`${type} declares no log actions — it stamps no reference into what it emits`,
			).toEqual([])
		}
	})
})
