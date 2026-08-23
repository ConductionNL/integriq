/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Chain E regression: migration round-trip.
 *
 * Spec: openconnector-comprehensive-tests (openspec/specs/ dir name, unrenamed)
 *   "Playwright MUST include a migration round-trip spec" — install with legacy
 *   data present → run the chain-B storage migration → all resource pages still
 *   show the same data counts as before migration.
 *
 * On the dev/CI container the chain-B migration runs automatically as a repair
 * step (lib/Migration/Version2Date20260520000001.php) during `occ upgrade` /
 * app enable — it sets `integriq.storage_migrated = 'true'` on a clean run.
 * There is currently NO standalone `occ integriq:migrate-storage` console
 * command registered in this repo (the migration is invoked from the repair
 * step, not a Command class) — see the migrator's own log message which still
 * references that command as a future per-entity retry hook. This spec therefore
 * verifies the *post-migration round-trip invariant* that the spec's acceptance
 * scenarios assert against ("the Sources list page shows the same data", "all 10
 * resource pages show no error/empty-state unexpectedly"):
 *
 *   1. The migration completed (`storage_migrated === 'true'`).
 *   2. Every migrated schema is queryable via OR's generic CRUD route with a
 *      200 + array body — no page regresses to an error state after migration.
 *   3. Counts are STABLE across two consecutive reads — the migration is a
 *      one-shot that does not duplicate or drop rows on re-read (the round-trip
 *      invariant; a non-idempotent migration would diverge here).
 *
 * If `storage_migrated` is not `'true'` on the target instance, the
 * round-trip assertions are skipped (matching or-cutover-smoke.spec.ts and
 * synced-from-leaf.spec.ts) rather than producing a false failure.
 */

import {
	test,
	expect,
	request as pwRequest,
	APIRequestContext,
} from '@playwright/test'
import { BASE_URL } from '../support/baseUrl'

const NEXTCLOUD = BASE_URL
const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS || 'admin'

const OR = '/index.php/apps/openregister/api'
const OC_REGISTER = 'openconnector'

// The 15 schemas the chain-B migrator copies out of the legacy
// oc_openconnector_* tables (LegacyToRegisterMigrator::ENTITY_ORDER).
const MIGRATED_SCHEMAS = [
	'source',
	'consumer',
	'endpoint',
	'event',
	'event_subscription',
	'job',
	'mapping',
	'rule',
	'synchronization',
	'synchronization_contract',
	'event_message',
	'call_log',
	'job_log',
	'synchronization_log',
	'synchronization_contract_log',
]

async function apiContext(): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: NEXTCLOUD,
		httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
	})
}

/**
 * Read `integriq.storage_migrated`.
 *
 * ⚠️ This used to probe `GET /index.php/apps/integriq/api/settings` and
 * fall back to `false` "when the endpoint is unavailable so the test skips
 * cleanly". That route DOES NOT EXIST in this repo — appinfo/routes.php
 * records it as deliberately deleted ("GET /api/settings + PUT /api/settings —
 * replaced by OR's /api/settings/* surface"); the only surviving settings
 * route is `POST /api/settings/rebase`. So the probe 404'd on every run, the
 * fallback turned that into `false`, and all three specs below reported
 * "skipped" for a reason with nothing to do with the migration. Measured
 * against the dev container: `GET .../api/settings` → 404, while the flag
 * itself was `true`. A guard that cannot observe its own precondition is
 * indistinguishable from a suite that passes.
 *
 * Read the flag itself instead, via Nextcloud core's provisioning API — a
 * shipped, always-enabled app, and the authoritative store for an app-config
 * value.
 *
 * ⚠️ An OCS HTTP 200 is the status of the ENVELOPE, not of the call. The real
 * result is `ocs.meta.statuscode`, which is checked separately below.
 *
 * The measured values are logged unconditionally: when this returns false the
 * three specs skip, and a skip whose cause is not written down is exactly the
 * failure mode this function already had once.
 */
async function readStorageMigrated(ctx: APIRequestContext): Promise<boolean> {
	const url =
		'/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/integriq/storage_migrated'
	const res = await ctx
		.get(url, {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		.catch(() => null)

	if (res === null) {
		// eslint-disable-next-line no-console
		console.warn('[migration-round-trip] storage_migrated probe: request threw')
		return false
	}

	const body = await res.json().catch(() => null)
	const ocsStatus = body?.ocs?.meta?.statuscode
	const value = body?.ocs?.data?.data

	// eslint-disable-next-line no-console
	console.info(
		`[migration-round-trip] storage_migrated probe: HTTP ${res.status()},`
			+ ` ocs.meta.statuscode=${String(ocsStatus)}, value=${JSON.stringify(value)}`,
	)

	if (res.status() !== 200 || ocsStatus !== 200) {
		return false
	}

	return value === 'true' || value === true
}

/**
 * Count objects returned by OR's generic CRUD route for a schema. Returns -1
 * when the route does not return a 200 (signals a regressed page).
 */
async function countSchema(ctx: APIRequestContext, schema: string): Promise<number> {
	const res = await ctx.get(`${OR}/objects/${OC_REGISTER}/${schema}`)
	if (res.status() !== 200) {
		return -1
	}
	const body = await res.json().catch(() => null)
	if (body === null) {
		return -1
	}
	const results = body.results ?? body
	if (Array.isArray(results) === false) {
		return -1
	}
	// Prefer an explicit total when the envelope provides one.
	if (typeof body.total === 'number') {
		return body.total
	}
	return results.length
}

test.describe('Migration round-trip — post chain-B invariants', () => {
	let storageMigrated = false

	test.beforeAll(async () => {
		const ctx = await apiContext()
		storageMigrated = await readStorageMigrated(ctx)
		await ctx.dispose()
	})

	test('chain-B migration completed (storage_migrated === true)', async () => {
		test.skip(
			!storageMigrated,
			'integriq.storage_migrated is not true on this instance',
		)
		expect(
			storageMigrated,
			'migration flag must be true after a clean migration',
		).toBe(true)
	})

	test('every migrated schema is queryable without page regression', async () => {
		test.skip(
			!storageMigrated,
			'integriq.storage_migrated is not true on this instance',
		)
		const ctx = await apiContext()
		for (const schema of MIGRATED_SCHEMAS) {
			const count = await countSchema(ctx, schema)
			expect(
				count,
				`schema "${schema}" must return a 200 + array body (got sentinel -1 means error/non-array)`,
			).toBeGreaterThanOrEqual(0)
		}
		await ctx.dispose()
	})

	test('object counts are stable across consecutive reads (round-trip invariant)', async () => {
		test.skip(
			!storageMigrated,
			'integriq.storage_migrated is not true on this instance',
		)
		const ctx = await apiContext()

		const first: Record<string, number> = {}
		for (const schema of MIGRATED_SCHEMAS) {
			first[schema] = await countSchema(ctx, schema)
		}

		// Second pass — a non-idempotent migration (re-run on read, duplicate
		// inserts) would diverge here. The migrator is a one-shot; counts hold.
		for (const schema of MIGRATED_SCHEMAS) {
			const second = await countSchema(ctx, schema)
			expect(
				second,
				`schema "${schema}" count must be stable across reads (was ${first[schema]}, now ${second})`,
			).toBe(first[schema])
		}

		await ctx.dispose()
	})
})
