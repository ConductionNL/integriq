/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * connectors-inavigator-case-types, REQ-INAV-003 and REQ-INAV-004: a gated
 * synchronization shows on its approval request what an approve would
 * create, change and remove, before anything is written.
 *
 * The first test reads the demo approval request the mock register seeds
 * (two case types to create, one changed with an added status type, one
 * removed, fourteen unchanged) and checks the approval screen shows exactly
 * that. It is found through the app's own approvals API, so it runs as the
 * admin the global setup signs in.
 *
 * The superseded-request scenario needs a real gated run whose source
 * changes between preview and accept. That waits on the i-Navigator import
 * (Task 2, blocked on a checkable interface), so it is declared here and
 * skipped until a gated case type synchronization exists on the instance.
 *
 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-accepting-writes-the-previewed-change-set-or-asks-again-req-inav-004
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE, assertNoAppErrors, trackErrors } from './spec-coverage/_helpers.ts'

interface ApprovalDetail {
	id: string
	status: string
	changeSet?: {
		counts?: Record<string, number>
	} | null
}

/**
 * The first pending approval request the caller may see that carries a
 * change set, or null when the instance has none.
 *
 * @param request The request fixture.
 * @return The approval request detail.
 */
async function pendingRequestWithChangeSet(request: APIRequestContext): Promise<ApprovalDetail | null> {
	const list = await request.get(`${APP_BASE}/api/approvals`, {
		params: { status: 'pending' },
		failOnStatusCode: false,
	})
	expect(list.status()).toBe(200)
	const { results } = await list.json()

	for (const row of results as Array<{ id: string }>) {
		const detail = await request.get(`${APP_BASE}/api/approvals/${row.id}`, { failOnStatusCode: false })
		if (detail.status() !== 200) {
			continue
		}

		const body = (await detail.json()) as ApprovalDetail
		if (body.changeSet) {
			return body
		}
	}

	return null
}

test.describe('i-Navigator case types: the change set at the approval gate', () => {
	test('an administrator sees what a case type re-import would change', async ({ page }) => {
		const approval = await pendingRequestWithChangeSet(page.request)
		test.skip(approval === null, 'no pending approval request with a change set: import the mock register demo data')

		const sink = trackErrors(page)
		await page.goto(`${APP_BASE}/approvals/${approval!.id}`, { waitUntil: 'domcontentloaded' })

		const changeSet = page.getByTestId('change-set')
		await expect(changeSet).toBeVisible({ timeout: 15_000 })

		const counts = approval!.changeSet!.counts ?? {}
		await expect(page.getByTestId('change-set-counts')).toContainText(`${counts.changed ?? 0}`)
		await expect(page.getByTestId('change-set-counts')).toContainText(`${counts.unchanged ?? 0}`)

		// The three kinds are tabs, each labelled with its exact count.
		for (const kind of ['created', 'changed', 'removed']) {
			await expect(page.getByTestId(`change-set-tab-${kind}`)).toContainText(`(${counts[kind] ?? 0})`)
		}

		// A changed case type shows the field that differs, before and after.
		await page.getByTestId('change-set-tab-changed').click()
		await expect(page.getByTestId('change-set-tab-changed')).toHaveAttribute('aria-selected', 'true')
		const panel = page.getByTestId('change-set-panel')
		if ((counts.changed ?? 0) > 0) {
			await expect(panel.locator('table')).toBeVisible()
		}

		// A removed case type is listed under its own tab.
		await page.getByTestId('change-set-tab-removed').click()
		if ((counts.removed ?? 0) > 0) {
			await expect(panel.locator('li').first()).toBeVisible()
		}

		// Opening the preview writes nothing: the request is still pending.
		const after = await page.request.get(`${APP_BASE}/api/approvals/${approval!.id}`)
		expect((await after.json()).status).toBe('pending')

		assertNoAppErrors(sink)
	})

	test('the source changed after the preview', async () => {
		test.skip(
			true,
			'needs a gated i-Navigator case type synchronization whose source changes between preview and accept; waits on Task 2 (no checkable i-Navigator interface yet)',
		)
	})
})
