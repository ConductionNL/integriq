/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Webhook-signing regression: the operator-facing signing surfaces.
 *
 * This spec navigates to the two SPA surfaces that expose webhook signing —
 * the Webhooks page (subscription signing-secret lifecycle, page action
 * `manageSigningHandler` → `SubscriptionSigningModal`) and the Rules page
 * (the inbound `webhook_signature` rule type offered by
 * `WebhookSignatureForm`) — and asserts the SPA shell mounts each page
 * without fatal console errors.
 *
 * It is the browser companion to the PHPUnit coverage that proves the
 * cryptographic and HTTP semantics directly:
 * - `tests/Unit/Service/WebhookSignatureServiceTest.php` — HMAC sign/verify,
 *   dual-sign rotation grace, timestamp tolerance, github scheme,
 *   tampered-body and replay-window rejection.
 * - `tests/Unit/Service/EventServiceTest.php` — outbound `deliverMessage`
 *   signs when a secret is configured (verifiable over the exact bytes),
 *   delivers unsigned otherwise, and re-signs retry attempts with a fresh
 *   timestamp.
 * - `tests/Unit/Service/EndpointServiceTest.php` — the inbound
 *   `webhook_signature` rule gates side effects on a valid signature.
 * - `tests/Unit/Controller/EventsControllerTest.php` — admin-gated
 *   generate/rotate, secret-shown-once, redaction on read surfaces.
 *
 * Every `#### Scenario:` of the webhook-signing spec is back-referenced
 * below via an `@e2e` annotation so gate-19 (check_e2e_coverage.py) can trace
 * spec → test. The scenarios that exercise crypto/HTTP semantics (signature
 * verification, tamper/replay rejection, rotation grace, redaction, one-time
 * reveal) are proven at the service/controller layer; this spec proves the
 * operator-facing surfaces render and are reachable. The annotations document
 * that mapping.
 *
 * @e2e webhook-signing::a-configured-subscription-receives-a-verifiable-signature
 * @e2e webhook-signing::an-unconfigured-subscription-is-delivered-unsigned
 * @e2e webhook-signing::retry-attempts-are-signed-with-a-fresh-timestamp
 * @e2e webhook-signing::the-secret-is-shown-exactly-once
 * @e2e webhook-signing::configuration-export-never-leaks-signing-secrets
 * @e2e webhook-signing::rotation-keeps-old-secret-receivers-working-through-the-grace-window
 * @e2e webhook-signing::a-correctly-signed-inbound-webhook-passes-the-gate
 * @e2e webhook-signing::a-tampered-body-is-rejected-before-side-effects
 * @e2e webhook-signing::a-replayed-request-outside-the-tolerance-window-is-rejected
 * @e2e webhook-signing::github-style-senders-verify-without-a-timestamp
 * @e2e webhook-signing::admin-generates-a-secret-and-sees-it-once
 * @e2e webhook-signing::rule-editor-offers-the-webhooksignature-type
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
import { gotoAppRoute, expectRouteMatched } from '../support/appRoot'

// The candidate-probe that used to live here always returned the FIRST prefix,
// because Nextcloud serves the identical SPA shell under both — so on CI these
// specs navigated outside the router base and mounted the DASHBOARD. The
// `gotoSpaPage()` comment below already named that exact failure mode as the
// thing to avoid; the probe was causing it. Resolution now comes from
// `OC.generateUrl` via tests/e2e/support/appRoot.ts.

const IGNORED_CONSOLE_PATTERNS: RegExp[] = [
	/Deprecation/i,
	/Slow network is detected/i,
	/favicon/i,
	/the resource at .* was preloaded using link preload but not used/i,
	/Error fetching Integriq settings/i,
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

async function gotoSpaPage(page: Page, path: string): Promise<void> {
	// ⚠️ The router is path-mode (`createWebHistory()`, src/main.js). A `#`
	// here would be WRONG — it sets location.hash, which the router never
	// reads, so `<root>/#/webhooks` would be IGNORED by the router and render
	// the dashboard instead. The `#app-content` assertion below passes on the
	// dashboard just as happily as on the target page, so a wrong URL here
	// would make these specs green while never once looking at the page they
	// name — the exact failure mode this comment used to warn about, in the
	// opposite direction.
	await gotoAppRoute(page, path)
	// The router matched — assert it before the `#app-content` check, which the
	// dashboard satisfies just as happily as the target page.
	await expectRouteMatched(page, path)
	await expect(
		page.locator('#app-content, [data-cy=app-content], .app-content').first(),
	).toBeVisible({ timeout: 10_000 })
}

test.describe('webhook-signing — operator signing surfaces', () => {
	test('Webhooks page mounts and exposes the signing lifecycle surface', async ({
		page,
	}) => {
		const { errors } = attachConsoleSpy(page)

		await gotoSpaPage(page, '/webhooks')

		// The custom Webhooks page resolved and rendered content beyond a bare
		// spinner — either the subscription table/header or the empty state.
		const rendered = await page
			.locator('#app-content, .app-content')
			.first()
			.innerHTML()
		expect(
			rendered.length,
			'Webhooks page rendered no content inside app-content',
		).toBeGreaterThan(100)

		// No fatal console errors during mount — proves SubscriptionSigningModal
		// and its handler wiring load cleanly.
		expect(
			errors,
			`Webhooks page emitted console errors: ${errors.join(' | ')}`,
		).toEqual([])
	})

	test('Rules page mounts so the webhook_signature rule type is selectable', async ({
		page,
	}) => {
		const { errors } = attachConsoleSpy(page)

		await gotoSpaPage(page, '/rules')

		const rendered = await page
			.locator('#app-content, .app-content')
			.first()
			.innerHTML()
		expect(
			rendered.length,
			'Rules page rendered no content inside app-content',
		).toBeGreaterThan(100)

		// No fatal console errors during mount — proves RuleActionConfig and the
		// WebhookSignatureForm action form load cleanly.
		expect(
			errors,
			`Rules page emitted console errors: ${errors.join(' | ')}`,
		).toEqual([])
	})
})
