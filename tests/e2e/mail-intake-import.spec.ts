/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * messaging-intake-routing-rules task 8 (mail-intake REQ-MAIL-002 from the
 * page): a handler with the mail import right imports a saved message from the
 * mail inbox's "Import file (.eml or .msg)" dialog under a chosen mailbox, a
 * file that does not import is refused in the dialog, and a user without the
 * right is not offered the action. The import endpoint itself is covered by
 * mail-intake.spec.ts; this spec drives the dialog that posts to it.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = '/index.php/apps/integriq/api'
const OCS_USERS = '/ocs/v1.php/cloud/users'
const PLAIN_USER = `e2e-no-mail-import-${Date.now()}`
const PLAIN_PASSWORD = 'E2e-No-Mail-Import-2026!'

// Basic-auth API calls without this header fail Nextcloud's CSRF check (412).
test.use({ extraHTTPHeaders: { 'OCS-APIRequest': 'true' } })

/**
 * A mailbox source in mock mode, named so the dialog's select can find it.
 *
 * @param request The request fixture.
 * @param name The mailbox name.
 * @return The source id.
 */
async function seedMailbox(request: APIRequestContext, name: string): Promise<string> {
	const resp = await request.post(`${OR_BASE}/source`, {
		failOnStatusCode: false,
		data: {
			name,
			description: 'Seeded by tests/e2e/mail-intake-import.spec.ts',
			type: 'mailbox',
			isEnabled: true,
			configuration: { mock: true, protocol: 'imap', folder: 'INBOX', fixture: { messages: [] } },
		},
	})
	expect(resp.status(), 'seeding a mailbox source must succeed').toBeLessThan(300)
	const body = await resp.json()
	return String(body.id ?? body.uuid)
}

/**
 * A minimal RFC 5322 message with a unique subject.
 *
 * @param subject The subject.
 * @return The .eml bytes.
 */
function eml(subject: string): Buffer {
	return Buffer.from([
		'From: Ans de Vries <ans@example.nl>',
		'To: info@gemeente.example.nl',
		`Subject: ${subject}`,
		'Date: Tue, 17 Nov 2026 10:00:00 +0100',
		`Message-ID: <${Date.now()}.${Math.random()}@example.nl>`,
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=utf-8',
		'',
		'Graag een reactie op mijn vraag over de vergunning.',
		'',
	].join('\r\n'), 'utf8')
}

/**
 * Open the mail inbox and the import dialog, with the mailbox picked.
 *
 * @param page The page.
 * @param mailbox The mailbox name.
 * @return The dialog.
 */
async function openImportDialog(page: Page, mailbox: string) {
	await page.goto(`${APP_BASE}/messages/mail`, { waitUntil: 'domcontentloaded' })
	await page.getByTestId('mail-import-file').click()
	const dialog = page.getByTestId('import-mail-file-modal')
	await expect(dialog).toBeVisible({ timeout: 10_000 })
	await dialog.getByLabel('Mailbox').click()
	await page.getByRole('option', { name: mailbox }).click()
	return dialog
}

test.describe('mail-intake-import', () => {
	const sources: string[] = []

	test.afterAll(async ({ request }) => {
		for (const id of sources) {
			await request.delete(`${OR_BASE}/source/${id}`, { failOnStatusCode: false })
		}
		await request.delete(`${OCS_USERS}/${PLAIN_USER}`, { headers: { 'OCS-APIRequest': 'true' }, failOnStatusCode: false })
	})

	test('a handler imports a saved message from the inbox dialog and it is listed', async ({ page, request }) => {
		const mailbox = `E2E import mailbox ${Date.now()}`
		sources.push(await seedMailbox(request, mailbox))
		const subject = `E2E import ${Date.now()}`

		const dialog = await openImportDialog(page, mailbox)
		await dialog.getByTestId('import-mail-file-input').setInputFiles({
			name: 'vraag.eml',
			mimeType: 'message/rfc822',
			buffer: eml(subject),
		})
		await dialog.getByTestId('import-mail-file-send').click()

		await expect(dialog.getByTestId('import-mail-file-result')).toContainText(`Imported "${subject}".`, { timeout: 20_000 })
		await expect(dialog.getByTestId('import-mail-file-error')).toHaveCount(0)

		await page.goto(`${APP_BASE}/messages/mail`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByText(subject).first()).toBeVisible({ timeout: 20_000 })
	})

	test('an empty file is refused in the dialog with the reason', async ({ page, request }) => {
		const mailbox = `E2E import refusal ${Date.now()}`
		sources.push(await seedMailbox(request, mailbox))

		const dialog = await openImportDialog(page, mailbox)
		await dialog.getByTestId('import-mail-file-input').setInputFiles({
			name: 'leeg.eml',
			mimeType: 'message/rfc822',
			buffer: Buffer.alloc(0),
		})
		await dialog.getByTestId('import-mail-file-send').click()

		await expect(dialog.getByTestId('import-mail-file-error')).toBeVisible({ timeout: 20_000 })
		await expect(dialog.getByTestId('import-mail-file-result')).toHaveCount(0)
		await expect(dialog, 'the dialog stays open on a refusal').toBeVisible()
	})

	test('a user without the mail import right is not offered the import', async ({ browser, request, baseURL }) => {
		const created = await request.post(OCS_USERS, {
			headers: { 'OCS-APIRequest': 'true' },
			form: { userid: PLAIN_USER, password: PLAIN_PASSWORD },
			failOnStatusCode: false,
		})
		expect(created.ok(), 'creating a plain user over OCS must succeed').toBeTruthy()

		const context = await browser.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: PLAIN_USER, password: PLAIN_PASSWORD },
		})
		try {
			const options = await context.request.get(`${API_BASE}/mail-intake/import/options`, {
				headers: { 'OCS-APIRequest': 'true' },
			})
			expect(options.status()).toBe(200)
			expect(await options.json()).toEqual({ allowed: false, mailboxes: [] })

			const page = await context.newPage()
			await page.goto(`${APP_BASE}/messages/mail`, { waitUntil: 'domcontentloaded' })
			await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
			await expect(page.getByTestId('mail-import-file')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
