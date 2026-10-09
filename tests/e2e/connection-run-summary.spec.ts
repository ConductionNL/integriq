/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/connection-run-monitoring/spec.md
 * (REQ-CRUN-002, REQ-CRUN-003, REQ-CRUN-005)
 *
 * A source shows its pulls per day, a failed pull runs again with one click,
 * and an opened alert is listed on the alerts page. Run records and alerts are
 * written through OpenRegister's generic object API, the one the pages read,
 * so the test does not wait for a scheduler. The counting job and the
 * recipient resolver are PHPUnit's: ConnectionAlertServiceTest and
 * ConnectionAlertRecipientResolverTest.
 */

import { expect, test } from '@playwright/test'
import { withRequestToken } from './support/requestToken.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects/integriq'

async function createObject(request, schema: string, data: Record<string, unknown>) {
	const resp = await request.post(`${OBJECTS}/${schema}`, {
		data,
		failOnStatusCode: false,
	})
	expect(resp.status()).toBeLessThan(300)
	const body = await resp.json()
	return String(body.id ?? body.uuid ?? body['@self']?.id)
}

function daysAgo(days: number, hour: number): string {
	const date = new Date()
	date.setUTCDate(date.getUTCDate() - days)
	date.setUTCHours(hour, 0, 0, 0)
	return date.toISOString().replace('.000Z', '+00:00')
}

test.describe('connection run summary', () => {
	// @e2e connection-run-monitoring::an-administrator-reads-last-weeks-pulls
	test('the source page shows its pulls per day', async ({ page, request }) => {
		const sourceId = await createObject(request, 'source', {
			name: 'E2E run summary source',
			location: 'https://example.org',
			type: 'api',
		})
		for (let day = 0; day < 7; day++) {
			for (const [hour, status] of [
				[2, day === 1 || day === 4 ? 'failed' : 'success'],
				[14, 'success'],
			] as const) {
				await createObject(request, 'synchronization_run', {
					synchronizationId: 'e2e-sync',
					sourceId,
					triggeredBy: 'cron',
					status,
					startedAt: daysAgo(day, hour),
					found: 5,
				})
			}
		}

		const summary = await (
			await request.get(
				`/index.php/apps/integriq/api/sources/${sourceId}/run-summary`,
				{ headers: await withRequestToken(request) },
			)
		).json()
		expect(summary.days).toHaveLength(7)
		expect(summary.days.reduce((sum, day) => sum + day.runs, 0)).toBe(14)
		expect(summary.days.reduce((sum, day) => sum + day.failed, 0)).toBe(2)

		await page.goto(`/index.php/apps/integriq/sources/${sourceId}`)
		await expect(
			page.getByTestId('run-summary-days').locator('tbody tr'),
		).toHaveCount(7)
		await expect(page.getByTestId('run-again').first()).toBeVisible()
	})

	// @e2e connection-run-monitoring::a-failed-monday-pull-is-restarted
	test('Run again starts the synchronization once and names the new run', async ({
		request,
	}) => {
		const sourceId = await createObject(request, 'source', {
			name: 'E2E unreachable source',
			location: 'https://unreachable.invalid',
			type: 'api',
		})
		const synchronizationId = await createObject(request, 'synchronization', {
			name: 'E2E rerun',
			sourceId,
			sourceType: 'api',
		})

		const resp = await request.post(
			`/index.php/apps/integriq/api/synchronizations/${synchronizationId}/run`,
			{
				data: { triggeredBy: 'rerun' },
				failOnStatusCode: false,
			},
		)
		const body = await resp.json()
		expect(body.runId).toBeTruthy()

		const run = await (
			await request.get(`${OBJECTS}/synchronization_run/${body.runId}`)
		).json()
		expect(run.triggeredBy).toBe('rerun')
		expect(run.sourceId).toBe(sourceId)
	})

	// @e2e connection-run-monitoring::the-named-group-is-told
	test('an opened alert is listed as open on the alerts page', async ({
		page,
		request,
	}) => {
		await createObject(request, 'connection_alert', {
			subjectType: 'source',
			subject: 'e2e-source',
			subjectName: 'E2E KVK',
			rule: 'failedCalls',
			count: 11,
			threshold: 10,
			windowMinutes: 60,
			state: 'open',
			openedAt: new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00'),
		})

		await page.goto('/index.php/apps/integriq/connection-alerts')
		await expect(page.getByText('E2E KVK')).toBeVisible()
	})
})
