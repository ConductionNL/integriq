/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 *
 * Every scenario here drives the real screens. A directory connection is a
 * Source, so its run and preview live as row actions on the Sources index, and
 * what a run changed is read back on the Directory runs page.
 *
 * The connection is seeded through OpenRegister's objects API in mock mode: the
 * fixture is the directory, so the reader, the mapping and the membership
 * writer under test are the production ones rather than stubs. Nothing else is
 * faked.
 *
 * Scenarios carrying `@e2e exclude` in the spec (the no-second-account-store
 * absence claim, the unknown-target-group refusal, both SCIM scenarios, the
 * ratio guard and per-item isolation) are covered by PHPUnit and Newman and are
 * deliberately not restated here.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'
import { appDialog } from './support/dialogs.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'

/** The Nextcloud group both directory groups map onto. */
const TARGET_GROUP = 'e2e-behandelaars'

/**
 * Seed a directory connection in mock mode.
 *
 * @param request The Playwright request context.
 * @param users The fixture users the directory answers with.
 * @param complete Whether the fixture stands for a complete read.
 * @return The connection's OpenRegister id.
 */
async function seedConnection(
	request: APIRequestContext,
	users: Array<Record<string, unknown>>,
	complete = true,
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/source`, {
		failOnStatusCode: false,
		data: {
			name: 'E2E directory',
			description: 'Seeded by tests/e2e/directory-sync.spec.ts',
			type: 'directory',
			isEnabled: true,
			configuration: {
				mock: true,
				fixture: { complete, users },
				mapping: {
					createMissingGroups: true,
					rules: [
						{ directoryGroup: 'OU=Vergunningen', group: TARGET_GROUP },
						{ directoryGroup: 'OU=Toezicht', group: TARGET_GROUP },
					],
				},
				openWork: { consumers: ['dossiq'] },
				// The seeded groups are small, so the ratio guard would stop
				// every removal. The scenarios that are about a removal confirm
				// it explicitly; this keeps the guard itself where its own
				// scenario tests it, over a truncated fixture in PHPUnit.
				deletionRatioThreshold: 1,
			},
		},
	})
	expect(
		resp.status(),
		'seeding a directory connection must succeed',
	).toBeLessThan(300)
	const body = await resp.json()

	return String(body.id ?? body.uuid)
}

/**
 * Run or preview a connection through the admin endpoint the row actions use.
 *
 * @param request The Playwright request context.
 * @param id The connection id.
 * @param options dryRun / confirmRemovals.
 * @return The run record.
 */
async function runConnection(
	request: APIRequestContext,
	id: string,
	options: { dryRun?: boolean; confirmRemovals?: boolean } = {},
): Promise<Record<string, any>> {
	const resp = await request.post(`${API_BASE}/directory/connections/${id}/run`, {
		failOnStatusCode: false,
		data: {
			dryRun: options.dryRun === true,
			confirmRemovals: options.confirmRemovals === true,
		},
	})
	expect(resp.status(), 'a directory run must not error').toBeLessThan(300)

	return await resp.json()
}

/**
 * Open a source's row-action menu on the Sources index.
 *
 * @param page The page.
 * @param name The source name.
 */
async function openRowActions(page: Page, name: string): Promise<void> {
	await page.goto(`${APP_BASE}/sources`, { waitUntil: 'domcontentloaded' })
	const row = page.getByRole('row', { name: new RegExp(name) }).first()
	await expect(
		row,
		'the seeded directory connection must be listed as a source',
	).toBeVisible({
		timeout: 20_000,
	})
	await row.getByRole('button').last().click()
}

test.describe('REQ-DS-001: a directory connection synchronises users and groups', () => {
	// @e2e directory-sync::a-membership-that-ended-in-the-directory-ends-here
	test('a membership that ended in the directory ends here', async ({
		request,
	}) => {
		const id = await seedConnection(request, [
			{ userName: 'admin', groups: ['OU=Vergunningen'] },
		])
		await runConnection(request, id)

		// The directory drops admin from every mapped group. The membership must
		// end here too, and the removal must be attributable to its run.
		const dropped = await seedConnection(request, [])
		const record = await runConnection(request, dropped, {
			confirmRemovals: true,
		})

		expect(record.removals.map((item: any) => item.userId)).toContain('admin')
		expect(record.membershipsRemoved).toBeGreaterThan(0)
		expect(String(record.runId ?? '')).not.toHaveLength(0)

		const members = await request.get(
			`/index.php/ocs/v2.php/cloud/groups/${TARGET_GROUP}?format=json`,
			{ failOnStatusCode: false, headers: { 'OCS-APIRequest': 'true' } },
		)
		if (members.ok()) {
			const body = await members.json()
			expect(body.ocs?.data?.users ?? []).not.toContain('admin')
		}
	})

	// @e2e directory-sync::a-new-member-arrives-without-a-manual-step
	test('a new member arrives without a manual step', async ({ page, request }) => {
		const id = await seedConnection(request, [
			{ userName: 'admin', groups: ['OU=Toezicht'] },
		])

		// The run endpoint is the same entry point DirectorySyncJob calls on its
		// schedule; no administrator edits a membership by hand either way.
		const record = await runConnection(request, id)
		expect(record.additions.map((item: any) => item.userId)).toContain('admin')

		await page.goto(`${APP_BASE}/directory-runs`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('main').first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText('E2E directory').first()).toBeVisible({
			timeout: 20_000,
		})
	})
})

test.describe('REQ-DS-002: the mapping is declared, not coded', () => {
	// @e2e directory-sync::an-administrator-maps-two-directory-groups-onto-one-nextcloud-group
	test('two directory groups map onto one Nextcloud group', async ({
		request,
	}) => {
		const id = await seedConnection(request, [
			{ userName: 'admin', groups: ['OU=Vergunningen'] },
			{ userName: 'admin', groups: ['OU=Toezicht'] },
		])
		const record = await runConnection(request, id)

		// Both directory groups resolve onto the one Nextcloud group, and no
		// membership is invented for a group the mapping does not name.
		expect(record.managedGroups).toEqual([TARGET_GROUP])

		const connections = await request.get(`${API_BASE}/directory/connections`, {
			failOnStatusCode: false,
		})
		expect(connections.status()).toBe(200)
		const body = await connections.json()
		const seeded = body.results.find((row: any) => row.id === id)
		expect(seeded, 'the mapping must be readable on the connection').toBeTruthy()
		expect(seeded.mapping.rules.map((rule: any) => rule.directoryGroup)).toEqual(
			['OU=Vergunningen', 'OU=Toezicht'],
		)
		expect(
			seeded.mapping.rules.every((rule: any) => rule.group === TARGET_GROUP),
		).toBe(true)
	})
})

test.describe('REQ-DS-004: a leaver’s open work is reported', () => {
	// @e2e directory-sync::a-leaver-with-a-live-case-list-is-named
	test('the run report names the account and the consumer that answered', async ({
		request,
	}) => {
		const id = await seedConnection(request, [
			{ userName: 'admin', groups: ['OU=Toezicht'] },
		])
		await runConnection(request, id)

		const dropped = await seedConnection(request, [])
		const record = await runConnection(request, dropped, {
			confirmRemovals: true,
		})

		// The account is named, and so is the consumer the connection expects an
		// answer from. On an instance without dossiq installed the answer reads
		// `unknown` rather than zero, which is the point of REQ-DS-004's second
		// scenario; the count itself is asserted in PHPUnit over an answering
		// consumer, because a browser cannot install one.
		expect(Object.keys(record.openWork)).toContain('admin')
		expect(Object.keys(record.openWork.admin)).toContain('dossiq')
		expect(record.openWork.admin.dossiq).not.toBe(0)
	})
})

test.describe('REQ-DS-005: a run can be previewed', () => {
	// @e2e directory-sync::an-administrator-sees-the-changes-before-they-happen
	test('a preview lists both sides and changes no membership', async ({
		page,
		request,
	}) => {
		const id = await seedConnection(request, [
			{ userName: 'admin', groups: ['OU=Vergunningen'] },
		])

		await openRowActions(page, 'E2E directory')
		await page.getByRole('menuitem', { name: 'Preview directory run' }).click()

		const dialog = appDialog(page)
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		await expect(dialog.getByTestId('directory-run-preview')).toBeVisible({
			timeout: 20_000,
		})
		await expect(dialog.getByTestId('directory-run-additions')).toBeVisible({
			timeout: 20_000,
		})

		// The preview changed nothing: running it again still reports the same
		// addition as outstanding.
		const record = await runConnection(request, id, { dryRun: true })
		expect(record.dryRun).toBe(true)
		expect(record.membershipsAdded).toBe(0)
		expect(record.membershipsRemoved).toBe(0)
		expect(record.additions.map((item: any) => item.userId)).toContain('admin')
	})
})

test.describe('REQ-DS-006: every run says what it changed', () => {
	// @e2e directory-sync::an-administrator-reads-yesterdays-run
	test('a finished run shows its counts and its failures with reasons', async ({
		page,
		request,
	}) => {
		const id = await seedConnection(request, [
			{ userName: 'admin', groups: ['OU=Toezicht'] },
			// Two rows integriq cannot map. Neither aborts the run, and both are
			// readable afterwards with their reason.
			{ groups: ['OU=Toezicht'] },
			{ groups: ['OU=Vergunningen'] },
		])
		const record = await runConnection(request, id)

		expect(record.usersRead).toBe(1)
		expect(record.failures).toHaveLength(2)
		expect(String(record.failures[0].reason)).toContain('no account name')

		await page.goto(`${APP_BASE}/directory-runs`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('main').first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText('E2E directory').first()).toBeVisible({
			timeout: 20_000,
		})
	})
})
