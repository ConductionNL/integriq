/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/message-recipient-selection/spec.md
 * (REQ-MRS-003 the record says why a recipient was left out, REQ-MRS-004 a
 * required recipient cannot be suppressed).
 *
 * What a user does here: open a sent message on the Sent messages page and
 * read, beside the recipient that was left out, the reason somebody typed,
 * who left them out and when. And: ask integriq who a message would go to
 * while leaving out a recipient the calling app marked required, and be told
 * no, in the calling app's own words, with nothing sent.
 *
 * The record is staged through OpenRegister's object API
 * (`/index.php/apps/openregister/api/objects/integriq/outbound_message`, with
 * `OCS-APIRequest: true`) in the shape MessageRecorder::withSelection writes,
 * because a send with a recipient decision only arrives through the
 * DeliveryRequestedEvent listener, which no HTTP route or browser can raise.
 * The refusal is read from the preview route
 * (`POST /api/outbound/recipients/preview`), which runs the send's own
 * RecipientResolver and writes nothing.
 *
 * Left to PHPUnit, as the spec's `@e2e exclude` lines say: the resolved list
 * the transport receives, the record shape of a suppressed recipient, that
 * nothing outside the message is written, the refusal of a reasonless
 * suppression, the acceptance of an unmarked one, that a preview leaves no
 * trace, and the listener's handling of the delivery request.
 *
 * Runs as the admin the global setup logs in. The staged record is deleted
 * at the end.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { appDialog } from './support/dialogs.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-mrs-${Date.now().toString(36)}`

/** The subject of the staged message, shown in the Sent messages list. */
const SUBJECT = `E2E beschikking ${RUN}`

/** The recipient that was left out, and why. */
const SUPPRESSED_ADDRESS = `buurman-${RUN}@example.org`
const SUPPRESSED_REASON = `Buurman heeft zijn zienswijze ingetrokken (${RUN})`
const SUPPRESSED_BY = 'behandelaar-e2e'
const SUPPRESSED_AT = '2025-11-21T10:15:00+00:00'

/** What the calling app says requires the applicant on a beschikking. */
const REQUIRED_BY = `De aanvrager ontvangt de beschikking (artikel 3:41 Awb) ${RUN}`

const cleanup: Array<{ schema: string, id: string }> = []

/**
 * Headers for a call outside the app's own axios.
 *
 * @param request The request fixture.
 * @return The headers.
 */
async function adminHeaders(request: APIRequestContext): Promise<Record<string, string>> {
	return { 'OCS-APIRequest': 'true', ...(await withRequestToken(request)) }
}

/**
 * Create one integriq object through OpenRegister's object API and remember it for cleanup.
 *
 * @param request The request fixture.
 * @param schema  The schema slug.
 * @param data    The object.
 * @return The stored object's id.
 */
async function seedObject(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, {
		failOnStatusCode: false,
		headers: await adminHeaders(request),
		data,
	})
	expect([200, 201], `seeding a ${schema}: ${await resp.text()}`).toContain(resp.status())
	const body = await resp.json()
	const id = String(body.id ?? body.uuid ?? body['@self']?.id ?? '')
	expect(id, `the seeded ${schema} has an id`).not.toBe('')
	cleanup.push({ schema, id })

	return id
}

/**
 * How many outbound messages are stored, as OpenRegister counts them.
 *
 * @param request The request fixture.
 * @return The count.
 */
async function outboundCount(request: APIRequestContext): Promise<number> {
	const resp = await request.get(`${OR_BASE}/outbound_message`, {
		headers: await adminHeaders(request),
		params: { _limit: 1 },
	})
	expect(resp.status()).toBe(200)
	const body = await resp.json()

	return Number(body.total ?? (body.results ?? []).length)
}

/**
 * Ask integriq who a message would go to, without sending it.
 *
 * @param request  The request fixture.
 * @param decision The standing, added and suppressed recipients.
 * @return The response.
 */
async function preview(request: APIRequestContext, decision: Record<string, unknown>) {
	return request.post(`${API_BASE}/outbound/recipients/preview`, {
		failOnStatusCode: false,
		headers: await adminHeaders(request),
		data: decision,
	})
}

test.describe('one-off-and-suppressed-recipients: who a message goes to, and who it did not', () => {
	test.describe.configure({ mode: 'serial' })

	test.afterAll(async ({ request }) => {
		const headers = await adminHeaders(request)
		for (const { schema, id } of cleanup) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { headers, failOnStatusCode: false })
		}
	})

	// @e2e message-recipient-selection::a-year-later-the-record-says-why
	test('the record shows the reason, the user and the time beside the suppressed recipient', async ({ page, request }) => {
		const now = new Date().toISOString()
		await seedObject(request, 'outbound_message', {
			subjectRef: `zaak/${RUN}`,
			channel: 'mail',
			subject: SUBJECT,
			sourceApp: 'dossiq',
			status: 'sent',
			createdAt: now,
			recipients: [
				{ address: `aanvrager-${RUN}@example.org`, name: 'Aanvrager', accountId: '', external: true, status: 'sent', handedOverAt: now },
				{ address: `gemachtigde-${RUN}@example.org`, name: 'Gemachtigde', accountId: '', external: true, status: 'sent', handedOverAt: now },
			],
			recipientSelection: {
				standing: [
					{ address: `aanvrager-${RUN}@example.org`, name: 'Aanvrager', accountId: '', required: true, requiredBy: REQUIRED_BY },
					{ address: SUPPRESSED_ADDRESS, name: 'Buurman', accountId: '', required: false, requiredBy: '' },
				],
				added: [
					{ address: `gemachtigde-${RUN}@example.org`, name: 'Gemachtigde', accountId: '', external: true },
				],
				suppressed: [
					{
						address: SUPPRESSED_ADDRESS,
						name: 'Buurman',
						state: 'suppressed',
						reason: SUPPRESSED_REASON,
						user: SUPPRESSED_BY,
						at: SUPPRESSED_AT,
					},
				],
			},
		})

		await gotoAppRoute(page, '/messages/outbound')
		await expectRouteMatched(page, '/messages/outbound')
		const row = page.getByRole('row').filter({ hasText: SUBJECT }).first()
		await expect(row, 'the message is listed on the Sent messages page').toBeVisible({ timeout: 20_000 })
		await row.click()

		const dialog = appDialog(page)
		await expect(dialog, 'the record opens').toBeVisible({ timeout: 15_000 })
		await expect(
			dialog.getByRole('heading', { name: /recipient selection/i }),
			'the recipient decision is its own part of the record',
		).toBeVisible()

		// CnLogsPage prints a nested part as pretty JSON in a <pre>; reading it
		// back shows the reason, user and time sit on the suppressed recipient
		// itself, not somewhere else on the record.
		const block = dialog.locator('pre').filter({ hasText: SUPPRESSED_REASON }).first()
		await expect(block).toBeVisible()
		const shown = JSON.parse((await block.textContent()) ?? '{}')
		expect(shown.suppressed, 'one recipient was left out').toHaveLength(1)
		expect(shown.suppressed[0]).toMatchObject({
			address: SUPPRESSED_ADDRESS,
			state: 'suppressed',
			reason: SUPPRESSED_REASON,
			user: SUPPRESSED_BY,
			at: SUPPRESSED_AT,
		})
	})

	// @e2e message-recipient-selection::the-applicant-cannot-be-dropped-from-a-beschikking
	test('suppressing a recipient the caller marked required is refused in the caller\'s words', async ({ request }) => {
		const applicant = `aanvrager-${RUN}@example.org`
		const before = await outboundCount(request)

		const resp = await preview(request, {
			standing: [
				{ address: applicant, name: 'Aanvrager', required: true, requiredBy: REQUIRED_BY },
				{ address: `buurman-${RUN}@example.org`, name: 'Buurman' },
			],
			added: [],
			suppressed: [
				{ address: applicant, reason: 'Aanvrager wil geen post meer' },
			],
		})

		expect(resp.status(), 'the suppression is refused').toBe(422)
		const body = await resp.json()
		expect(body.code).toBe('required_recipient')
		expect(body.address).toBe(applicant)
		expect(body.error, 'the refusal repeats what the caller said requires the recipient').toContain(REQUIRED_BY)

		expect(await outboundCount(request), 'nothing was recorded as sent').toBe(before)
	})
})
