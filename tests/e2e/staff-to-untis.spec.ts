/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * connectors-hr-staff-sync on a live instance: the staff-to-untis set
 * imports, a new humaniq employee reaches Untis as a teacher, an approved leave
 * request reaches Untis as a teacher absence, and (once task 4 lands) the
 * nightly run repairs a push missed while Untis was down.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * `StaffToUntisSetTest` runs the set's mappings and conditions over humaniq
 * fixtures. Only an instance shows that the configuration import creates the
 * source, mappings and synchronizations, that saving a humaniq object fires
 * the synchronization, and that the call reaches the Untis paths the set names.
 *
 * WHAT IT NEEDS. The push tests need humaniq installed and a stub of the Untis
 * gateway the instance can reach, given as UNTIS_STUB_URL (the stub answers
 * POST /WebUntis/api/rest/extern/v2/teachers with {"teachers":[{"id":4711}]},
 * POST /WebUntis/api/rest/extern/v1/teacher-absence with {"id":815}, and the
 * token URL with an access token). Without either they skip and say why; the
 * set is never pointed at the real Untis gateway from a test.
 *
 * Everything seeded is deleted again; the imported set stays, disabled.
 *
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-approved-leave-becomes-an-untis-teacher-absence-req-hrs-002
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
 */

import type { APIRequestContext } from '@playwright/test'

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { expect, test } from '@playwright/test'
import { withRequestToken } from './support/requestToken.ts'

/** OpenRegister's objects API. */
const OBJECTS = '/index.php/apps/openregister/api/objects'

/** integriq's API. */
const API = '/index.php/apps/integriq/api'

/** A reachable stub of the Untis gateway, or empty. */
const STUB = (process.env.UNTIS_STUB_URL ?? '').replace(/\/$/, '')

/** A unique suffix for this run's seeds. */
const RUN = Date.now().toString(36)

/** The packaged set, as the configuration import takes it. */
const SET = JSON.parse(readFileSync(resolve(__dirname, '../../lib/Settings/configurations/staff-to-untis.json'), 'utf8'))

/**
 * The objects of one schema in a register, by slug.
 *
 * @param request  The admin's request context.
 * @param register The register.
 * @param schema   The schema.
 * @return The objects keyed by their slug.
 */
async function bySlug(request: APIRequestContext, register: string, schema: string): Promise<Record<string, any>> {
	const res = await request.get(`${OBJECTS}/${register}/${schema}?_limit=500`, { headers: { Accept: 'application/json' } })
	expect(res.ok(), `list ${register}/${schema} -> ${res.status()}`).toBe(true)
	const body = await res.json()
	const rows: any[] = Array.isArray(body) ? body : (body.results ?? [])
	const out: Record<string, any> = {}
	for (const row of rows) {
		const slug = String(row?.slug ?? row?.['@self']?.slug ?? '')
		if (slug !== '') {
			out[slug] = row
		}
	}
	return out
}

/**
 * An object's id.
 *
 * @param row The object.
 * @return Its id.
 */
function idOf(row: any): string {
	return String(row?.id ?? row?.uuid ?? row?.['@self']?.id ?? '')
}

/**
 * Whether humaniq's register answers on this instance.
 *
 * @param request The admin's request context.
 * @return True when humaniq's Employee schema can be listed.
 */
async function humaniqInstalled(request: APIRequestContext): Promise<boolean> {
	const res = await request.get(`${OBJECTS}/humaniq/Employee?_limit=1`, { headers: { Accept: 'application/json' } })
	return res.ok()
}

/**
 * The latest calls integriq logged against an endpoint path.
 *
 * @param request The admin's request context.
 * @param path    The endpoint path the call went to.
 * @return The matching call log rows.
 */
async function callsTo(request: APIRequestContext, path: string): Promise<any[]> {
	const res = await request.get(`${OBJECTS}/integriq/call_log?_limit=50&_order[created]=desc`, { headers: { Accept: 'application/json' } })
	expect(res.ok(), `list call_log -> ${res.status()}`).toBe(true)
	const body = await res.json()
	const rows: any[] = Array.isArray(body) ? body : (body.results ?? [])
	return rows.filter(row => String(row?.request?.url ?? row?.endpoint ?? '').includes(path))
}

/**
 * Point the set's source at the stub and enable both synchronizations' source.
 *
 * @param request The admin's request context.
 * @return Nothing.
 */
async function pointAtStub(request: APIRequestContext): Promise<void> {
	const source = (await bySlug(request, 'integriq', 'source'))['staff-to-untis-source']
	const configuration = source.configuration ?? {}
	configuration.authentication = { ...(configuration.authentication ?? {}), tokenUrl: `${STUB}/WebUntis/api/sso/v3/e2e/token`, client_id: 'e2e' }
	const res = await request.put(`${OBJECTS}/integriq/source/${idOf(source)}`, {
		data: { ...source, location: `${STUB}/WebUntis`, isEnabled: true, configuration },
	})
	expect(res.status(), 'pointing the Untis source at the stub').toBeLessThan(300)
}

test.describe('staff to Untis', () => {
	test.beforeAll(async ({ request }) => {
		const res = await request.post(`${API}/configurations/import`, {
			headers: { ...(await withRequestToken(request)), 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' },
			data: { document: SET },
		})
		expect(res.status(), await res.text()).toBeLessThan(300)
	})

	test('the set imports its source, mappings and synchronizations', async ({ request }) => {
		const sources = await bySlug(request, 'integriq', 'source')
		const mappings = await bySlug(request, 'integriq', 'mapping')
		const synchronizations = await bySlug(request, 'integriq', 'synchronization')

		expect(sources['staff-to-untis-source'], 'the Untis source is imported').toBeTruthy()
		for (const slug of SET.mappings) {
			expect(mappings[slug], `the mapping ${slug} is imported`).toBeTruthy()
			expect(mappings[slug].passThrough, `the mapping ${slug} is an allow-list`).toBe(false)
		}
		for (const slug of SET.synchronizations) {
			expect(synchronizations[slug], `the synchronization ${slug} is imported`).toBeTruthy()
		}
	})

	test('a new teacher is on the timetable the day they start', async ({ request }) => {
		test.skip(STUB === '', 'UNTIS_STUB_URL is not set: no Untis stub to push to')
		test.skip(!(await humaniqInstalled(request)), 'humaniq is not installed on this instance')
		await pointAtStub(request)

		const employee = await request.post(`${OBJECTS}/humaniq/Employee`, {
			data: { lastName: `E2E ${RUN}`, firstName: 'Anna', employeeNumber: `E${RUN}`.slice(0, 20), nextcloudUserId: `e2e-${RUN}`, startDate: '2026-08-01', bsn: '999993653', iban: 'NL91ABNA0417164300' },
		})
		expect(employee.status(), 'seeding an employee').toBeLessThan(300)
		const employeeId = idOf(await employee.json())

		try {
			await expect.poll(async () => (await callsTo(request, '/api/rest/extern/v2/teachers')).length, { timeout: 30_000 }).toBeGreaterThan(0)
			const [call] = await callsTo(request, '/api/rest/extern/v2/teachers')
			const sent = JSON.stringify(call?.request ?? call)
			expect(sent, 'the teacher carries the Nextcloud user id').toContain(`e2e-${RUN}`)
			expect(sent, 'no BSN leaves humaniq').not.toContain('999993653')
			expect(sent, 'no IBAN leaves humaniq').not.toContain('NL91ABNA0417164300')
		} finally {
			await request.delete(`${OBJECTS}/humaniq/Employee/${employeeId}`)
		}
	})

	test('approved leave blocks the timetable', async ({ request }) => {
		test.skip(STUB === '', 'UNTIS_STUB_URL is not set: no Untis stub to push to')
		test.skip(!(await humaniqInstalled(request)), 'humaniq is not installed on this instance')
		await pointAtStub(request)

		const employee = await request.post(`${OBJECTS}/humaniq/Employee`, {
			data: { lastName: `E2E leave ${RUN}`, employeeNumber: `L${RUN}`.slice(0, 20), nextcloudUserId: `e2e-leave-${RUN}`, startDate: '2026-08-01' },
		})
		const employeeId = idOf(await employee.json())
		const leave = await request.post(`${OBJECTS}/humaniq/LeaveRequest`, {
			data: { employeeId, leaveType: 'e2e-private-type', reason: 'e2e private note', startDate: '2027-03-03', endDate: '2027-03-07', status: 'submitted' },
		})
		expect(leave.status(), 'seeding a leave request').toBeLessThan(300)
		const leaveId = idOf(await leave.json())

		try {
			expect(await callsTo(request, '/api/rest/extern/v1/teacher-absence'), 'a submitted request sends nothing').toHaveLength(0)

			const approved = await request.patch(`${OBJECTS}/humaniq/LeaveRequest/${leaveId}`, { data: { status: 'approved' } })
			expect(approved.status(), 'approving the leave request').toBeLessThan(300)

			await expect.poll(async () => (await callsTo(request, '/api/rest/extern/v1/teacher-absence')).length, { timeout: 30_000 }).toBeGreaterThan(0)
			const [call] = await callsTo(request, '/api/rest/extern/v1/teacher-absence')
			const sent = JSON.stringify(call?.request ?? call)
			expect(sent).toContain('2027-03-03T00:00:00')
			expect(sent).toContain('2027-03-07T23:59:00')
			expect(sent, 'no leave type reaches Untis').not.toContain('e2e-private-type')
			expect(sent, 'no note reaches Untis').not.toContain('e2e private note')
		} finally {
			await request.delete(`${OBJECTS}/humaniq/LeaveRequest/${leaveId}`)
			await request.delete(`${OBJECTS}/humaniq/Employee/${employeeId}`)
		}
	})

	test.fixme('a push missed during an outage is repaired by the nightly run', async () => {
		// Waits on task 4 of connectors-hr-staff-sync: the nightly reconciliation
		// job does not exist yet, so there is nothing to run.
	})
})
