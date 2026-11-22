/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage:
 * openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md
 * (REQ-PRV-001, REQ-PRV-002, REQ-PRV-003)
 *
 * Every scenario runs on the log provider, the binding that charges nobody:
 * a seeded `payment` source answers its status from
 * `configuration.mockStatuses`, so "the provider reports it paid" is a patch
 * of that map, and the refresh then runs the production path behind
 * POST /api/payments/{id}/refresh. Payments are created through
 * POST /api/payments, as a sibling app creates them.
 *
 * Not here, and why: Mollie in test mode needs a real Mollie test key (an
 * external system), the refused-key, second-refresh, unauthorized-refresh,
 * unknown-provider and RBAC scenarios carry `@e2e exclude` in the spec and are
 * PHPUnit's.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'
import { appDialog } from './support/dialogs.ts'

const API = '/index.php/apps/integriq/api'
const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

/**
 * The OpenRegister id of an object.
 *
 * @param object The object.
 * @return Its id.
 */
function idOf(object: Record<string, any>): string {
	return String(object.id ?? object.uuid ?? object['@self']?.id ?? '')
}

/**
 * Seed a log-provider payment source no other test uses.
 *
 * @param request The Playwright request context.
 * @param name Its name.
 * @param slug Its slug.
 * @return The source's id.
 */
async function seedPaymentSource(request: APIRequestContext, name: string, slug: string): Promise<string> {
	const resp = await request.post(`${OR_BASE}/source`, {
		failOnStatusCode: false,
		data: {
			name,
			slug,
			description: 'Seeded by tests/e2e/payments-review.spec.ts',
			type: 'payment',
			isEnabled: true,
			location: 'https://sandbox.payment.example',
			configuration: { provider: 'log', mockStatuses: {} },
		},
	})
	expect(resp.status()).toBeLessThan(300)

	return idOf(await resp.json())
}

/**
 * Create a payment through the endpoint a sibling app calls.
 *
 * @param request The Playwright request context.
 * @param sourceSlug The payment source.
 * @param description Its description.
 * @return The create envelope.
 */
async function createPayment(request: APIRequestContext, sourceSlug: string, description: string): Promise<Record<string, any>> {
	const resp = await request.post(`${API}/payments`, {
		failOnStatusCode: false,
		data: {
			sourceSlug,
			amount: { value: '45.00', currency: 'EUR' },
			description,
			method: 'ideal',
			redirectUrl: 'https://school.example/ouderbijdrage/return',
		},
	})
	expect(resp.status(), await resp.text()).toBe(200)

	return resp.json()
}

/**
 * Seed one payment_intent row directly, in the given state.
 *
 * @param request The Playwright request context.
 * @param sourceSlug Its source.
 * @param description Its description.
 * @param paymentStatus Its provider status.
 * @return Its id.
 */
async function seedIntent(request: APIRequestContext, sourceSlug: string, description: string, paymentStatus: string): Promise<string> {
	const now = new Date().toISOString()
	const resp = await request.post(`${OR_BASE}/payment_intent`, {
		failOnStatusCode: false,
		data: {
			sourceSlug,
			provider: 'log',
			providerPaymentId: `E2E-${paymentStatus}-${Date.now().toString(36)}`,
			amountValue: '12.50',
			amountCurrency: 'EUR',
			description,
			method: 'ideal',
			redirectUrl: 'https://school.example/return',
			metadata: {},
			paymentStatus,
			createdAt: now,
			updatedAt: now,
		},
	})
	expect(resp.status(), await resp.text()).toBeLessThan(300)

	return idOf(await resp.json())
}

/**
 * Open an index page and walk its pager until a row with the text is shown.
 *
 * @param page The Playwright page.
 * @param route The page's route under the app.
 * @param text Text the row carries.
 */
async function showRow(page: Page, route: string, text: string): Promise<void> {
	await page.goto(`${APP_BASE}${route}`, { waitUntil: 'domcontentloaded' })
	await expect(page.getByTestId('cn-object-row').first()).toBeVisible({ timeout: 20_000 })
	const row = page.getByTestId('cn-object-row').filter({ hasText: text }).first()
	for (let hop = 0; hop < 40; hop++) {
		if (await row.waitFor({ state: 'visible', timeout: 5_000 }).then(() => true).catch(() => false)) {
			return
		}
		const next = page.getByRole('button', { name: 'Next', exact: true }).first()
		if ((await next.isEnabled({ timeout: 2_000 }).catch(() => false)) === false) {
			break
		}
		await next.click()
		await expect(page.getByText(`Page ${hop + 2} of`).first()).toBeVisible({ timeout: 20_000 })
	}
	await expect(row, `a row carrying "${text}" on ${route}`).toBeVisible()
}

test.describe('payments review', () => {
	const marker = `E2E payments ${Date.now().toString(36)}`
	const slug = `e2e-payments-${Date.now().toString(36)}`
	const cleanup: Array<[string, string]> = []
	let sourceId = ''

	test.beforeAll(async ({ request }) => {
		sourceId = await seedPaymentSource(request, marker, slug)
		cleanup.push(['source', sourceId])
	})

	test.afterAll(async ({ request }) => {
		for (const [schema, id] of cleanup.reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false })
		}
	})

	// @e2e live-payment-providers::a-paid-school-fee-shows-as-captured
	// @e2e live-payment-providers::a-payment-behind-a-firewall-is-settled-by-hand
	test('a payment the provider reports paid shows as paid and captured after "Refresh status"', async ({
		page,
		request,
	}) => {
		const description = `${marker} ouderbijdrage`
		const created = await createPayment(request, slug, description)
		cleanup.push(['payment_intent', String(created.paymentIntentId)])
		expect(created.paymentStatus).toBe('open')

		// The provider marks it paid; its webhook never reaches the instance.
		const patched = await request.patch(`${OR_BASE}/source/${sourceId}`, {
			failOnStatusCode: false,
			data: { configuration: { provider: 'log', mockStatuses: { [created.providerPaymentId]: 'paid' } } },
		})
		expect(patched.status()).toBeLessThan(300)

		await showRow(page, '/payments', description)
		const row = page.getByTestId('cn-object-row').filter({ hasText: description }).first()
		await expect(row).toContainText('open')
		await expect(row).toContainText(slug)

		const refreshed = page.waitForResponse((resp) => resp.url().includes(`/api/payments/${created.paymentIntentId}/refresh`))
		await row.getByRole('button').last().click()
		await page.getByRole('menuitem', { name: 'Refresh status' }).click()
		expect((await refreshed).status()).toBe(200)
		await expect(page.getByText('The provider reports this payment as paid.')).toBeVisible({ timeout: 10_000 })

		const stored = await request.get(`${OR_BASE}/payment_intent/${created.paymentIntentId}`)
		expect(stored.status()).toBe(200)
		const intent = await stored.json()
		expect(intent.paymentStatus).toBe('paid')
		expect(intent.lastOutcome).toBe('captured')

		await showRow(page, '/payments', description)
		const after = page.getByTestId('cn-object-row').filter({ hasText: description }).first()
		await expect(after).toContainText('paid')
		await expect(after).toContainText('captured')
	})

	// @e2e live-payment-providers::an-administrator-narrows-the-list-to-open-payments
	test('the open filter leaves only the open payment', async ({ page, request }) => {
		for (const status of ['open', 'paid', 'expired']) {
			cleanup.push(['payment_intent', await seedIntent(request, slug, `${marker} ${status} row`, status)])
		}

		await showRow(page, '/payments', `${marker} open row`)
		await page.getByRole('tab', { name: 'open', exact: true }).click()

		const ours = page.getByTestId('cn-object-row').filter({ hasText: marker })
		await expect(ours.filter({ hasText: `${marker} open row` })).toHaveCount(1, { timeout: 10_000 })
		await expect(ours.filter({ hasText: `${marker} paid row` })).toHaveCount(0)
		await expect(ours.filter({ hasText: `${marker} expired row` })).toHaveCount(0)
		for (const status of await page.getByTestId('cn-object-row').allInnerTexts()) {
			expect(status).toContain('open')
		}
	})

	// @e2e live-payment-providers::an-administrator-activates-mollie-in-test-mode
	test('the source form of a payment source shows the provider step, and the check reports the log provider ready', async ({
		page,
		request,
	}) => {
		const providers = await request.get(`${API}/payments/providers`)
		expect(providers.status()).toBe(200)
		const listed = JSON.stringify(await providers.json())
		expect(listed).toContain('log')
		expect(listed).toContain('mollie')

		await showRow(page, '/sources', marker)
		await page.getByTestId('cn-object-row').filter({ hasText: marker }).first().getByRole('button').last().click()
		await page.getByRole('menuitem', { name: 'Edit' }).click()

		const dialog = appDialog(page)
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		await expect(dialog.getByRole('heading', { name: 'Payment provider' })).toBeVisible()
		const checked = page.waitForResponse((resp) => resp.url().includes('/api/payments/providers/check'))
		await dialog.getByRole('button', { name: 'Check provider' }).click()
		expect((await checked).status()).toBe(200)
		await expect(dialog.getByRole('status')).toHaveText('The log provider is ready. It charges nobody.')
	})
})
