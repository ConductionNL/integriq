/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md
 * (REQ-OTEL-005, export is configured by an administrator).
 *
 * What an administrator does here: open the integriq admin settings, switch
 * trace export on with a collector address and a sampling share, and save.
 * An address that is not https is refused unless it is marked as internal.
 *
 * The export itself (a POST to the collector from a background job) is
 * covered by PHPUnit: a browser cannot see a cron job's outbound call.
 *
 * Error sink: openspec/changes/archive/2026-11-24-observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md
 * (REQ-OTEL-008, the error sink is configured on the admin page). An
 * administrator switches the sink on with a Sentry DSN and the default apps
 * and saves; a plain http address not marked internal is refused with the
 * reason shown. The settings read before each error sink test are written
 * back after it. That the next openregister error is queued for the DSN
 * (REQ-OTEL-006), the refusal of an enabled sink without an endpoint, and the
 * span command (REQ-OTEL-007) are backend behaviour, covered by PHPUnit
 * (ErrorSinkListenerTest, SettingsServiceTest, SpanExportRequestedListenerTest).
 */

import { expect, test } from '@playwright/test'

const CONFIG_URL = '/index.php/apps/integriq/api/admin/otel'
const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

test.describe('opentelemetry export settings', () => {
	test.afterEach(async ({ request }) => {
		await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { enabled: false, endpoint: '', samplingRatio: 0.1 },
		})
	})

	// @e2e execution-trace::an-administrator-switches-export-on
	test('an administrator switches export on', async ({ page, request }) => {
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		const section = page.getByTestId('admin-otel-section')
		await expect(section).toBeVisible({ timeout: 20_000 })

		// NcTextField and NcCheckboxRadioSwitch put data-testid on the input itself.
		await page
			.getByTestId('admin-otel-endpoint')
			.fill('https://otel.example.org:4318')
		await page.getByTestId('admin-otel-sampling').fill('10')
		await page.getByTestId('admin-otel-enabled').check({ force: true })
		await page.getByTestId('admin-otel-save').click()

		await expect
			.poll(
				async () => {
					const stored = await request.get(CONFIG_URL, {
						headers: HEADERS,
					})
					return stored.json()
				},
				{ timeout: 10_000 },
			)
			.toMatchObject({
				enabled: true,
				endpoint: 'https://otel.example.org:4318',
				samplingRatio: 0.1,
			})
	})

	test('a plain http collector is refused unless marked internal', async ({
		request,
	}) => {
		const refused = await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { enabled: true, endpoint: 'http://collector:4318' },
		})
		expect(refused.status()).toBe(400)

		const internal = await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: {
				enabled: true,
				endpoint: 'http://collector:4318',
				allowLocal: true,
			},
		})
		expect(internal.status()).toBe(200)
	})
})

const ERROR_SINK_URL = '/index.php/apps/integriq/api/admin/errorsink'

/** The error sink apps an administrator gets unless they change them. */
const DEFAULT_ERROR_SINK_APPS = ['integriq', 'openregister', 'opencatalogi']

test.describe('error sink settings', () => {
	let original: Record<string, unknown> = {}

	test.beforeEach(async ({ request }) => {
		const stored = await request.get(ERROR_SINK_URL, { headers: HEADERS })
		expect(stored.status()).toBe(200)
		original = await stored.json()
	})

	test.afterEach(async ({ request }) => {
		await request.put(ERROR_SINK_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: original,
		})
	})

	// @e2e execution-trace::an-administrator-points-errors-at-sentry
	test('an administrator points errors at Sentry', async ({ page, request }) => {
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		const section = page.getByTestId('admin-errorsink-section')
		await expect(section).toBeVisible({ timeout: 20_000 })
		await expect(page.getByTestId('admin-errorsink-endpoint')).toBeVisible({ timeout: 20_000 })

		await page.getByTestId('admin-errorsink-enabled').check({ force: true })
		await page.getByTestId('admin-errorsink-kind').locator('input').first().click()
		await page
			.getByRole('option', { name: 'Sentry compatible service' })
			.first()
			.click()
		await page
			.getByTestId('admin-errorsink-endpoint')
			.fill('https://e2ekey@sentry.example.org/42')
		await expect(page.getByTestId('admin-errorsink-apps')).toHaveValue(
			DEFAULT_ERROR_SINK_APPS.join(', '),
		)
		await page.getByTestId('admin-errorsink-save').click()

		await expect
			.poll(
				async () => {
					const stored = await request.get(ERROR_SINK_URL, {
						headers: HEADERS,
					})
					return stored.json()
				},
				{ timeout: 10_000 },
			)
			.toMatchObject({
				enabled: true,
				kind: 'sentry',
				endpoint: 'https://e2ekey@sentry.example.org/42',
				apps: DEFAULT_ERROR_SINK_APPS,
			})
	})

	test('a plain http error service is refused unless marked internal', async ({
		page,
		request,
	}) => {
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		const section = page.getByTestId('admin-errorsink-section')
		await expect(page.getByTestId('admin-errorsink-endpoint')).toBeVisible({ timeout: 20_000 })

		await page.getByTestId('admin-errorsink-enabled').check({ force: true })
		await page.getByTestId('admin-errorsink-kind').locator('input').first().click()
		await page
			.getByRole('option', { name: 'OpenTelemetry collector (OTLP logs)' })
			.first()
			.click()
		await page
			.getByTestId('admin-errorsink-endpoint')
			.fill('http://collector:4318')
		await page.getByTestId('admin-errorsink-allow-local').uncheck({ force: true })
		await page.getByTestId('admin-errorsink-save').click()

		await expect(section.getByRole('alert')).toContainText('https', {
			timeout: 10_000,
		})
		const stored = await request.get(ERROR_SINK_URL, { headers: HEADERS })
		expect((await stored.json()).endpoint).toBe(original.endpoint)

		await page.getByTestId('admin-errorsink-allow-local').check({ force: true })
		await page.getByTestId('admin-errorsink-save').click()
		await expect
			.poll(
				async () => {
					const after = await request.get(ERROR_SINK_URL, {
						headers: HEADERS,
					})
					return after.json()
				},
				{ timeout: 10_000 },
			)
			.toMatchObject({
				enabled: true,
				kind: 'otlp-logs',
				endpoint: 'http://collector:4318',
				allowLocal: true,
			})
	})
})
