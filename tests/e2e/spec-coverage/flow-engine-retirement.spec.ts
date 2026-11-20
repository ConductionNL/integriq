/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * retire-integriq-flow-schema, tasks 5.1 and 5.2 — integriq's former step
 * types run on OpenRegister's flow engine, end to end, on real state.
 *
 * Scenarios: openspec/changes/retire-integriq-flow-schema/specs/flow-orchestration/spec.md
 *   - "Every former step type has a node" (the call and mapping halves)
 *   - "An approval step pauses and resumes" (approve edge, reject edge)
 *
 * WHAT EACH TEST PROVES
 * ---------------------
 * 1. A flow drawn as trigger -> openconnector.source-call ->
 *    openconnector.apply-mapping -> openregister.object-write -> end is
 *    preflighted by the live node registry, created, RUN, driven to a terminal
 *    status by executing OpenRegister's FlowRunWorker, and the object it wrote
 *    is read back. The target schema is asserted EMPTY first, so "one object
 *    afterwards" is a measured delta, and the written values come from the
 *    Source's response, so the call and the mapping are both on the path.
 * 2. A flow with an openconnector.approval-request step suspends; the
 *    approval_request it opened is found by its `engineRunUuid`; an anonymous
 *    caller (empty storage state) cannot decide it and the run stays parked;
 *    an approve resumes the run on the approve edge. A second run is rejected
 *    and takes the reject edge. Routing is read from the run log, which names
 *    every node that executed.
 *
 * ENVIRONMENT
 * -----------
 * The instance comes from PLAYWRIGHT_BASE_URL / BASE_URL (support/baseUrl.ts;
 * there is no default). occ comes from OCC_CMD, an answering occ above the
 * checkout, or NC_CONTAINER (support/flowWorker.ts; no default container).
 * The admin credentials the Source authenticates back to Nextcloud with come
 * from NC_ADMIN_USER / NC_ADMIN_PASS.
 *
 * No `@e2e` anchors: this change's scenarios live in a change delta and have
 * no capability spec under openspec/specs yet, so an anchor would resolve to
 * nothing. Add them when the change is archived.
 */
import type { Browser } from '@playwright/test'
import type { ApiClient } from '../workflows/_fixture.ts'

import { expect, test } from '@playwright/test'
import { anonymousRequest } from '../support/anonymous.ts'
import { executeFlowRunWorker, flowRunWorkerJobId } from '../support/flowWorker.ts'
import { createObject, deleteObject, makeApiClient } from '../workflows/_fixture.ts'

/** OpenRegister's API root. */
const OR = '/index.php/apps/openregister/api'

/** Integriq's own API root. */
const OC = '/index.php/apps/integriq/api'

/** The register integriq's own entities live in. */
const OC_REGISTER = 'integriq'

/** Unique per-run marker, so every read is scoped to this run's rows. */
const RUN = `fer-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`

const ADMIN_USER = process.env.NC_ADMIN_USER ?? process.env.ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? process.env.ADMIN_PASS ?? 'admin'

/** Statuses after which the engine will not touch a run again. */
const TERMINAL = ['completed', 'stopped', 'finished', 'failed', 'error', 'dead_letter']

/** Terminal statuses that mean the run finished rather than died. */
const SUCCESSFUL = ['completed', 'stopped', 'finished']

let api: ApiClient

/** Everything created here, removed in afterAll (best effort, shared instance). */
const created = {
	flows: [] as string[],
	objects: [] as Array<[number, number, string]>,
	integriq: [] as Array<[string, string]>,
	registers: [] as number[],
	schemas: [] as number[],
}

/**
 * POST JSON and fail loudly with the body when the server refuses.
 *
 * @param url The URL.
 * @param data The JSON body.
 * @param label What this call is, for the failure message.
 *
 * @return The decoded response body.
 */
async function post(url: string, data: unknown, label: string): Promise<Record<string, any>> {
	const resp = await api.request.post(url, { data, failOnStatusCode: false })
	const text = await resp.text()
	expect(resp.status(), `${label} must succeed — ${resp.status()}: ${text.slice(0, 600)}`).toBeLessThan(300)
	return JSON.parse(text)
}

/**
 * GET JSON and fail loudly with the body when the server refuses.
 *
 * @param url The URL.
 * @param label What this call is, for the failure message.
 *
 * @return The decoded response body.
 */
async function get(url: string, label: string): Promise<Record<string, any>> {
	const resp = await api.request.get(url, { failOnStatusCode: false })
	const text = await resp.text()
	expect(resp.status(), `${label} must succeed — ${resp.status()}: ${text.slice(0, 600)}`).toBeLessThan(300)
	return JSON.parse(text)
}

/**
 * Execute the worker until the run reaches one of the wanted statuses.
 *
 * @param runUuid The run.
 * @param wanted The statuses to stop at.
 * @param label What this run is, for the failure message.
 *
 * @return The run record.
 */
async function driveRun(runUuid: string, wanted: string[], label: string): Promise<Record<string, any>> {
	const jobId = flowRunWorkerJobId()
	let run = await get(`${OR}/flow-runs/${runUuid}`, `reading ${label}`)
	for (let attempt = 0; attempt < 8 && wanted.includes(String(run.status)) === false; attempt++) {
		if (TERMINAL.includes(String(run.status)) === true) {
			break
		}
		executeFlowRunWorker(jobId)
		run = await get(`${OR}/flow-runs/${runUuid}`, `reading ${label}`)
	}
	expect(
		wanted,
		`${label} must reach ${wanted.join('/')}; it is "${run.status}", error ${JSON.stringify(run.error)}, `
			+ `log ${JSON.stringify((run.log ?? []).map((e: Record<string, unknown>) => [e.transition, e.status, e.error]))}`,
	).toContain(String(run.status))
	return run
}

/**
 * The node ids a run executed, in order, and the ones that failed.
 *
 * A failed step can still leave the run `stopped` with no run-level error, so
 * the per-step status is the ground truth.
 *
 * @param run The run record.
 *
 * @return The executed and failed node ids.
 */
function stepsOf(run: Record<string, any>): { executed: string[], failed: string[] } {
	const log = (run.log ?? []) as Array<Record<string, unknown>>
	return {
		executed: log.map((entry) => String(entry.transition)),
		failed: log.filter((entry) => String(entry.status) === 'failed').map((entry) => String(entry.transition)),
	}
}

/**
 * Create a flow after the live node registry has preflighted it.
 *
 * @param doc The flow document.
 * @param label What this flow is, for the failure message.
 *
 * @return The flow id.
 */
async function createFlow(doc: Record<string, unknown>, label: string): Promise<string> {
	const verdict = await post(`${OR}/flow/validate`, doc, `preflighting ${label}`)
	expect(verdict.blocking, `${label} must carry nothing this instance cannot run: ${JSON.stringify(verdict.blocking)}`).toEqual([])
	expect(verdict.valid, `${label} must preflight as valid`).toBe(true)

	const flow = await post(`${OR}/flows`, { ...doc, app: 'openconnector', enabled: true }, `creating ${label}`)
	const id = String(flow.id ?? flow.uuid)
	expect(id, `${label} must persist with an id`).toBeTruthy()
	created.flows.push(id)
	return id
}

/**
 * Start a run of a flow and return its uuid.
 *
 * @param flowId The flow.
 * @param label What this run is, for the failure message.
 *
 * @return The run uuid.
 */
async function startRun(flowId: string, label: string): Promise<string> {
	const queued = await post(`${OR}/flows/${flowId}/run`, {}, `starting ${label}`)
	const uuid = String(queued.uuid ?? '')
	expect(uuid, `${label} must be queued with a uuid`).not.toBe('')
	return uuid
}

/**
 * Create a scratch schema.
 *
 * @param title The schema title.
 * @param properties Its properties.
 *
 * @return The schema id.
 */
async function mkSchema(title: string, properties: Record<string, unknown>): Promise<number> {
	const body = await post(`${OR}/schemas`, { title, description: RUN, properties }, `schema ${title}`)
	created.schemas.push(Number(body.id))
	return Number(body.id)
}

/**
 * The approval_request a suspended run opened.
 *
 * @param runUuid The run.
 *
 * @return The approval_request record.
 */
async function approvalFor(runUuid: string): Promise<Record<string, any>> {
	const body = await get(
		`${OR}/objects/${OC_REGISTER}/approval_request?engineRunUuid=${encodeURIComponent(runUuid)}&_limit=5`,
		'reading the approval request the run opened',
	)
	const rows = ((body.results ?? []) as Array<Record<string, any>>).filter(
		(row) => String(row.engineRunUuid ?? '') === runUuid,
	)
	expect(rows.length, `the suspended run ${runUuid} must have opened exactly one approval request`).toBe(1)
	expect(String(rows[0].status), 'and it must be pending').toBe('pending')
	const id = String(rows[0]['@self']?.id ?? rows[0].id ?? rows[0].uuid)
	created.integriq.push(['approval_request', id])
	return { ...rows[0], id }
}

test.describe.configure({ mode: 'serial' })

test.describe('Integriq steps on OpenRegister\'s flow engine', () => {
	test.beforeAll(async ({ browser, baseURL }) => {
		api = await makeApiClient(browser as Browser, baseURL!)
	})

	test.afterAll(async () => {
		if (api === undefined) {
			return
		}
		for (const flowId of created.flows) {
			await api.request.delete(`${OR}/flows/${flowId}`, { failOnStatusCode: false })
		}
		for (const [register, schema, id] of created.objects) {
			await api.request.delete(`${OR}/objects/${register}/${schema}/${id}`, { failOnStatusCode: false })
		}
		for (const [schema, id] of created.integriq) {
			await deleteObject(api, schema, id)
		}
		for (const register of created.registers) {
			await api.request.delete(`${OR}/registers/${register}`, { failOnStatusCode: false })
		}
		for (const schema of created.schemas) {
			await api.request.delete(`${OR}/schemas/${schema}`, { failOnStatusCode: false })
		}
		await api.dispose()
	})

	test('a flow that calls a Source, maps the response and writes an object runs green on the engine', async () => {
		test.setTimeout(300_000)

		const sourceSchema = await mkSchema(`${RUN}-src`, { name: { type: 'string' }, city: { type: 'string' } })
		const targetSchema = await mkSchema(`${RUN}-tgt`, { label: { type: 'string' }, town: { type: 'string' } })
		const register = await post(
			`${OR}/registers`,
			{ title: `${RUN}-reg`, description: RUN, schemas: [sourceSchema, targetSchema] },
			'register',
		)
		const registerId = Number(register.id)
		created.registers.push(registerId)

		const seeded = await post(
			`${OR}/objects/${registerId}/${sourceSchema}`,
			{ name: `${RUN} origin`, city: 'Zwolle' },
			'seeding the record the Source will serve',
		)
		const seededId = String(seeded['@self']?.id ?? seeded.id ?? seeded.uuid)
		created.objects.push([registerId, sourceSchema, seededId])

		// The Source authenticates back to this Nextcloud explicitly: without
		// credentials the server's own call returns a login page, which is a 200.
		const sourcePath = `/index.php/apps/openregister/api/objects/${registerId}/${sourceSchema}`
		const source = await createObject(api, 'source', {
			name: `${RUN}-source`,
			description: RUN,
			location: `http://localhost${sourcePath}`,
			type: 'json',
			isEnabled: true,
			configuration: {
				headers: {
					Authorization: 'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64'),
					'OCS-APIRequest': 'true',
					Accept: 'application/json',
				},
			},
		})
		const sourceId = String(source.id ?? source.uuid)
		created.integriq.push(['source', sourceId])

		// Which origin the SERVER reaches itself on differs between the dev
		// container (:80) and CI (:8080); accept one only when the seeded
		// record comes back.
		let reachable = ''
		for (const origin of ['http://localhost', 'http://localhost:8080']) {
			if (reachable === '') {
				await api.request.put(`${OR}/objects/${OC_REGISTER}/source/${sourceId}`, {
					data: { ...source, location: `${origin}${sourcePath}` },
					failOnStatusCode: false,
				})
				const probe = await api.request.post(`${OC}/sources/test/${sourceId}`, {
					data: { method: 'GET', endpoint: `/${seededId}` },
					failOnStatusCode: false,
				})
				if (probe.ok() && (await probe.text()).includes(`${RUN} origin`)) {
					reachable = origin
				}
			}
		}
		expect(reachable, 'no candidate origin returned the seeded record to the server').not.toBe('')

		const mapping = await createObject(api, 'mapping', {
			name: `${RUN}-mapping`,
			description: RUN,
			passThrough: false,
			mapping: { label: '{{ name }}', town: '{{ city }}' },
			unset: [],
			cast: {},
		})
		const mappingId = String(mapping.id ?? mapping.uuid)
		created.integriq.push(['mapping', mappingId])

		const before = await get(`${OR}/objects/${registerId}/${targetSchema}?_limit=10`, 'reading the empty target')
		expect(Number(before.total ?? (before.results ?? []).length), 'the target schema starts empty').toBe(0)

		const flowId = await createFlow(
			{
				name: `${RUN} call-map-write`,
				description: RUN,
				nodes: [
					{ id: 'trigger', type: 'openregister.trigger-manual', config: {} },
					{
						id: 'call',
						type: 'openconnector.source-call',
						config: { source: sourceId, endpoint: `/${seededId}`, method: 'GET', output: 'response' },
					},
					{
						id: 'map',
						type: 'openconnector.apply-mapping',
						config: { mapping: mappingId, input: 'response.body', output: 'target' },
					},
					{
						id: 'write',
						type: 'openregister.object-write',
						config: {
							register: String(registerId),
							schema: String(targetSchema),
							operation: 'create',
							fields: { label: '{{target.label}}', town: '{{target.town}}' },
							output: 'written',
						},
					},
					{ id: 'end', type: 'openregister.end', config: {} },
				],
				edges: [
					{ id: 'trigger-call', from: 'trigger', to: 'call' },
					{ id: 'call-map', from: 'call', to: 'map' },
					{ id: 'map-write', from: 'map', to: 'write' },
					{ id: 'write-end', from: 'write', to: 'end' },
				],
			},
			'the call-map-write flow',
		)

		const run = await driveRun(await startRun(flowId, 'the call-map-write run'), SUCCESSFUL, 'the call-map-write run')
		expect(run.error, 'the run must not carry an error').toBeFalsy()
		const steps = stepsOf(run)
		expect(steps.failed, 'no step may fail').toEqual([])
		expect(steps.executed, 'every node runs, in order').toEqual(['trigger', 'call', 'map', 'write', 'end'])

		const after = await get(`${OR}/objects/${registerId}/${targetSchema}?_limit=10`, 'reading the target')
		const rows = (after.results ?? []) as Array<Record<string, any>>
		for (const row of rows) {
			created.objects.push([registerId, targetSchema, String(row['@self']?.id ?? row.id)])
		}
		expect(rows.length, 'the run wrote exactly one object').toBe(1)
		expect(rows[0].label, 'its label came from the Source response through the mapping').toBe(`${RUN} origin`)
		expect(rows[0].town, 'and so did its town').toBe('Zwolle')
	})

	test('an approval step pauses the run, resumes on approve, and takes the reject edge on reject', async () => {
		test.setTimeout(300_000)

		const decision = (verdict: string) => ({ '==': [{ var: 'json.approval.decision' }, verdict] })
		const flowId = await createFlow(
			{
				name: `${RUN} approval`,
				description: RUN,
				nodes: [
					{ id: 'trigger', type: 'openregister.trigger-manual', config: {} },
					{
						id: 'ask',
						type: 'openconnector.approval-request',
						config: {
							question: `${RUN}: may this run continue?`,
							approverGroup: 'admin',
							ttlSeconds: 3600,
							failOnReject: false,
						},
					},
					{ id: 'route', type: 'openregister.switch', config: {} },
					{ id: 'approved', type: 'openregister.end', config: {} },
					{ id: 'rejected', type: 'openregister.end', config: {} },
				],
				edges: [
					{ id: 'trigger-ask', from: 'trigger', to: 'ask' },
					{ id: 'ask-route', from: 'ask', to: 'route' },
					{ id: 'route-approved', from: 'route', to: 'approved', condition: decision('approved') },
					{ id: 'route-rejected', from: 'route', to: 'rejected', condition: decision('rejected') },
				],
			},
			'the approval flow',
		)

		// APPROVE.
		const approveRun = await startRun(flowId, 'the run to approve')
		const parked = await driveRun(approveRun, ['suspended'], 'the run to approve')
		expect(stepsOf(parked).executed, 'the run parks at the approval step, not after it').not.toContain('approved')
		const pending = await approvalFor(approveRun)

		// Someone without a session cannot decide it, and the run stays parked.
		const anonymous = await anonymousRequest({ 'OCS-APIRequest': 'true' })
		try {
			const refused = await anonymous.post(`${OC}/approvals/${pending.id}/approve`, { failOnStatusCode: false })
			expect([401, 403], 'an anonymous approve must be refused').toContain(refused.status())
		} finally {
			await anonymous.dispose()
		}
		const still = await get(`${OR}/flow-runs/${approveRun}`, 'reading the parked run')
		expect(String(still.status), 'the refused approve leaves the run parked').toBe('suspended')

		await post(`${OC}/approvals/${pending.id}/approve`, {}, 'approving the request')
		const approved = await driveRun(approveRun, SUCCESSFUL, 'the approved run')
		const approvedSteps = stepsOf(approved)
		expect(approvedSteps.failed, 'no step of the approved run fails').toEqual([])
		expect(approvedSteps.executed, 'the approved run resumes on the approve edge').toContain('approved')
		expect(approvedSteps.executed, 'and never on the reject edge').not.toContain('rejected')

		// REJECT.
		const rejectRun = await startRun(flowId, 'the run to reject')
		await driveRun(rejectRun, ['suspended'], 'the run to reject')
		const toReject = await approvalFor(rejectRun)
		await post(`${OC}/approvals/${toReject.id}/reject`, { comment: `${RUN} no` }, 'rejecting the request')
		const rejected = await driveRun(rejectRun, SUCCESSFUL, 'the rejected run')
		const rejectedSteps = stepsOf(rejected)
		expect(rejectedSteps.failed, 'a rejection without failOnReject is the flow working, not a failure').toEqual([])
		expect(rejectedSteps.executed, 'the rejected run takes the reject edge').toContain('rejected')
		expect(rejectedSteps.executed, 'and never the approve edge').not.toContain('approved')
	})
})
