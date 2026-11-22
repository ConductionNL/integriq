/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * e2e for openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md
 *
 * REQ-SDP-002: a signed destruction notice purges the one object a
 * synchronization contract points at, without a full run, and a notice that
 * is not signed with the source's secret is refused before its body is read.
 * The notices are posted from a request context with an EMPTY storage state,
 * as the source system would send them.
 *
 * REQ-SDP-001 (the form half): the synchronization editor warns that purged
 * files cannot be restored as soon as a purge is chosen.
 *
 * The admin context seeds a register and schema of their own, a target
 * object, a source with a webhook secret, two synchronizations and one
 * contract. All of it is removed in afterAll.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { createHmac } from 'node:crypto'
import { APP_BASE } from './spec-coverage/_helpers.ts'
import { anonymousRequest } from './support/anonymous.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_API = '/index.php/apps/openregister/api'
const OR_OBJECTS = `${OR_API}/objects/integriq`
const API = '/index.php/apps/integriq/api'
const STAMP = Date.now()
const SECRET = `e2e-destroy-secret-${STAMP}-${Math.random().toString(36).slice(2)}`
const ORIGIN_ID = `e2e-document-${STAMP}`
const PURGING_SYNC = `e2e purge on destruction ${STAMP}`
const PLAIN_SYNC = `e2e delete on disappearance ${STAMP}`

/** Created paths, removed in afterAll, newest first. */
const cleanup: string[] = []

let purgingSyncId = ''
let targetObjectPath = ''
let contractId = ''
let bystanderPath = ''

/**
 * JSON headers with the admin session's request token.
 *
 * @param request The admin request context.
 * @return The headers.
 */
async function adminHeaders(
	request: APIRequestContext,
): Promise<Record<string, string>> {
	return {
		...(await withRequestToken(request)),
		'Content-Type': 'application/json',
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * Create something as the admin and remember it for cleanup.
 *
 * @param request The admin request context.
 * @param path    The collection path.
 * @param data    The body.
 * @return The created object as answered.
 */
async function create(
	request: APIRequestContext,
	path: string,
	data: Record<string, unknown>,
): Promise<Record<string, any>> {
	const res = await request.post(path, {
		headers: await adminHeaders(request),
		data,
	})
	const text = await res.text()
	expect([200, 201], `create ${path}: ${text.slice(0, 200)}`).toContain(
		res.status(),
	)
	const body = JSON.parse(text)
	const id = String(
		body.uuid ?? body.id ?? body['@self']?.uuid ?? body['@self']?.id ?? '',
	)
	expect(id, `created ${path} must carry an id`).not.toEqual('')
	cleanup.unshift(`${path}/${id}`)
	return { ...body, ref: id }
}

/**
 * The `X-OpenConnector-Signature` header for a body, as the source signs it.
 *
 * @param body   The raw body.
 * @param secret The secret.
 * @return The header value.
 */
function sign(body: string, secret: string): string {
	const ts = Math.floor(Date.now() / 1000)
	const v1 = createHmac('sha256', secret).update(`${ts}.${body}`).digest('hex')
	return `t=${ts},v1=${v1}`
}

/**
 * Post a destruction notice to a synchronization's signed route.
 *
 * @param syncId    The synchronization.
 * @param body      The notice.
 * @param signature The signature header, or null to send none.
 * @return The status and body.
 */
async function notify(
	syncId: string,
	body: Record<string, unknown>,
	signature: string | null,
): Promise<{ status: number; body: any }> {
	const raw = JSON.stringify(body)
	const source = await anonymousRequest()
	const res = await source.post(`${API}/synchronizations/${syncId}/destroyed`, {
		headers: {
			'Content-Type': 'application/json',
			...(signature === null
				? {}
				: { 'X-OpenConnector-Signature': signature }),
		},
		data: raw,
	})
	const answer = { status: res.status(), body: await res.json().catch(() => ({})) }
	await source.dispose()
	return answer
}

/**
 * Open a synchronization's edit modal from the Synchronizations page.
 *
 * @param page The page.
 * @param name The synchronization's name.
 * @return Nothing.
 */
async function openEditor(page: Page, name: string): Promise<void> {
	await page.goto(`${APP_BASE}/synchronizations`, {
		waitUntil: 'domcontentloaded',
	})
	const row = page.getByRole('row', { name: new RegExp(name) })
	await expect(row).toBeVisible({ timeout: 20_000 })
	await row.getByRole('button', { name: /actions/i }).click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()
	await expect(page.getByTestId('sync-editor-disappearance-policy')).toBeVisible({
		timeout: 15_000,
	})
}

test.describe('synchronisation-source-destruction-purge', () => {
	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		const req = page.request

		const schema = await create(req, `${OR_API}/schemas`, {
			title: 'E2E document',
			slug: `e2e-document-${STAMP}`,
			properties: { titel: { type: 'string' } },
		})
		const register = await create(req, `${OR_API}/registers`, {
			title: 'E2E vernietiging',
			slug: `e2e-vernietiging-${STAMP}`,
			schemas: [schema.ref],
		})
		const target = await create(
			req,
			`${OR_API}/objects/e2e-vernietiging-${STAMP}/e2e-document-${STAMP}`,
			{ titel: 'Besluit dat de bron vernietigt' },
		)
		targetObjectPath = `${OR_API}/objects/e2e-vernietiging-${STAMP}/e2e-document-${STAMP}/${target.ref}`
		const bystander = await create(
			req,
			`${OR_API}/objects/e2e-vernietiging-${STAMP}/e2e-document-${STAMP}`,
			{ titel: 'Besluit dat blijft' },
		)
		bystanderPath = `${OR_API}/objects/e2e-vernietiging-${STAMP}/e2e-document-${STAMP}/${bystander.ref}`

		const source = await create(req, `${OR_OBJECTS}/source`, {
			name: `e2e destroying source ${STAMP}`,
			type: 'api',
			location: 'https://example.test/api',
			isEnabled: true,
			configuration: {
				webhookSignature: { scheme: 'openconnector', secret: SECRET },
			},
		})
		const targetId = `${register.id ?? register.ref}/${schema.id ?? schema.ref}`
		const purging = await create(req, `${OR_OBJECTS}/synchronization`, {
			name: PURGING_SYNC,
			sourceId: source.ref,
			sourceType: 'api',
			targetType: 'register/schema',
			targetId,
			sourceConfig: { onSourceDestroyed: 'purge' },
		})
		purgingSyncId = purging.ref
		await create(req, `${OR_OBJECTS}/synchronization`, {
			name: PLAIN_SYNC,
			sourceId: source.ref,
			sourceType: 'api',
			targetType: 'register/schema',
			targetId,
			sourceConfig: { disappearancePolicy: 'delete' },
		})
		const contract = await create(
			req,
			`${OR_OBJECTS}/synchronization_contract`,
			{
				synchronizationId: purgingSyncId,
				originId: ORIGIN_ID,
				targetId: target.ref,
			},
		)
		contractId = contract.ref
		await create(req, `${OR_OBJECTS}/synchronization_contract`, {
			synchronizationId: purgingSyncId,
			originId: `${ORIGIN_ID}-blijft`,
			targetId: bystander.ref,
		})
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		for (const path of cleanup) {
			await page.request
				.delete(path, { headers: await adminHeaders(page.request) })
				.catch(() => null)
		}
		await page.close()
	})

	test('a notice that is not signed with the source secret is refused', async () => {
		const body = { originId: ORIGIN_ID }
		expect((await notify(purgingSyncId, body, null)).status).toBe(401)
		expect(
			(
				await notify(
					purgingSyncId,
					body,
					sign(JSON.stringify(body), 'not-the-secret'),
				)
			).status,
		).toBe(401)
		// An unknown synchronization answers exactly like a bad signature.
		expect(
			(
				await notify(
					`e2e-no-such-sync-${STAMP}`,
					body,
					sign(JSON.stringify(body), SECRET),
				)
			).status,
		).toBe(401)
	})

	// @e2e synchronization-engine::the-dms-destroys-a-document-and-the-publication-follows
	test('a signed notice purges only the object of that record', async ({
		page,
	}) => {
		const unknown = { originId: `${ORIGIN_ID}-unknown` }
		const missing = await notify(
			purgingSyncId,
			unknown,
			sign(JSON.stringify(unknown), SECRET),
		)
		expect(missing.status).toBe(404)

		const body = {
			originId: ORIGIN_ID,
			reference: `e2e-vernietigingsverklaring-${STAMP}`,
		}
		const answer = await notify(
			purgingSyncId,
			body,
			sign(JSON.stringify(body), SECRET),
		)
		expect(answer.status, JSON.stringify(answer.body)).toBe(200)
		expect(answer.body.outcome).toBe('purged')
		expect(answer.body.originId).toBe(ORIGIN_ID)

		// The object is gone, not soft-deleted, and no other object changed.
		const headers = await adminHeaders(page.request)
		expect(
			(await page.request.get(targetObjectPath, { headers })).status(),
		).toBe(404)
		const kept = await page.request.get(bystanderPath, { headers })
		expect(kept.status()).toBe(200)
		expect((await kept.json()).titel).toBe('Besluit dat blijft')
		const deleted = await page.request.get(`${targetObjectPath}?_deleted=true`, {
			headers,
		})
		expect(deleted.status()).not.toBe(200)

		// The contract is kept, pointing at nothing, and says it was purged.
		const contract = await page.request.get(
			`${OR_OBJECTS}/synchronization_contract/${contractId}`,
			{ headers },
		)
		expect(contract.status()).toBe(200)
		const data = await contract.json()
		expect(data.targetId ?? null).toBeNull()
		expect(data.targetLastAction).toBe('purge')

		// A second notice for the same record finds nothing left to remove.
		const again = await notify(
			purgingSyncId,
			body,
			sign(JSON.stringify(body), SECRET),
		)
		expect(again.status).toBe(200)
		expect(again.body.outcome).toBe('nothing_to_remove')
	})

	// @e2e synchronization-engine::an-auditor-finds-what-was-purged
	test('the contract log names the record, the purged object, the notice and the time', async ({
		page,
	}) => {
		const res = await page.request.get(
			`${OR_OBJECTS}/synchronization_contract_log?synchronizationContractId=${contractId}&_limit=50`,
			{ headers: await adminHeaders(page.request) },
		)
		expect(res.status()).toBe(200)
		const body = await res.json()
		const entries = (Array.isArray(body) ? body : (body.results ?? [])) as Array<
			Record<string, any>
		>
		const purge = entries.find((entry) => entry.targetResult === 'purged')
		expect(purge, 'a purge entry in the contract log').toBeTruthy()
		expect(purge?.source?.originId).toBe(ORIGIN_ID)
		expect(purge?.source?.trigger).toBe('destructionNotice')
		expect(purge?.source?.reference).toBe(`e2e-vernietigingsverklaring-${STAMP}`)
		expect(String(purge?.target?.id ?? '')).not.toEqual('')
		const when = purge?.created ?? purge?.['@self']?.created
		expect(when, 'the entry carries its time').toBeTruthy()
	})

	test('the editor warns as soon as a purge is chosen', async ({ page }) => {
		await openEditor(page, PURGING_SYNC)
		await expect(page.getByTestId('sync-editor-purge-warning')).toContainText(
			'Purged files cannot be restored.',
		)
		await page.keyboard.press('Escape')

		await openEditor(page, PLAIN_SYNC)
		await expect(page.getByTestId('sync-editor-purge-warning')).toHaveCount(0)
		await page.getByTestId('sync-editor-disappearance-policy').click()
		await page
			.getByRole('option', {
				name: 'Delete the record and its files permanently',
			})
			.click()
		await expect(page.getByTestId('sync-editor-purge-warning')).toBeVisible()
		await page.keyboard.press('Escape')
	})
})
