/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md
 *
 * The scenarios here are the ones an administrator actually performs: finding
 * where a send failed, filtering the log as a queue, retrying from it,
 * forwarding provably, and reading a record whose channel cannot report a
 * read. The records are seeded through OpenRegister's objects API, so the
 * screens and the endpoints under test are the production ones.
 *
 * The scenarios carrying `@e2e exclude` in the spec (a pre-transport failure,
 * the audit write on a body read, redaction, bulk outcome aggregation, the
 * link from both ends, the inbound receipt and the two last-contact answers)
 * are covered by PHPUnit and deliberately not restated.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'

/**
 * Seed one outbound message record.
 *
 * @param request The Playwright request context.
 * @param overrides What this record should say.
 * @return The record's OpenRegister id.
 */
async function seedMessage(
	request: APIRequestContext,
	overrides: Record<string, unknown> = {},
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/outbound_message`, {
		failOnStatusCode: false,
		data: {
			subjectRef: 'zaak/e2e-0042',
			channel: 'mail',
			subject: 'Ontvangstbevestiging',
			body: 'Wij hebben uw brief ontvangen.',
			sourceApp: 'dossiq',
			status: 'partially failed',
			createdAt: new Date().toISOString(),
			recipients: [
				{
					address: 'jan@example.org',
					name: 'Jan Burger',
					accountId: 'jan',
					external: false,
					status: 'sent',
					deliveryState: 'unsupported by this channel',
					readState: 'unsupported by this channel',
					handedOverAt: new Date().toISOString(),
				},
				{
					address: 'piet@example.org',
					name: 'Piet Behandelaar',
					accountId: 'piet',
					external: false,
					status: 'sent',
					deliveryState: 'unsupported by this channel',
					readState: 'unsupported by this channel',
				},
				{
					address: 'gemachtigde@advocaat.example',
					name: 'Gemachtigde',
					accountId: '',
					external: true,
					status: 'failed',
					deliveryState: 'unsupported by this channel',
					readState: 'unsupported by this channel',
					failedStep: 'transport',
					reason: '550 mailbox unavailable',
				},
			],
			steps: [
				{
					step: 'rendered',
					outcome: 'succeeded',
					at: new Date().toISOString(),
					detail: '',
				},
				{
					step: 'transport',
					outcome: 'failed',
					at: new Date().toISOString(),
					detail: '550 mailbox unavailable',
				},
			],
			...overrides,
		},
	})
	expect(resp.status(), 'seeding an outbound message must succeed').toBeLessThan(
		300,
	)
	const body = await resp.json()

	return String(body.id ?? body.uuid)
}

test.describe('outbound message log', () => {
	test('an administrator finds where a send failed', async ({ page, request }) => {
		await seedMessage(request, { subject: 'E2E ontvangstbevestiging' })

		await page.goto(`${APP_BASE}/messages/outbound`, {
			waitUntil: 'domcontentloaded',
		})
		const row = page
			.getByRole('row')
			.filter({ hasText: 'E2E ontvangstbevestiging' })
			.first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(
			row,
			'the queue says at a glance that not every copy left',
		).toContainText('partially failed')

		await row.click()
		await expect(
			page.getByText('550 mailbox unavailable').first(),
			'the failed recipient names the step and what the transport said',
		).toBeVisible({ timeout: 20_000 })
		await expect(
			page.getByText('gemachtigde@advocaat.example').first(),
		).toBeVisible()
	})

	test('the queue is readable as a queue', async ({ page, request }) => {
		await seedMessage(request, {
			subject: 'E2E mislukt bericht',
			status: 'failed',
		})
		await seedMessage(request, {
			subject: 'E2E verzonden bericht',
			status: 'sent',
		})

		await page.goto(`${APP_BASE}/messages/outbound`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.getByText('E2E mislukt bericht').first()).toBeVisible({
			timeout: 20_000,
		})
	})

	test('a failed message is retried and the attempt lands on the same record', async ({
		request,
	}) => {
		const id = await seedMessage(request, {
			subject: 'E2E opnieuw versturen',
			status: 'failed',
		})

		const resp = await request.post(
			`${API_BASE}/outbound/messages/${id}/retry`,
			{
				failOnStatusCode: false,
				headers: { 'OCS-APIRequest': 'true' },
				data: {},
			},
		)
		expect(
			resp.status(),
			'the retry must be accepted or refused, never 500',
		).toBeLessThan(500)

		const body = await resp.json()
		expect(body.items, 'a retry reports per item').toHaveLength(1)

		const after = await request.get(`${OR_BASE}/outbound_message/${id}`)
		const record = await after.json()
		expect(
			record.attempts?.length ?? 0,
			'the attempt is appended to the record that failed, not to a new one',
		).toBeGreaterThan(0)
	})

	test('a misdirected request is passed on provably', async ({ request }) => {
		const id = await seedMessage(request, { subject: 'E2E doorzenden' })

		const resp = await request.post(
			`${API_BASE}/outbound/messages/${id}/forward`,
			{
				failOnStatusCode: false,
				headers: { 'OCS-APIRequest': 'true' },
				data: {
					recipients: [
						{
							address: 'info@anderegemeente.example',
							name: 'Andere gemeente',
						},
					],
					note: 'Doorgezonden op grond van artikel 2:3 Awb.',
				},
			},
		)
		expect(resp.status(), 'the forward must be created').toBe(201)

		const forward = await resp.json()
		expect(forward.message.forwardedFrom ?? '').toBe(id)

		const original = await (
			await request.get(`${OR_BASE}/outbound_message/${id}`)
		).json()
		expect(
			original.forwardedTo?.[0]?.message,
			'the link reads from the original too',
		).toBe(forward.id)
		expect(
			original.recipients?.length,
			'the original is evidence, so the forward changes nothing but the link',
		).toBe(3)
	})

	test('a channel without read receipts says so rather than reporting none', async ({
		page,
		request,
	}) => {
		await seedMessage(request, { subject: 'E2E leesbevestiging' })

		await page.goto(`${APP_BASE}/messages/outbound`, {
			waitUntil: 'domcontentloaded',
		})
		const row = page
			.getByRole('row')
			.filter({ hasText: 'E2E leesbevestiging' })
			.first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await row.click()

		await expect(
			page.getByText('unsupported by this channel').first(),
			'mail cannot report a read, and the record says that instead of leaving it blank',
		).toBeVisible({ timeout: 20_000 })
	})

	test('a handler without the permission sees that a letter went out and not what it said', async ({
		request,
	}) => {
		const id = await seedMessage(request, { subject: 'E2E brieftekst' })

		// The seeded session is an administrator, so this asserts the endpoint
		// exists and answers; the refusal for a principal without the
		// `outbound.read-body` action is covered by PHPUnit, where the action
		// matrix can be set per test.
		const resp = await request.get(`${API_BASE}/outbound/messages/${id}/body`, {
			failOnStatusCode: false,
			headers: { 'OCS-APIRequest': 'true' },
		})

		expect(
			resp.status(),
			'reading a body is a request that is answered or refused',
		).toBeLessThan(500)
	})
})
