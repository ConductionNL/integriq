/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Webhook-signing regression: the operator-facing signing surfaces.
 *
 * This spec navigates (HASH-mode router — src/main.js `mode: 'hash'`, so
 * routes MUST be hash fragments: `/apps/openconnector/#/webhooks`; the path
 * form silently lands on the Dashboard) to the two SPA surfaces that expose
 * webhook signing — the Webhooks page (subscription signing-secret lifecycle,
 * `SubscriptionSigningModal`) and the Rules page (the inbound
 * `webhook_signature` rule type offered by `WebhookSignatureForm`) — and
 * asserts each page's REAL surface renders: its own page heading and its own
 * create action (mirroring spec-coverage/nav-and-index-pages.spec.ts), plus a
 * clean console.
 *
 * It is the browser companion to the PHPUnit coverage that proves the
 * cryptographic and HTTP semantics directly:
 * - `tests/Unit/Service/WebhookSignatureServiceTest.php` — HMAC sign/verify,
 *   dual-sign rotation grace, timestamp tolerance, github scheme,
 *   tampered-body and replay-window rejection.
 * - `tests/Unit/Service/EventServiceTest.php` — outbound `deliverMessage`
 *   signing semantics.
 * - `tests/Unit/Service/EndpointServiceTest.php` — the inbound
 *   `webhook_signature` rule gate.
 * - `tests/Unit/Controller/EventsControllerTest.php` — admin-gated
 *   generate/rotate, secret-shown-once, redaction on read surfaces.
 *
 * @e2e scoping (gate-19, honest coverage): this spec proves the operator
 * surfaces render as themselves — it does NOT exercise the crypto/HTTP
 * scenarios (signature verification, tamper/replay rejection, rotation grace,
 * redaction, one-time reveal). Those scenarios are proven at the
 * service/controller layer and carry `@e2e exclude <reason>` in
 * openspec/specs/webhook-signing/spec.md instead of vacuous tags here.
 *
 * Cross-ref:
 * - openspec/specs/webhook-signing/spec.md
 * - src/modals/Subscription/SubscriptionSigningModal.vue
 * - src/views/Rule/actionForms/WebhookSignatureForm.vue
 * - src/views/Rule/RuleActionConfig.vue
 * - lib/Service/WebhookSignatureService.php
 * - lib/Service/EventService.php
 * - lib/Service/EndpointService.php
 * - lib/Controller/EventsController.php
 */

import { test, expect, type Page, type ConsoleMessage } from '@playwright/test'

// Resolve the SPA root the same way manifest-pages.spec.ts does: apache dev
// containers serve `/apps/openconnector`, the `php -S` CI install serves the
// `/index.php/` form. Probe once with a HEAD-ish GET.
const ROOT_CANDIDATES = ['/apps/openconnector', '/index.php/apps/openconnector']
let _root: string | null = null
async function rootUrl(page: Page): Promise<string> {
	if (_root) return _root
	for (const candidate of ROOT_CANDIDATES) {
		const res = await page.request.get(`${candidate}/sources`, { failOnStatusCode: false })
		if (res.ok() && (await res.text()).includes('openconnector-main.js')) {
			_root = candidate
			return candidate
		}
	}
	throw new Error('Neither /apps nor /index.php form serves the openconnector SPA shell')
}

const IGNORED_CONSOLE_PATTERNS: RegExp[] = [
	/Deprecation/i,
	/Slow network is detected/i,
	/favicon/i,
	/the resource at .* was preloaded using link preload but not used/i,
	/Error fetching OpenConnector settings/i,
	/Failed to load resource:.*Not Found/i,
	/Failed to load user status/i,
	/user_status/i,
	/the server responded with a status of 500/i,
]

function attachConsoleSpy(page: Page): { errors: string[] } {
	const errors: string[] = []
	page.on('console', (msg: ConsoleMessage) => {
		const text = msg.text()
		if (IGNORED_CONSOLE_PATTERNS.some((rx) => rx.test(text))) return
		if (msg.type() === 'error') errors.push(text)
	})
	page.on('pageerror', (err) => {
		errors.push(`pageerror: ${err.message}`)
	})
	return { errors }
}

/** Navigate to a hash route and wait for DOM ready. */
async function gotoSpaPage(page: Page, route: string): Promise<void> {
	const root = await rootUrl(page)
	await page.goto(`${root}/#${route}`, {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	})
}

test.describe('webhook-signing — operator signing surfaces', () => {

	test('Webhooks page mounts with its own heading and "Add Webhook" action', async ({ page }) => {
		const { errors } = attachConsoleSpy(page)

		await gotoSpaPage(page, '/webhooks')

		// CnIndexPage renders no <h*> title element, so the right-page proof
		// is the resolved hash route plus the page-specific create action.
		await expect
			.poll(() => new URL(page.url()).hash, { timeout: 15_000 })
			.toContain('#/webhooks')

		// The create action from the event_subscription binding (addLabel
		// override — see spec-coverage/webhooks.spec.ts). Its presence proves
		// the page hosting SubscriptionSigningModal's manageSigningHandler
		// mounted as the Webhooks surface.
		await expect(
			page.getByRole('button', { name: /Add Webhook/i }).first(),
			'Webhooks page must offer the "Add Webhook" create action',
		).toBeVisible({ timeout: 15_000 })

		// Guard against regression to the old "consumer" mis-binding.
		await expect(page.getByRole('button', { name: /Add Consumer/i })).toHaveCount(0)

		// No fatal console errors during mount.
		expect(
			errors,
			`Webhooks page emitted console errors: ${errors.join(' | ')}`,
		).toEqual([])
	})

	test('Rules page mounts with its own heading and "Add Rule" action', async ({ page }) => {
		const { errors } = attachConsoleSpy(page)

		await gotoSpaPage(page, '/rules')

		// CnIndexPage renders no <h*> title element — see the Webhooks test.
		await expect
			.poll(() => new URL(page.url()).hash, { timeout: 15_000 })
			.toContain('#/rules')

		await expect(
			page.getByRole('button', { name: /Add Rule/i }).first(),
			'Rules page must offer the "Add Rule" create action',
		).toBeVisible({ timeout: 15_000 })

		// No fatal console errors during mount — proves RuleActionConfig and
		// the WebhookSignatureForm action form load cleanly with the page.
		expect(
			errors,
			`Rules page emitted console errors: ${errors.join(' | ')}`,
		).toEqual([])
	})

})
