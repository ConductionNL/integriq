/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/archive/2026-07-15-hitl-approval-rule-action/specs/approval-workflow/spec.md
 * (REQ-007 Pending Approvals UI; the rule editor's `approval` action form).
 * Written for openspec/changes/approvals-verification-pack tasks 3 and 4.
 *
 * What an approver does here: open the Approvals list, open a pending
 * request, approve it with a comment so it leaves the pending list, and
 * reject another with a comment, which is recorded. Reject stays disabled
 * until a comment is typed. An administrator then picks `approval` as a
 * rule's action type and sees the approver group, time to live and the two
 * fallback outcomes, which persist on save.
 *
 * The requests are staged as the approval_request objects the rule pipeline
 * writes when it suspends, because suspending a real call needs an endpoint
 * with an approval rule and a caller. The suspension, the resume, the timeout
 * sweep and the two-layer authorization carry `@e2e exclude` in the spec and
 * are covered by PHPUnit. The admin the global setup logs in may act on any
 * request (the admin break-glass of REQ-006), so the approver group is a name
 * of this run's own.
 *
 * Everything is seeded through OpenRegister's object API under this run's
 * marker and deleted afterwards.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { appDialog } from '../support/dialogs.ts'
import { APP_BASE } from './_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-appr-${Date.now().toString(36)}`

const APPROVE_GROUP = `approvers-a-${RUN}`
const REJECT_GROUP = `approvers-r-${RUN}`
const RULE_NAME = `E2E approval rule ${RUN}`

const ids: Record<string, string> = {}
const cleanup: Array<{ schema: string, id: string }> = []

/**
 * Create one object through OpenRegister's object API and remember it for cleanup.
 *
 * @param request The request fixture.
 * @param schema  The schema slug.
 * @param data    The object.
 * @return The object's OpenRegister id.
 */
async function seedObject(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, { failOnStatusCode: false, headers: HEADERS, data })
	expect([200, 201], `seeding a ${schema}: ${await resp.text()}`).toContain(resp.status())
	const body = await resp.json()
	const id = body.id ?? body.uuid ?? body['@self']?.id
	expect(typeof id, `the seeded ${schema} has an id`).toBe('string')
	cleanup.push({ schema, id: id as string })

	return id as string
}

/**
 * Read one approval_request back.
 *
 * @param request The request fixture.
 * @param id      The request's id.
 * @return The stored object.
 */
async function readApproval(request: APIRequestContext, id: string): Promise<Record<string, unknown>> {
	const resp = await request.get(`${OR_BASE}/approval_request/${id}`, { failOnStatusCode: false, headers: HEADERS })
	expect(resp.status()).toBe(200)

	return resp.json()
}

/**
 * Open one request's detail page from the pending list.
 *
 * @param page  The page.
 * @param group The request's approver group, unique to it in this run.
 */
async function openFromPendingList(page: Page, group: string): Promise<void> {
	await page.goto(`${APP_BASE}/approvals`, { waitUntil: 'domcontentloaded' })
	const table = page.getByTestId('approvals-table')
	await expect(table).toBeVisible({ timeout: 30_000 })
	const row = table.getByRole('row').filter({ hasText: group })
	await expect(row).toContainText('pending')
	await row.getByRole('button', { name: 'Open' }).click()
	await expect(page.getByRole('heading', { name: 'Approval request' })).toBeVisible({ timeout: 15_000 })
}

/**
 * A pending request as the rule pipeline stores it on suspension.
 *
 * @param group The approver group.
 * @return The object.
 */
function pendingRequest(group: string): Record<string, unknown> {
	return {
		status: 'pending',
		approverGroup: group,
		timing: 'before',
		resumeOrder: 20,
		onReject: 'error',
		onTimeout: 'dead_letter',
		requesterUserId: 'admin',
		snapshot: { method: 'POST', path: `/${RUN}/verzoeken`, body: { onderwerp: RUN } },
		createdAt: new Date().toISOString(),
		expiresAt: new Date(Date.now() + 86_400_000).toISOString(),
	}
}

test.describe('approval-workflow: the Pending Approvals pages', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		ids.approve = await seedObject(request, 'approval_request', pendingRequest(APPROVE_GROUP))
		ids.reject = await seedObject(request, 'approval_request', pendingRequest(REJECT_GROUP))
	})

	test.afterAll(async ({ request }) => {
		for (const { schema, id } of cleanup.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}
	})

	// @e2e approval-workflow::approvals-list-page-mounts-and-shows-content
	test('the Approvals list shows the pending requests', async ({ page }) => {
		await page.goto(`${APP_BASE}/approvals`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByRole('heading', { name: 'Approvals' })).toBeVisible({ timeout: 30_000 })
		const table = page.getByTestId('approvals-table')
		await expect(table.getByRole('row').filter({ hasText: APPROVE_GROUP })).toBeVisible()
		await expect(table.getByRole('row').filter({ hasText: REJECT_GROUP })).toBeVisible()
	})

	// @e2e approval-workflow::approve-action-is-available-from-the-detail-page
	test('an approver approves with a comment and the request leaves the pending list', async ({ page, request }) => {
		await openFromPendingList(page, APPROVE_GROUP)
		await expect(page.getByTestId('reject-button'), 'reject needs a comment first').toBeDisabled()

		await page.getByRole('textbox').fill(`Checked by ${RUN}`)
		await page.getByRole('button', { name: 'Approve', exact: true }).click()

		await expect.poll(async () => (await readApproval(request, ids.approve)).status, { timeout: 15_000 }).not.toBe('pending')

		await page.goto(`${APP_BASE}/approvals`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByTestId('approvals-table').or(page.getByTestId('empty-state'))).toBeVisible({ timeout: 30_000 })
		await expect(page.getByRole('row').filter({ hasText: APPROVE_GROUP }), 'gone from the pending list').toHaveCount(0)
	})

	test('an approver rejects with a comment, which is recorded', async ({ page, request }) => {
		await openFromPendingList(page, REJECT_GROUP)
		const reason = `Missing legal basis field (${RUN})`
		await page.getByRole('textbox').fill(reason)
		await expect(page.getByTestId('reject-button')).toBeEnabled()
		await page.getByTestId('reject-button').click()

		await expect.poll(async () => (await readApproval(request, ids.reject)).status, { timeout: 15_000 }).toBe('rejected')
		const stored = await readApproval(request, ids.reject)
		expect(stored.comment).toBe(reason)
		expect(stored.approverUserId, 'who rejected it is recorded').toBeTruthy()
		expect(stored.rejectedAt, 'when it was rejected is recorded').toBeTruthy()
	})
})

test.describe('approval-workflow: the rule editor\'s approval action', () => {
	test.afterAll(async ({ request }) => {
		const resp = await request.get(`${OR_BASE}/rule`, { failOnStatusCode: false, headers: HEADERS, params: { _search: RULE_NAME } })
		const results = resp.ok() ? ((await resp.json()).results ?? []) : []
		for (const rule of results as Array<Record<string, unknown>>) {
			if (rule.name === RULE_NAME) {
				const id = (rule.id ?? (rule['@self'] as Record<string, unknown> | undefined)?.id) as string
				await request.delete(`${OR_BASE}/rule/${id}`, { failOnStatusCode: false, headers: HEADERS })
			}
		}
	})

	test('picking approval shows its fields, and they persist on save', async ({ page, request }) => {
		await page.goto(`${APP_BASE}/rules`, { waitUntil: 'domcontentloaded' })
		await page.getByRole('button', { name: 'Add Rule' }).click()
		const dialog = appDialog(page)
		await expect(dialog).toBeVisible({ timeout: 15_000 })

		await dialog.getByLabel('Name').first().fill(RULE_NAME)
		await dialog.getByRole('combobox', { name: 'Type' }).click()
		await page.getByRole('option', { name: 'Approval', exact: true }).click()

		await expect(dialog.getByRole('combobox', { name: 'Approver group' })).toBeVisible({ timeout: 10_000 })
		await expect(dialog.getByLabel('Time to live (seconds, default 86400)')).toBeVisible()
		await expect(dialog.getByRole('combobox', { name: 'On reject' })).toBeVisible()
		await expect(dialog.getByRole('combobox', { name: 'On timeout' })).toBeVisible()

		await dialog.getByRole('combobox', { name: 'Approver group' }).click()
		await page.getByRole('option', { name: /^admin$/i }).first().click()
		await dialog.getByLabel('Time to live (seconds, default 86400)').fill('600')
		await dialog.getByRole('button', { name: /save|create/i }).first().click()

		await expect.poll(async () => {
			const resp = await request.get(`${OR_BASE}/rule`, { failOnStatusCode: false, headers: HEADERS, params: { _search: RULE_NAME } })
			const results = resp.ok() ? ((await resp.json()).results ?? []) : []
			const rule = (results as Array<Record<string, unknown>>).find((candidate) => candidate.name === RULE_NAME)
			const approval = ((rule?.configuration as Record<string, unknown> | undefined)?.approval ?? {}) as Record<string, unknown>

			return [rule?.type, approval.approverGroup, approval.ttlSeconds]
		}, { timeout: 15_000 }).toEqual(['approval', 'admin', 600])
	})
})
