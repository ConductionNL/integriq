/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md
 *
 * The SMS provider seam as a running instance shows it: the provider picker
 * of an SMS source, a mistyped provider stored as a failure, a signed Twilio
 * delivery receipt reaching the SMS page, and the two sends that leave the
 * instance (MessageBird SMS and NotifyNL e-mail).
 *
 * The sends that reach a real vendor need a brokered credential of a test
 * account, which no CI instance carries. Those tests run only when the
 * instance under test names one, and skip with the variable they need
 * otherwise:
 *
 *   E2E_MESSAGEBIRD_CREDENTIAL_REF  a brokered MessageBird access key
 *   E2E_SMS_RECIPIENT               the test phone number (E.164)
 *   E2E_NOTIFYNL_SOURCE_SLUG        an enabled NotifyNL source with a mail template
 *   E2E_NOTIFYNL_MAIL_TEMPLATE      that template's id
 *   E2E_NOTIFYNL_MAIL_RECIPIENT     the test address
 *
 * Scenarios carrying `@e2e exclude` in the spec (fail-closed credential path,
 * a non-SMS slug, a forged callback, NotifyNL's callback and the address
 * check) are covered by PHPUnit and have no test here.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { createHmac } from 'node:crypto'
import { anonymousRequest } from './support/anonymous.ts'
import { expectRouteMatched, gotoAppRoute } from './support/appRoot.ts'
import { absoluteUrl } from './support/baseUrl.ts'
import { appDialog } from './support/dialogs.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const APP_API = '/index.php/apps/integriq/api'
const RUN = `e2e-smsp-${Date.now()}`

/** Source ids this file created, removed again in afterAll. */
const createdSources: string[] = []

/** Message ids this file created, removed again in afterAll. */
const createdMessages: string[] = []

/**
 * The id of an OpenRegister object as the object API returns it.
 *
 * @param body The object body.
 * @return The id.
 */
function idOf(body: Record<string, unknown>): string {
	return String(body.id ?? (body['@self'] as Record<string, string> | undefined)?.id ?? '')
}

/**
 * Seed an SMS source through the object API.
 *
 * @param request The admin request context.
 * @param slug A slug unique to the calling test.
 * @param configuration The source configuration under test.
 * @param authentication The source's brokered credential, if any.
 * @return The created source's body, read back so the save listener's token is on it.
 */
async function seedSmsSource(
	request: APIRequestContext,
	slug: string,
	configuration: Record<string, unknown>,
	authentication: Record<string, unknown> | null = null,
): Promise<Record<string, unknown>> {
	const resp = await request.post(`${OR_BASE}/source`, {
		failOnStatusCode: false,
		data: {
			name: slug,
			slug,
			description: 'Seeded by tests/e2e/sms-providers.spec.ts',
			type: 'sms',
			isEnabled: true,
			location: 'https://example.invalid/sms',
			configuration: authentication === null ? configuration : { ...configuration, authentication },
		},
	})
	expect(resp.status(), `seeding SMS source ${slug}: ${await resp.text()}`).toBeLessThan(300)
	const id = idOf((await resp.json()) as Record<string, unknown>)
	createdSources.push(id)

	const read = await request.get(`${OR_BASE}/source/${id}`, { failOnStatusCode: false })
	expect(read.status()).toBe(200)

	return (await read.json()) as Record<string, unknown>
}

/**
 * The stored SMS messages a source sent.
 *
 * @param request The admin request context.
 * @param slug The source's slug.
 * @return The messages.
 */
async function messagesOf(
	request: APIRequestContext,
	slug: string,
): Promise<Record<string, unknown>[]> {
	const resp = await request.get(
		`${OR_BASE}/sms_message?sourceSlug=${encodeURIComponent(slug)}&_limit=50`,
		{ failOnStatusCode: false },
	)
	expect(resp.status()).toBe(200)

	return ((await resp.json()).results ?? []) as Record<string, unknown>[]
}

/**
 * Open the SMS page and wait for its list.
 *
 * @param page The page.
 * @return Nothing.
 */
async function openSmsPage(page: Page): Promise<void> {
	await gotoAppRoute(page, '/messages/sms')
	await expectRouteMatched(page, '/messages/sms')
	await expect(page.locator('table').first()).toBeVisible({ timeout: 20_000 })
}

/**
 * Send one SMS through the app's send endpoint.
 *
 * @param request The admin request context.
 * @param data The send body.
 * @return The response.
 */
async function sendSms(
	request: APIRequestContext,
	data: Record<string, unknown>,
): Promise<{ status: number, body: Record<string, unknown> }> {
	const resp = await request.post(`${APP_API}/notifynl/messages`, {
		failOnStatusCode: false,
		headers: await withRequestToken(request),
		data,
	})
	const text = await resp.text()
	let body: Record<string, unknown>
	try {
		body = JSON.parse(text) as Record<string, unknown>
	} catch {
		body = { raw: text }
	}

	return { status: resp.status(), body }
}

test.describe('SMS providers', () => {
	test.afterAll(async ({ request }) => {
		for (const id of createdMessages) {
			await request.delete(`${OR_BASE}/sms_message/${id}`, { failOnStatusCode: false })
		}
		for (const id of createdSources) {
			await request.delete(`${OR_BASE}/source/${id}`, { failOnStatusCode: false })
		}
	})

	// @e2e notifynl-sms-channel::an-administrator-sets-up-a-cmcom-source
	test('the provider picker of a new SMS source offers the five providers', async ({
		page,
	}) => {
		test.setTimeout(90_000)

		// The picker is built from the registry endpoint, so the endpoint and
		// the picker must agree.
		const listed = await page.request.get(`${APP_API}/sms/providers`, {
			failOnStatusCode: false,
			headers: await withRequestToken(page.request),
		})
		expect(listed.status()).toBe(200)
		const providers = ((await listed.json()).providers ?? []) as Record<string, unknown>[]
		const ids = providers.map((p) => String(p.providerId))
		expect(ids).toEqual(expect.arrayContaining(['log', 'notifynl', 'cmcom', 'messagebird', 'twilio']))

		await gotoAppRoute(page, '/sources')
		await expectRouteMatched(page, '/sources')
		await page.getByRole('button', { name: /add source/i }).first().click()
		const dialog = appDialog(page)
		await expect(dialog, 'the create modal must open').toBeVisible({ timeout: 10_000 })

		await dialog.locator('#cn-source-form-type').click()
		await dialog.locator('#cn-source-form-type').fill('SMS')
		await page.getByRole('option', { name: /^SMS$/ }).first().click()

		const picker = dialog.locator('#cn-source-form-sms-provider')
		await expect(picker, 'a source of type sms shows the provider picker').toBeVisible({
			timeout: 10_000,
		})
		await picker.click()
		for (const name of [/Sandbox \/ log/, /^NotifyNL$/, /^CM\.com$/, /^MessageBird$/, /^Twilio$/]) {
			await expect(page.getByRole('option', { name }).first()).toBeVisible()
		}

		// Picking CM.com shows the settings CM.com's binding declares.
		await page.getByRole('option', { name: /^CM\.com$/ }).first().click()
		await expect(dialog.getByText(/^Sender/).first()).toBeVisible({ timeout: 10_000 })
	})

	// @e2e notifynl-sms-channel::a-mistyped-provider-shows-as-a-failure-not-a-success
	test('a source whose provider is mistyped stores the message failed, naming the value', async ({
		page,
		request,
	}) => {
		const slug = `${RUN}-twillio`
		await seedSmsSource(request, slug, { provider: 'twillio' })

		const sent = await sendSms(request, {
			to: '+31600000001',
			body: 'e2e: mistyped provider',
			sourceSlug: slug,
			sourceApp: 'integriq-e2e',
		})
		expect(sent.status, 'a mistyped provider must never report a successful send').not.toBe(200)
		expect(JSON.stringify(sent.body)).not.toContain('"status":"sent"')

		const stored = await messagesOf(request, slug)
		expect(stored.length, 'the failed message must be stored').toBeGreaterThan(0)
		for (const message of stored) {
			createdMessages.push(idOf(message))
			expect(message.status).toBe('failed')
			expect(String(message.detail ?? '')).toContain('twillio')
		}

		await openSmsPage(page)
		const row = page.locator('tr', { hasText: slug }).first()
		await expect(row, 'the SMS page lists the failed message with its source').toBeVisible({
			timeout: 20_000,
		})
		await expect(row).toContainText(/failed/i)
	})

	// @e2e notifynl-sms-channel::a-twilio-delivery-receipt-marks-the-message-delivered
	test('a signed Twilio delivery receipt marks the message delivered', async ({
		page,
		request,
	}) => {
		const slug = `${RUN}-twilio`
		const signingKey = `e2e-signing-key-${RUN}`
		const source = await seedSmsSource(request, slug, {
			provider: 'twilio',
			accountSid: 'AC00000000000000000000000000000000',
			webhookSigningKey: signingKey,
		})
		const configuration = (source.configuration ?? {}) as Record<string, unknown>
		const token = String(configuration.statusCallbackToken ?? '')
		expect(token, 'saving an SMS source must give it a callback token').not.toBe('')
		expect(
			JSON.stringify(configuration),
			'the typed signing key is stored encrypted, never as itself',
		).not.toContain(signingKey)

		// The message Twilio reports on, as a send through this source leaves it.
		const sid = `SM${Date.now()}e2e`
		const seeded = await request.post(`${OR_BASE}/sms_message`, {
			failOnStatusCode: false,
			data: {
				recipientMsisdn: '+31600000002',
				provider: 'twilio',
				sourceSlug: slug,
				providerMessageId: sid,
				status: 'sent',
				sourceApp: 'integriq-e2e',
			},
		})
		expect(seeded.status()).toBeLessThan(300)
		const messageId = idOf((await seeded.json()) as Record<string, unknown>)
		createdMessages.push(messageId)

		// Twilio posts form-encoded and signs the URL plus the sorted fields.
		const path = `${APP_API}/sms/twilio/status/${token}`
		const fields: Record<string, string> = {
			AccountSid: 'AC00000000000000000000000000000000',
			MessageSid: sid,
			MessageStatus: 'delivered',
		}
		const signed = Object.keys(fields)
			.sort()
			.reduce((acc, name) => acc + name + fields[name], absoluteUrl(path))
		const signature = createHmac('sha1', signingKey).update(signed).digest('base64')

		// The vendor carries no session.
		const vendor = await anonymousRequest()
		try {
			const callback = await vendor.post(path, {
				failOnStatusCode: false,
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					'X-Twilio-Signature': signature,
				},
				data: new URLSearchParams(fields).toString(),
			})
			expect(callback.status(), await callback.text()).toBe(200)
		} finally {
			await vendor.dispose()
		}

		const read = await request.get(`${OR_BASE}/sms_message/${messageId}`, { failOnStatusCode: false })
		expect(read.status()).toBe(200)
		expect((await read.json()).status).toBe('delivered')

		await openSmsPage(page)
		const row = page.locator('tr', { hasText: slug }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row).toContainText(/delivered/i)
	})

	// @e2e notifynl-sms-channel::a-sibling-app-sends-a-reminder-through-messagebird
	test('a message sent through a MessageBird source is listed with its provider and source', async ({
		page,
		request,
	}) => {
		const credentialRef = process.env.E2E_MESSAGEBIRD_CREDENTIAL_REF ?? ''
		const recipient = process.env.E2E_SMS_RECIPIENT ?? ''
		test.skip(
			credentialRef === '' || recipient === '',
			'needs E2E_MESSAGEBIRD_CREDENTIAL_REF and E2E_SMS_RECIPIENT: a live MessageBird test account',
		)

		const slug = `${RUN}-messagebird`
		await seedSmsSource(request, slug, { provider: 'messagebird' }, { credentialRef })

		const sent = await sendSms(request, {
			to: recipient,
			body: 'integriq e2e: MessageBird reminder',
			sourceSlug: slug,
			sourceApp: 'pipelinq',
		})
		expect(sent.status, JSON.stringify(sent.body)).toBe(200)
		createdMessages.push(String(sent.body.id ?? ''))
		expect(sent.body.provider).toBe('messagebird')
		expect(sent.body.sourceSlug).toBe(slug)
		expect(String(sent.body.providerMessageId ?? '')).not.toBe('')

		await openSmsPage(page)
		const row = page.locator('tr', { hasText: slug }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row).toContainText('messagebird')
	})

	// @e2e notifynl-sms-channel::a-case-workers-app-sends-a-notifynl-mail
	test('a NotifyNL mail is handed over and logged with NotifyNL\'s reference', async ({
		page,
		request,
	}) => {
		const sourceSlug = process.env.E2E_NOTIFYNL_SOURCE_SLUG ?? ''
		const templateId = process.env.E2E_NOTIFYNL_MAIL_TEMPLATE ?? ''
		const recipient = process.env.E2E_NOTIFYNL_MAIL_RECIPIENT ?? ''
		test.skip(
			sourceSlug === '' || templateId === '' || recipient === '',
			'needs E2E_NOTIFYNL_SOURCE_SLUG, E2E_NOTIFYNL_MAIL_TEMPLATE and E2E_NOTIFYNL_MAIL_RECIPIENT: a live NotifyNL test account',
		)

		const resp = await request.post(`${APP_API}/notifynl/emails`, {
			failOnStatusCode: false,
			headers: await withRequestToken(request),
			data: {
				to: recipient,
				templateId,
				personalisation: { naam: 'E2E' },
				sourceSlug,
				sourceApp: 'dossiq',
			},
		})
		expect(resp.status(), await resp.text()).toBe(200)
		const body = (await resp.json()) as Record<string, unknown>
		const reference = String(body.reference ?? '')
		expect(reference, 'NotifyNL\'s id is returned as the reference').not.toBe('')

		// The send's id is the outbound message log row it was recorded in.
		const log = await request.get(`${OR_BASE}/outbound_message/${String(body.id ?? '')}`, {
			failOnStatusCode: false,
		})
		expect(log.status()).toBe(200)
		const record = (await log.json()) as Record<string, unknown>
		expect(record.channel).toBe('notifynl')
		const recipients = (record.recipients ?? []) as Record<string, unknown>[]
		expect(
			recipients.some((r) => String(r.reference ?? '') === reference),
			'the recipient is handed over with NotifyNL\'s reference',
		).toBe(true)

		await gotoAppRoute(page, '/messages/outbound')
		await expectRouteMatched(page, '/messages/outbound')
		await expect(
			page.getByRole('row').filter({ hasText: recipient }).first(),
			'the outbound message log lists the mail',
		).toBeVisible({ timeout: 20_000 })
	})
})
