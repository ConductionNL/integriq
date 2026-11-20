/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * messaging-intake-routing-rules: an administrator saves routing rules through
 * the endpoint the rule dialog calls (validated against the channel and the
 * case type), tests a draft rule against a sample, routes a held message
 * again, and reads the seeded rules on the "Intake routing" page.
 *
 * Self-contained: the case type is a schema this spec creates in OpenRegister
 * and removes afterwards, so the dialog's validation has real fields to check.
 * No app in the CI environment opens that case type, so a message that
 * reaches its rule is held with the reason "No app opened a …", which names
 * the rule's case type; what each scenario asserts is which rule matched and
 * what changed on the message, not the case another app would open.
 *
 * Deliveries use the signed public-space-report channel, as
 * intake-channels.spec.ts does: the Teams channel authenticates with Bot
 * Framework tokens that a test cannot mint. The Teams scenario therefore
 * saves and lists a Teams rule, and leaves delivery to PHPUnit.
 *
 * Out of scope here: the Outlook manifest scenario (task 6 is not built), and
 * the scenarios the spec marks `@e2e exclude`.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { createHmac } from 'node:crypto'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_API = '/index.php/apps/openregister/api'
const OR_BASE = `${OR_API}/objects/integriq`
const API_BASE = '/index.php/apps/integriq/api'
const SECRET = 'e2e-intake-routing-secret'
const CHANNEL = 'public-space-report'

const seededRules: string[] = []
let caseTypeSlug = ''
let caseTypeId = ''

/**
 * The openconnector-scheme signature header for a delivery body.
 *
 * @param body The raw body.
 * @return The header value.
 */
function sign(body: string): string {
	const timestamp = Math.floor(Date.now() / 1000)
	const signature = createHmac('sha256', SECRET).update(`${timestamp}.${body}`).digest('hex')
	return `t=${timestamp},v1=${signature}`
}

/**
 * Make the channel's consumer carry this spec's signing secret.
 *
 * @param request The request fixture.
 * @param channelId The channel.
 * @return Nothing.
 */
async function seedChannelConsumer(request: APIRequestContext, channelId: string): Promise<void> {
	const authorizationType = `intake-channel-${channelId}`
	const data = {
		name: `E2E ${channelId}`,
		description: 'Seeded by tests/e2e/intake-routing-rules.spec.ts',
		authorizationType,
		authorizationConfiguration: { scheme: 'openconnector', secret: SECRET },
		userId: 'admin',
	}
	const found = await request.get(`${OR_BASE}/consumer`, { failOnStatusCode: false, params: { authorizationType, _limit: 10 } })
	expect(found.status()).toBe(200)
	const existing = ((await found.json()).results ?? []) as Array<Record<string, any>>
	expect(existing.length, `more than one ${authorizationType} consumer`).toBeLessThan(2)
	const resp = existing.length === 1
		? await request.patch(`${OR_BASE}/consumer/${existing[0].id ?? existing[0]['@self']?.id}`, { failOnStatusCode: false, data })
		: await request.post(`${OR_BASE}/consumer`, { failOnStatusCode: false, data })
	expect(resp.status(), 'configuring a channel consumer must succeed').toBeLessThan(300)
}

/**
 * Deliver a signed public space report; it is held while no rule matches.
 *
 * @param request The request fixture.
 * @param description The report's text.
 * @return The delivery answer: `{id, status, reason}`.
 */
async function deliver(request: APIRequestContext, description: string): Promise<Record<string, any>> {
	const body = JSON.stringify({
		id: `MOR-${Date.now()}-${Math.floor(Math.random() * 1e6)}`,
		description,
		location: { latitude: 52.0907, longitude: 5.1214 },
	})
	const resp = await request.post(`${API_BASE}/intake/channels/${CHANNEL}/inbound`, {
		failOnStatusCode: false,
		headers: { 'Content-Type': 'application/json', 'X-OpenConnector-Signature': sign(body) },
		data: body,
	})
	expect(resp.status(), 'a signed delivery must be accepted').toBe(202)
	return resp.json()
}

/**
 * The stored intake message.
 *
 * @param request The request fixture.
 * @param id The message id.
 * @return The message object.
 */
async function message(request: APIRequestContext, id: string): Promise<Record<string, any>> {
	const resp = await request.get(`${OR_BASE}/intake_message/${id}`)
	expect(resp.status()).toBe(200)
	return resp.json()
}

/**
 * Save a rule through the endpoint the rule dialog calls.
 *
 * @param request The request fixture.
 * @param rule The rule.
 * @return The response.
 */
async function saveRule(request: APIRequestContext, rule: Record<string, unknown>) {
	const resp = await request.post(`${API_BASE}/intake/routing-rules`, { failOnStatusCode: false, data: rule })
	if (resp.status() < 300) {
		seededRules.push(String((await resp.json()).id))
	}
	return resp
}

/**
 * The rules stored for a channel.
 *
 * @param request The request fixture.
 * @param channelId The channel.
 * @return The rule objects.
 */
async function rulesOn(request: APIRequestContext, channelId: string): Promise<Array<Record<string, any>>> {
	const resp = await request.get(`${OR_BASE}/intake_routing_rule`, { params: { channelId, _limit: 100 } })
	expect(resp.status()).toBe(200)
	return ((await resp.json()).results ?? []) as Array<Record<string, any>>
}

test.describe('messaging-intake-routing-rules', () => {
	test.beforeAll(async ({ request }) => {
		caseTypeSlug = `e2e_algemene_vraag_${Date.now()}`
		const created = await request.post(`${OR_API}/schemas`, {
			failOnStatusCode: false,
			data: {
				title: 'Algemene vraag (e2e)',
				slug: caseTypeSlug,
				description: 'Case type stand-in for tests/e2e/intake-routing-rules.spec.ts',
				properties: {
					vraag: { type: 'string', title: 'Vraag' },
					omschrijving: { type: 'string', title: 'Omschrijving' },
				},
			},
		})
		expect(created.status(), 'creating the stand-in case type must succeed').toBeLessThan(300)
		caseTypeId = String((await created.json()).id)
		await seedChannelConsumer(request, CHANNEL)
	})

	test.afterEach(async ({ request }) => {
		for (const id of seededRules.splice(0)) {
			await request.delete(`${OR_BASE}/intake_routing_rule/${id}`, { failOnStatusCode: false })
		}
	})

	test.afterAll(async ({ request }) => {
		if (caseTypeId !== '') {
			await request.delete(`${OR_API}/schemas/${caseTypeId}`, { failOnStatusCode: false })
		}
	})

	// @e2e intake-channels::only-a-channel-integriq-has-can-be-picked
	test('the channel list is what the intake registry describes', async ({ request }) => {
		const resp = await request.get(`${API_BASE}/intake/channels`)
		expect(resp.status()).toBe(200)
		const ids = ((await resp.json()).channels as Array<Record<string, unknown>>).map((channel) => channel.channelId)
		expect(ids).toEqual(expect.arrayContaining(['teams', 'public-space-report', 'form-submission']))
		for (const id of ids) {
			expect(id, 'every channel is a slug, not free text').toMatch(/^[a-z0-9-]+$/)
		}

		const unknown = await saveRule(request, { name: 'E2E unknown channel', channelId: 'carrier-pigeon', targetSchema: caseTypeSlug, isEnabled: true, order: 10 })
		expect(unknown.status(), 'a channel the registry does not describe is refused').toBe(400)
	})

	// @e2e intake-channels::an-administrator-routes-teams-messages-to-a-case-type
	test('a Teams rule onto a case type is saved and listed', async ({ request }) => {
		const resp = await saveRule(request, {
			name: 'E2E Teams vragen',
			channelId: 'teams',
			targetSchema: caseTypeSlug,
			fieldMapping: { vraag: 'text' },
			isEnabled: true,
			order: 10,
		})
		expect(resp.status()).toBe(201)
		const { id, rule } = await resp.json()
		expect(rule).toMatchObject({ channelId: 'teams', targetSchema: caseTypeSlug })

		const listed = await rulesOn(request, 'teams')
		expect(listed.map((row) => String(row.id ?? row['@self']?.id))).toContain(id)
	})

	// @e2e intake-channels::a-mapping-onto-a-missing-field-is-refused-in-the-dialog
	test('a mapping onto a field the case type lacks is refused and nothing is stored', async ({ request }) => {
		const name = `E2E straatnaam ${Date.now()}`
		const resp = await saveRule(request, {
			name,
			channelId: CHANNEL,
			targetSchema: caseTypeSlug,
			fieldMapping: { straatnaam: 'text' },
			isEnabled: true,
			order: 10,
		})
		expect(resp.status()).toBe(400)
		expect((await resp.json()).error).toBe(`Case type "${caseTypeSlug}" has no field "straatnaam".`)
		expect((await rulesOn(request, CHANNEL)).map((row) => row.name)).not.toContain(name)
	})

	// @e2e intake-channels::an-administrator-checks-a-condition-before-saving
	test('a draft rule tested against a sample says it matches and what the case would receive', async ({ request }) => {
		const rule = {
			name: 'E2E lantaarnpalen (draft)',
			channelId: CHANNEL,
			targetSchema: caseTypeSlug,
			condition: { field: 'text', operator: 'contains', value: 'lantaarnpaal' },
			fieldMapping: { omschrijving: 'text' },
			isEnabled: true,
			order: 10,
		}
		const before = (await rulesOn(request, CHANNEL)).length

		const hit = await request.post(`${API_BASE}/intake/routing-rules/test`, {
			data: { rule, sample: { text: 'De lantaarnpaal op de hoek brandt niet.' } },
		})
		expect(hit.status()).toBe(200)
		const tested = await hit.json()
		expect(tested.matches).toBe(true)
		expect(tested.payload).toMatchObject({ omschrijving: 'De lantaarnpaal op de hoek brandt niet.' })

		const miss = await request.post(`${API_BASE}/intake/routing-rules/test`, {
			data: { rule, sample: { text: 'Er ligt afval op de stoep.' } },
		})
		expect((await miss.json()).matches).toBe(false)

		expect((await rulesOn(request, CHANNEL)).length, 'a test stores no rule').toBe(before)
	})

	// @e2e intake-channels::a-new-rule-leaves-the-held-queue-alone
	test('saving a rule leaves the held messages held', async ({ request }) => {
		const held = [
			await deliver(request, 'Losliggende stoeptegel bij de bakker'),
			await deliver(request, 'Kapotte bank in het park'),
		]
		for (const delivered of held) {
			expect(delivered.status).toBe('held')
			expect(delivered.reason).toBe(`No routing rule matched channel "${CHANNEL}".`)
		}

		const saved = await saveRule(request, {
			name: 'E2E meldingen',
			channelId: CHANNEL,
			targetSchema: caseTypeSlug,
			fieldMapping: { omschrijving: 'text' },
			isEnabled: true,
			order: 10,
		})
		expect(saved.status()).toBe(201)

		for (const delivered of held) {
			const row = await message(request, delivered.id)
			expect(row.status).toBe('held')
			expect(row.reason).toBe(`No routing rule matched channel "${CHANNEL}".`)
		}
	})

	// @e2e intake-channels::a-report-held-before-its-rule-existed-opens-a-case
	test('"Route again" runs a report held before its rule existed through that rule', async ({ request }) => {
		const delivered = await deliver(request, 'Boom omgewaaid op het fietspad')
		expect(delivered.status).toBe('held')

		const saved = await saveRule(request, {
			name: 'E2E meldingen opnieuw',
			channelId: CHANNEL,
			targetSchema: caseTypeSlug,
			fieldMapping: { omschrijving: 'text' },
			isEnabled: true,
			order: 10,
		})
		expect(saved.status()).toBe(201)

		const routed = await request.post(`${API_BASE}/intake/messages/${delivered.id}/route`)
		expect(routed.status()).toBe(200)

		// With an app that opens the case type the status is `routed`; without
		// one, the message now reaches the rule and waits for that app.
		const row = await message(request, delivered.id)
		if (row.status === 'routed') {
			expect(JSON.stringify(row)).toContain(caseTypeSlug)
		} else {
			expect(row.status).toBe('held')
			expect(row.reason).toBe(`No app opened a "${caseTypeSlug}" for this message.`)
		}
	})

	// @e2e intake-channels::a-fresh-demo-install-shows-working-rules
	test('the seeded rules name channels integriq has and no placeholder', async ({ page, request }) => {
		const resp = await request.get(`${OR_BASE}/intake_routing_rule`, { params: { _limit: 200 } })
		expect(resp.status()).toBe(200)
		const rules = ((await resp.json()).results ?? []) as Array<Record<string, any>>
		test.skip(rules.length === 0, 'no demo data loaded on this instance')
		for (const rule of rules) {
			expect(['teams', 'public-space-report', 'form-submission']).toContain(rule.channelId)
			expect(JSON.stringify(rule)).not.toMatch(/\{\{|%[a-zA-Z]+%|lorem ipsum|TODO|placeholder/i)
		}

		// The page lists them; its cells are not asserted (known bug #996 renders
		// table cells as "—", see spec-coverage/consumer-management.spec.ts).
		await page.goto(`${APP_BASE}/intake/routing-rules`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
	})
})
