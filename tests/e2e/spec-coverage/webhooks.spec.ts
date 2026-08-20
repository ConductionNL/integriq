/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Genuine behavioral UI coverage for the openconnector Webhooks page.
 *
 * FIXED: src/manifest.json's "Webhooks" page used to be a schema-driven index
 * bound to schema "consumer" (the SAME schema as the Consumers page) — an exact
 * copy. There is no "webhook" schema in OpenRegister (the OR endpoint
 * /api/objects/openconnector/webhook returns 404). Per ADR-013 the event-bus
 * model has no separate webhook entity: a webhook IS an EventSubscription with a
 * delivery `sink` URL + `protocol` + push/pull `style`. The page is now bound to
 * schema "event_subscription" with an `addLabel: "Add Webhook"` override (the
 * schema title is "EventSubscription", so the default would read
 * "Add EventSubscription"). The Webhooks index now lists webhook subscriptions —
 * a distinct surface from Consumers — and its create button reads "Add Webhook".
 */
import { test, expect } from '@playwright/test'
import { navTo, trackErrors, assertNoAppErrors } from './_helpers'

test.describe('Webhooks — index surface', () => {
	// @e2e openconnector-comprehensive-tests::webhooks-page-mounts
	test('Webhooks page renders heading via nav-click without app errors', async ({
		page,
	}) => {
		const sink = trackErrors(page)
		await navTo(page, 'Webhooks', '/webhooks')
		// Schema-driven index pages render via nc-vue CnIndexPage, whose title
		// header is gated behind `showTitle` (default FALSE, not set by the
		// manifest) — so there is no `<h1>/<h2>` page-title heading element on an
		// index page (unchanged between the Vue 2 and Vue 3 builds). navTo already
		// asserts the route resolved; the schema-scoped "Add Webhook" create button
		// is the page-identity signal that DOES render.
		const addBtn = page.getByRole('button', { name: /Add Webhook/i }).first()
		await expect(addBtn, 'Webhooks page must offer a create action').toBeVisible(
			{ timeout: 15_000 },
		)
		assertNoAppErrors(sink)
	})

	// @e2e openconnector-comprehensive-tests::webhooks-create-button-label
	// The Webhooks page is now bound to the "event_subscription" schema (the real
	// webhook entity per ADR-013) with addLabel "Add Webhook". The create button
	// MUST read "Add Webhook" and MUST NOT read "Add Consumer" (the old mis-binding).
	test('Webhooks create button reads "Add Webhook" (bound to event_subscription)', async ({
		page,
	}) => {
		await navTo(page, 'Webhooks', '/webhooks')
		const addWebhook = page.getByRole('button', { name: /Add Webhook/i }).first()
		await expect(
			addWebhook,
			'Webhooks page create button must read "Add Webhook"',
		).toBeVisible({ timeout: 15_000 })
		// Guard against regression to the old "consumer" binding.
		await expect(
			page.getByRole('button', { name: /Add Consumer/i }),
		).toHaveCount(0)
	})
})
