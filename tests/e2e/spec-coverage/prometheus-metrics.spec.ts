/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/prometheus-metrics/spec.md
 *
 * Tests REQ-PROM-001 through REQ-PROM-006 against the live Nextcloud
 * instance named by PLAYWRIGHT_BASE_URL (admin/admin).
 *
 * The metrics endpoint at GET /index.php/apps/integriq/api/metrics
 * returns Prometheus text exposition format (text/plain; version=0.0.4).
 * These tests verify the HTTP surface contract; they do NOT assert specific
 * numeric values (those change with data in the DB).
 */

import { test, expect } from '@playwright/test'
import * as http from 'http'
import { absoluteUrl } from '../support/baseUrl'

const METRICS_URL = '/index.php/apps/integriq/api/metrics'

test.describe('REQ-PROM-001: Metrics endpoint', () => {
	test('authenticated admin receives 200 with Prometheus text format', async ({
		request,
	}) => {
		// Storage state from globalSetup provides the admin session cookie.
		const resp = await request.get(METRICS_URL, { failOnStatusCode: false })
		expect(resp.status()).toBe(200)

		const ct = resp.headers()['content-type'] ?? ''
		// Spec: content-type MUST be text/plain; version=0.0.4; charset=utf-8
		expect(ct).toMatch(/text\/plain/i)

		const body = await resp.text()
		// Spec: body MUST contain # HELP and # TYPE lines
		expect(body).toMatch(/^# HELP /m)
		expect(body).toMatch(/^# TYPE /m)
	})

	test('unauthenticated request returns 401 (REQ-PROM-001 scenario 2)', async () => {
		// Use Node's built-in http module to make a raw request with no cookies/auth
		// — Playwright's request contexts inherit storage state from the test session,
		// so we bypass Playwright entirely here to get a truly unauthenticated call.
		const status = await new Promise<number>((resolve, reject) => {
			const req = http.get(absoluteUrl(METRICS_URL), (res) => {
				res.resume()
				resolve(res.statusCode ?? 0)
			})
			req.on('error', reject)
			req.setTimeout(10_000, () => {
				req.destroy()
				reject(new Error('timeout'))
			})
		})
		expect(status).toBe(401)
	})
})

test.describe('REQ-PROM-002: openconnector_info gauge', () => {
	test('metrics body contains openconnector_info with version, php_version, nextcloud_version labels', async ({
		request,
	}) => {
		const resp = await request.get(METRICS_URL, { failOnStatusCode: false })
		expect(resp.status()).toBe(200)
		const body = await resp.text()

		// # HELP line
		expect(body).toMatch(/# HELP openconnector_info/i)
		// # TYPE line declares it as gauge
		expect(body).toMatch(/# TYPE openconnector_info gauge/i)
		// Metric line has version, php_version, nextcloud_version labels and value 1
		expect(body).toMatch(
			/openconnector_info\{.*version="[^"]*".*,.*php_version="[^"]*".*,.*nextcloud_version="[^"]*".*\}\s+1/,
		)
	})
})

test.describe('REQ-PROM-003: openconnector_up gauge', () => {
	test('metrics body contains openconnector_up with value 1 on a healthy instance', async ({
		request,
	}) => {
		const resp = await request.get(METRICS_URL, { failOnStatusCode: false })
		expect(resp.status()).toBe(200)
		const body = await resp.text()

		expect(body).toMatch(/# HELP openconnector_up/i)
		expect(body).toMatch(/# TYPE openconnector_up gauge/i)
		// On a healthy instance the value is 1.
		expect(body).toMatch(/^openconnector_up\s+1\s*$/m)
	})
})

test.describe('REQ-PROM-004: openconnector_sources_total gauge', () => {
	test('metrics body contains openconnector_sources_total with type label', async ({
		request,
	}) => {
		const resp = await request.get(METRICS_URL, { failOnStatusCode: false })
		expect(resp.status()).toBe(200)
		const body = await resp.text()

		expect(body).toMatch(/# HELP openconnector_sources_total/i)
		expect(body).toMatch(/# TYPE openconnector_sources_total gauge/i)
		// At least one sources_total line with a type label
		expect(body).toMatch(/openconnector_sources_total\{type="[^"]+"\}\s+\d+/)
	})
})

test.describe('REQ-PROM-005: openconnector_calls_total counter', () => {
	test('metrics body contains openconnector_calls_total with status label', async ({
		request,
	}) => {
		const resp = await request.get(METRICS_URL, { failOnStatusCode: false })
		expect(resp.status()).toBe(200)
		const body = await resp.text()

		expect(body).toMatch(/# HELP openconnector_calls_total/i)
		expect(body).toMatch(/# TYPE openconnector_calls_total counter/i)
		// At least one calls_total line with a status label
		expect(body).toMatch(/openconnector_calls_total\{status="[^"]+"\}\s+\d+/)
	})
})

test.describe('REQ-PROM-006: Synchronization metrics', () => {
	test('metrics body contains openconnector_synchronizations_total and openconnector_synchronization_runs_total', async ({
		request,
	}) => {
		const resp = await request.get(METRICS_URL, { failOnStatusCode: false })
		expect(resp.status()).toBe(200)
		const body = await resp.text()

		expect(body).toMatch(/# HELP openconnector_synchronizations_total/i)
		expect(body).toMatch(/# TYPE openconnector_synchronizations_total gauge/i)
		expect(body).toMatch(/^openconnector_synchronizations_total\s+\d+/m)

		expect(body).toMatch(/# HELP openconnector_synchronization_runs_total/i)
		expect(body).toMatch(
			/# TYPE openconnector_synchronization_runs_total counter/i,
		)
		expect(body).toMatch(
			/openconnector_synchronization_runs_total\{status="[^"]+"\}\s+\d+/,
		)
	})
})
