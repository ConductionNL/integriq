/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 *
 * The scenario driven here is the import of a saved Outlook message. The
 * fixture at tests/e2e/fixtures/outlook-message.msg is a real compound file
 * with two attachments, so the reader under test is the production one.
 *
 * The other scenarios in that spec carry `@e2e exclude` because they are a
 * background synchronization and two cross-app typed events, all covered by
 * PHPUnit; they are deliberately not restated here.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'

/**
 * Seed a mailbox source in mock mode.
 *
 * @param request The Playwright request context.
 * @return The source's OpenRegister id.
 */
async function seedMailbox(request: APIRequestContext): Promise<string> {
	const resp = await request.post(`${OR_BASE}/source`, {
		failOnStatusCode: false,
		data: {
			name: 'E2E mailbox',
			description: 'Seeded by tests/e2e/mail-intake.spec.ts',
			type: 'mailbox',
			isEnabled: true,
			configuration: {
				mock: true,
				protocol: 'imap',
				folder: 'INBOX',
				casePattern: '/\\b([A-Z]{2,10}-\\d{4}-\\d{1,8})\\b/',
				fixture: { messages: [] },
			},
		},
	})
	expect(resp.status(), 'seeding a mailbox source must succeed').toBeLessThan(300)
	const body = await resp.json()

	return String(body.id ?? body.uuid)
}

test.describe('mail intake', () => {
	test('an operator imports a saved Outlook message', async ({
		page,
		request,
	}) => {
		const sourceId = await seedMailbox(request)
		const file = readFileSync(join(__dirname, 'fixtures', 'outlook-message.msg'))

		const resp = await request.post(`${API_BASE}/mail-intake/import`, {
			failOnStatusCode: false,
			// The import route is CSRF-protected like every other write here; the
			// documented way an API client passes that check is the OCS header.
			headers: { 'OCS-APIRequest': 'true' },
			multipart: {
				sourceId,
				file: {
					name: 'outlook-message.msg',
					mimeType: 'application/vnd.ms-outlook',
					buffer: file,
				},
			},
		})

		expect(resp.status(), 'the import must be accepted').toBe(201)
		const body = await resp.json()
		expect(body.warning, 'a real .msg parses, so nothing falls back').toBeFalsy()
		expect(
			body.message.attachments.length,
			'both attachments survive the import',
		).toBe(2)
		expect(body.message.subject).toBe('Vraag over ZAAK-2026-0042')
		expect(
			body.message.detectedReference,
			'the case number in the subject is detected',
		).toBe('ZAAK-2026-0042')

		// The message is on the page an operator actually looks at.
		await page.goto(`${APP_BASE}/messages/mail`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(
			page.getByText('Vraag over ZAAK-2026-0042').first(),
		).toBeVisible({ timeout: 20_000 })
	})

	test('a second import of the same message creates nothing new', async ({
		request,
	}) => {
		const sourceId = await seedMailbox(request)
		const file = readFileSync(join(__dirname, 'fixtures', 'outlook-message.msg'))

		const importOnce = async () =>
			await request.post(`${API_BASE}/mail-intake/import`, {
				failOnStatusCode: false,
				headers: { 'OCS-APIRequest': 'true' },
				multipart: {
					sourceId,
					file: {
						name: 'outlook-message.msg',
						mimeType: 'application/vnd.ms-outlook',
						buffer: file,
					},
				},
			})

		const first = await importOnce()
		const second = await importOnce()
		expect(first.status()).toBe(201)
		expect(second.status()).toBe(201)

		const firstBody = await first.json()
		const secondBody = await second.json()
		expect(
			secondBody.id,
			're-importing the same message returns the message already stored',
		).toBe(firstBody.id)
	})

	test('an anonymous caller cannot import a message', async () => {
		// The least privileged principal that should be refused: nobody signed
		// in. A 2xx here would mean any visitor can write objects other apps act
		// on, so the assertion is on the refusal, not on the body.
		const anonymous = await playwrightRequest.newContext({
			baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080',
		})

		const resp = await anonymous.post(`${API_BASE}/mail-intake/import`, {
			failOnStatusCode: false,
			headers: { 'OCS-APIRequest': 'true' },
			multipart: {
				sourceId: 'whatever',
				file: {
					name: 'outlook-message.msg',
					mimeType: 'application/vnd.ms-outlook',
					buffer: Buffer.from('not even a message'),
				},
			},
		})

		expect(
			resp.status(),
			'an anonymous import must be refused, not merely empty',
		).toBeGreaterThanOrEqual(401)
		await anonymous.dispose()
	})
})
