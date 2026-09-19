/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md
 *
 * The scenarios the spec sends here are the ones a running instance can
 * answer: the refusal of a policy the engine does not know, the two policies
 * that keep a record the source dropped, and the refusal of a local delete of
 * a record a source owns.
 *
 * The end-and-flag scenarios drive a real synchronisation against a mock-mode
 * source: the fixture is the registry, so the engine, the guards and the
 * policy under test are the production ones. Between the two runs the source's
 * canned body loses a record, which is exactly what "the source stopped
 * carrying it" means.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/index.php/apps/integriq/api'
const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

/**
 * Seed a mock-mode source carrying a fixed set of records.
 *
 * @param request The Playwright request context.
 * @param records The records the source answers with.
 * @param name A name unique to the calling test.
 * @return The source's OpenRegister id.
 */
async function seedSource(
	request: APIRequestContext,
	records: Array<Record<string, unknown>>,
	name: string,
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/source`, {
		failOnStatusCode: false,
		data: {
			name,
			description: 'Seeded by tests/e2e/source-owned-records.spec.ts',
			type: 'api',
			isEnabled: true,
			location: 'https://example.invalid/records',
			configuration: { mock: true, mockResponse: { results: records } },
		},
	})
	expect(resp.status()).toBeLessThan(300)

	return String((await resp.json()).id ?? (await resp.json())['@self']?.id)
}

/**
 * Replace a mock source's canned records.
 *
 * @param request The Playwright request context.
 * @param sourceId The source's id.
 * @param records The records the source now answers with.
 */
async function replaceRecords(
	request: APIRequestContext,
	sourceId: string,
	records: Array<Record<string, unknown>>,
): Promise<void> {
	const resp = await request.put(`${OR_BASE}/source/${sourceId}`, {
		failOnStatusCode: false,
		data: { configuration: { mock: true, mockResponse: { results: records } } },
	})
	expect(resp.status()).toBeLessThan(300)
}

/**
 * Seed a synchronisation with a declared disappearance policy.
 *
 * @param request The Playwright request context.
 * @param sourceId The source it reads.
 * @param policy The disappearance policy it declares.
 * @param name A name unique to the calling test.
 * @return The synchronisation's OpenRegister id.
 */
async function seedSynchronization(
	request: APIRequestContext,
	sourceId: string,
	policy: string,
	name: string,
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/synchronization`, {
		failOnStatusCode: false,
		data: {
			name,
			sourceId,
			sourceType: 'api',
			targetType: 'register/schema',
			targetId: 'integriq/contact',
			sourceConfig: {
				resultsPosition: 'results',
				idPosition: 'id',
				disappearancePolicy: policy,
				ownershipMode: 'source',
			},
		},
	})
	expect(resp.status()).toBeLessThan(300)

	return String((await resp.json()).id ?? (await resp.json())['@self']?.id)
}

test.describe('records owned by an external source', () => {
	// @e2e source-owned-records::a-misspelled-policy-is-refused-at-save
	test('a misspelled disappearance policy is refused, naming the key and the values', async ({
		request,
	}) => {
		const resp = await request.post(`${API_BASE}/ownership/validate-policy`, {
			failOnStatusCode: false,
			data: { sourceConfig: { disappearancePolicy: 'remove' } },
		})

		expect(resp.status()).toBe(400)
		const body = await resp.json()
		expect(body.valid).toBe(false)
		expect(body.key).toBe('disappearancePolicy')
		expect(body.accepted).toEqual(['delete', 'markEnded', 'keepAndFlag'])
		expect(String(body.error)).toContain('not treated as the default')
	})

	// @e2e source-owned-records::a-handler-cannot-quietly-remove-a-brp-person
	test('an anonymous request can delete nothing at all', async ({
		playwright,
	}) => {
		const anonymous = await playwright.request.newContext()
		try {
			const resp = await anonymous.delete(
				`${API_BASE}/ownership/pw-e2e-does-not-exist?schema=contact`,
				{ failOnStatusCode: false },
			)

			expect(resp.status()).not.toBe(200)
			expect([401, 403, 412]).toContain(resp.status())
		} finally {
			await anonymous.dispose()
		}
	})

	// @e2e source-owned-records::an-unknown-object-answers-local-rather-than-failing
	test('an object no synchronisation maintains reads local', async ({
		request,
	}) => {
		const resp = await request.get(
			`${API_BASE}/ownership/pw-e2e-unmaintained-${Date.now()}`,
			{ failOnStatusCode: false },
		)

		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body.mode).toBe('local')
		expect(body.source).toBeNull()
		expect(body.originId).toBeNull()
	})

	// @e2e source-owned-records::an-employee-who-left-the-directory-is-ended-not-erased
	test('under markEnded a record the source dropped is ended, not erased', async ({
		request,
	}) => {
		const stamp = Date.now()
		const sourceId = await seedSource(
			request,
			[{ id: `pw-${stamp}-a`, naam: 'De Vries' }],
			`E2E ended source ${stamp}`,
		)
		const syncId = await seedSynchronization(
			request,
			sourceId,
			'markEnded',
			`E2E markEnded ${stamp}`,
		)

		await request.post(`${API_BASE}/synchronizations/${syncId}/run`, {
			failOnStatusCode: false,
		})

		await replaceRecords(request, sourceId, [])
		const second = await request.post(
			`${API_BASE}/synchronizations/${syncId}/run`,
			{ failOnStatusCode: false },
		)
		expect(second.status()).toBeLessThan(500)

		const contracts = await request.get(
			`${API_BASE}/synchronizations/contracts/${syncId}`,
			{ failOnStatusCode: false },
		)
		expect(contracts.status()).toBe(200)
		const rows = (await contracts.json()).results ?? []
		const contract = rows.find(
			(row: Record<string, unknown>) =>
				String(row.originId) === `pw-${stamp}-a`,
		)
		expect(contract, 'the first run has to have written a contract').toBeTruthy()

		// The record is still there, and it says when the source stopped
		// carrying it.
		const ownership = await request.get(
			`${API_BASE}/ownership/${contract.targetId}`,
			{ failOnStatusCode: false },
		)
		expect(ownership.status()).toBe(200)
		const state = await ownership.json()
		expect(state.mode).toBe('source')
		expect(state.absentAtSource).toBe(true)
		expect(state.endedAt).toBeTruthy()
	})

	// @e2e source-owned-records::a-person-missing-from-the-brp-is-flagged-for-somebody-to-look-at
	test('under keepAndFlag the values are untouched and the record is flagged', async ({
		request,
	}) => {
		const stamp = Date.now()
		const sourceId = await seedSource(
			request,
			[{ id: `pw-${stamp}-b`, naam: 'Jansen' }],
			`E2E flagged source ${stamp}`,
		)
		const syncId = await seedSynchronization(
			request,
			sourceId,
			'keepAndFlag',
			`E2E keepAndFlag ${stamp}`,
		)

		await request.post(`${API_BASE}/synchronizations/${syncId}/run`, {
			failOnStatusCode: false,
		})
		await replaceRecords(request, sourceId, [])
		await request.post(`${API_BASE}/synchronizations/${syncId}/run`, {
			failOnStatusCode: false,
		})

		const contracts = await request.get(
			`${API_BASE}/synchronizations/contracts/${syncId}`,
			{ failOnStatusCode: false },
		)
		const rows = (await contracts.json()).results ?? []
		const contract = rows.find(
			(row: Record<string, unknown>) =>
				String(row.originId) === `pw-${stamp}-b`,
		)
		expect(contract, 'the first run has to have written a contract').toBeTruthy()

		const ownership = await request.get(
			`${API_BASE}/ownership/${contract.targetId}`,
			{ failOnStatusCode: false },
		)
		const state = await ownership.json()
		expect(state.absentAtSource).toBe(true)
		expect(state.endedAt).toBeNull()

		// The values themselves are untouched: keepAndFlag ends nothing.
		const object = await request.get(`${OR_BASE}/contact/${contract.targetId}`, {
			failOnStatusCode: false,
		})
		expect(object.status()).toBe(200)
		expect((await object.json()).naam).toBe('Jansen')
	})

	// @e2e source-owned-records::an-override-is-a-written-statement
	test('a delete of a source-owned record is refused, and an override is a written statement', async ({
		request,
	}) => {
		const stamp = Date.now()
		const sourceId = await seedSource(
			request,
			[{ id: `pw-${stamp}-c`, naam: 'Bakker' }],
			`E2E owned source ${stamp}`,
		)
		const syncId = await seedSynchronization(
			request,
			sourceId,
			'delete',
			`E2E owned ${stamp}`,
		)
		await request.post(`${API_BASE}/synchronizations/${syncId}/run`, {
			failOnStatusCode: false,
		})

		const contracts = await request.get(
			`${API_BASE}/synchronizations/contracts/${syncId}`,
			{ failOnStatusCode: false },
		)
		const rows = (await contracts.json()).results ?? []
		const contract = rows.find(
			(row: Record<string, unknown>) =>
				String(row.originId) === `pw-${stamp}-c`,
		)
		expect(contract, 'the run has to have written a contract').toBeTruthy()

		const refused = await request.delete(
			`${API_BASE}/ownership/${contract.targetId}?schema=contact`,
			{ failOnStatusCode: false },
		)
		expect(refused.status()).toBe(403)
		expect(String((await refused.json()).error)).toContain(`E2E owned ${stamp}`)

		const overridden = await request.delete(
			`${API_BASE}/ownership/${contract.targetId}?schema=contact&reason=${encodeURIComponent('duplicate row, merged by hand')}`,
			{ failOnStatusCode: false },
		)
		expect(overridden.status()).toBe(200)
		const body = await overridden.json()
		expect(body.deleted).toBe(true)
		expect(body.override.reason).toBe('duplicate row, merged by hand')
		expect(body.override.user).toBeTruthy()
		expect(body.override.at).toBeTruthy()
	})
})
