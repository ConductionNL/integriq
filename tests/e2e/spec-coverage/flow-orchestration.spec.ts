/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/flow-orchestration/spec.md (legacy backend,
 *                                                            REQ-001–008 only)
 *                openspec/specs/execution-trace/spec.md    (REQ-007)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * REQ-009/010/011 REMOVED 2026-08-16 — flow-engine-unification task 6.2
 * ─────────────────────────────────────────────────────────────────────────────
 * This file used to cover REQ-009 (the typed step-list editor: FlowStepRow,
 * keyboard reorder, config-ref picker scoped by step type) and REQ-010/011
 * (the draft/dirty Save-Discard contract on that same editor). That UI no
 * longer exists: FlowDetailPage.vue now renders only the shared
 * `CnFlowDetail` canvas from @conduction/nextcloud-vue over OpenRegister's
 * native flow store (nodes[]/edges[]), not this app's own `flow`/`steps[]`
 * schema the old editor was built on. See
 * openspec/specs/flow-orchestration/spec.md's 2026-08-16 scope note and
 * openconnector#1255 for the full backend-state writeup.
 *
 * The shared canvas's own editor behaviour (dirty tracking, node palette,
 * keyboard operability) is `@conduction/nextcloud-vue`'s to test — duplicating
 * it per consuming app is exactly the kind of coverage that silently drifts
 * from what the shared component actually does. What THIS app still owns and
 * must keep proving: the `/flows` and `/flows/:id` routes exist, resolve to
 * the shared components with the right `app="openconnector"` scoping, and
 * survive a real reload — see the new describe block below.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IS DELIBERATELY *NOT* COVERED HERE
 * ─────────────────────────────────────────────────────────────────────────────
 * The index list's exact columns (Name / Trigger / Status, measured
 * 2026-08-16 — a different shape than the old Name/Enabled/Description table
 * openconnector#1214 was filed against) are not pinned here. `CnFlowIndexPage`
 * owns that presentation; pinning its column set from a consumer app couples
 * this file to a shared-library layout choice it doesn't control.
 *
 * REQ-007 (execution-trace, Trace detail / Replay) is UNRELATED to this
 * migration — the trace surface never used the old step-list editor — and is
 * unchanged below.
 */
import type { Browser, Page } from '@playwright/test'
import type { ApiClient } from '../workflows/_fixture'

import { expect, test } from '@playwright/test'
import { createObject, deleteObject, makeApiClient } from '../workflows/_fixture'
import { APP_BASE } from './_helpers'
import { resolveAppRoot, expectRouteMatched } from '../support/appRoot'

/** OpenRegister's native flow store — a different backend than OR_BASE/OC_API in _fixture.ts, which target openconnector's legacy `flow` schema. */
const FLOWS_API = '/index.php/apps/openregister/api/flows'

/** Unique per-run marker so every assertion can scope to this run's rows. */
const runId = `flow-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`

/**
 * Per-describe fixture ownership.
 *
 * NOT STYLE. The `regression` project sets `fullyParallel: true`, so Playwright
 * splits one file into one run-group PER `test.describe`. A file-level
 * `afterAll` then fires when the FIRST group finishes and the next group's
 * `beforeAll` hits `apiRequestContext.post: Request context disposed` — which
 * Playwright reports as a 60s "beforeAll hook timeout", i.e. as a slow API
 * rather than as a lifecycle bug, taking the rest of the file down as
 * "did not run". Each describe owns its own client and tears down its own rows.
 */
class Fixtures {
	api!: ApiClient
	private created: Array<{ schema: string; id: string }> = []

	async open(browser: Browser, baseURL: string): Promise<void> {
		this.api = await makeApiClient(browser, baseURL)
	}

	async make(schema: string, data: Record<string, any>): Promise<any> {
		const obj = await createObject(this.api, schema, data)
		const id = obj.id ?? obj.uuid
		if (!id) {
			throw new Error(
				`createObject(${schema}) returned no id/uuid — cannot track it for cleanup: ${JSON.stringify(obj).slice(0, 300)}`,
			)
		}
		this.created.push({ schema, id })
		return obj
	}

	/**
	 * Create an object WITHOUT registering it for teardown.
	 *
	 * For schemas OpenRegister refuses to delete. `execution_trace` declares
	 * `x-openregister-archival`, and a delete comes back
	 * `403 SCHEMA_ARCHIVAL_IMMUTABLE: … user-driven delete operations are not
	 * permitted. Rows expire automatically via the ArchivalRetentionTask cron.`
	 * Tracking it anyway would print a 403 on every run and train the reader to
	 * ignore teardown errors, which is how a real leak gets missed. The row is
	 * left to the retention task, which is the mechanism the schema documents.
	 */
	async makeUntracked(schema: string, data: Record<string, any>): Promise<any> {
		return createObject(this.api, schema, data)
	}

	async close(): Promise<void> {
		for (const { schema, id } of this.created.reverse()) {
			await deleteObject(this.api, schema, id)
		}
		this.created = []
		await this.api?.dispose()
	}
}

/**
 * Create a flow in OpenRegister's NATIVE flow store — a different backend
 * than `Fixtures.make('flow', …)` above, which targets openconnector's
 * legacy `register=openconnector, schema=flow` schema. `/flows/:id` now
 * renders the shared canvas over this store, not that one.
 */
async function createFlow(api: ApiClient, data: Record<string, any>): Promise<any> {
	const resp = await api.request.post(FLOWS_API, { data, failOnStatusCode: false })
	if (!resp.ok()) {
		throw new Error(`createFlow failed: ${resp.status()} ${await resp.text()}`)
	}
	return resp.json()
}

/** Delete a flow from the native store by id. Best-effort — used only in teardown. */
async function deleteFlow(api: ApiClient, id: string): Promise<void> {
	await api.request.delete(`${FLOWS_API}/${id}`, { failOnStatusCode: false })
}

/**
 * Resolve the app root URL by probing, rather than trusting the shared
 * `APP_BASE` constant (which hardcodes `/index.php/`, correct for CI's
 * router-less `php -S` server).
 *
 * Measured 2026-08-16: on this Apache-based dev container, a DEEP path
 * under `/index.php/apps/openconnector/<route>` (e.g. `/traces/<id>`)
 * redirects to the bare `/apps/openconnector/` app root, discarding the
 * route — a real, pre-existing Nextcloud redirect that path-mode routing
 * now exposes (hash-mode was accidentally immune: a fragment with no
 * `#` of its own in the redirect's Location header is re-appended by the
 * browser, so the OLD hash deep-links survived this redirect by luck, not
 * because it didn't happen). `${APP_BASE}/traces/${id}` in the REQ-007
 * block below still uses the shared constant and is expected to fail
 * locally for this reason — pre-existing, unrelated to this migration, and
 * NOT reproduced in CI, which has no such redirect. This local prober
 * exists so the NEW tests below don't inherit that failure.
 *
 * 🔴 The probe it originally used (try each candidate, take the first that
 * serves the SPA shell) could not do that job: BOTH prefixes serve the
 * identical shell, so it always returned `/apps/openconnector` — right for
 * this dev container by luck, wrong for CI, where the router base is the
 * `/index.php/` form. The two tests in this file that used it failed in CI
 * while the two that use `APP_BASE` passed, in the same run — a controlled
 * comparison inside one file. Resolution now comes from `OC.generateUrl` via
 * tests/e2e/support/appRoot.ts, which is correct in both environments because
 * it is the function `src/main.js` itself calls to build the router base.
 */
async function resolveRoot(page: Page): Promise<string> {
	return await resolveAppRoot(page)
}

/* ===========================================================================
 * flow-engine-unification task 6.2 — the shared canvas, scoped to this app
 * ======================================================================== */
test.describe('Flows — the shared canvas, scoped to app=openconnector', () => {
	let api: ApiClient
	let flowId = ''
	const FLOW_NAME = `${runId} canvas smoke`

	test.beforeAll(async ({ browser, baseURL }) => {
		api = await makeApiClient(browser, baseURL!)
		const flow = await createFlow(api, {
			name: FLOW_NAME,
			description: 'flow-orchestration spec-coverage fixture — safe to delete',
			app: 'openconnector',
			enabled: true,
			trigger: 'manual',
			nodes: [
				{
					id: 'trigger1',
					type: 'openregister.trigger-manual',
					config: [],
					position: { x: 80, y: 160 },
				},
				{
					id: 'step1',
					type: 'openconnector.synchronization-run',
					// A syntactically valid config (validateConfig() only requires a
					// non-empty string) — this test proves the CANVAS renders the
					// seeded graph, not that the referenced synchronization exists.
					config: { synchronization: runId },
					position: { x: 320, y: 160 },
				},
			],
			edges: [{ id: 'e1', from: 'trigger1', to: 'step1', title: 'start' }],
		})
		flowId = flow.id ?? flow.uuid
	})
	test.afterAll(async () => {
		if (flowId) await deleteFlow(api, flowId)
		await api?.dispose()
	})

	// @e2e flow-orchestration::flows-index-page-mounts-and-lists-flows
	// @e2e flow-orchestration::the-flows-index-lists-only-this-apps-flows
	test('the flows index lists this app-scoped flow by name', async ({
		page,
	}: {
		page: Page
	}) => {
		const root = await resolveRoot(page)
		await page.goto(`${root}/flows`, { waitUntil: 'domcontentloaded' })
		await expectRouteMatched(page, '/flows')
		await expect(
			page.getByText(FLOW_NAME),
			'the seeded flow must appear in the openconnector-scoped list',
		).toBeVisible({ timeout: 20_000 })
	})

	// `flow-engine-unification` is an OpenRegister change name, not a capability
	// in this repository — the anchor resolved to nothing here. The scenario it
	// meant is `flow-orchestration` REQ-017, which is where this app's own
	// adoption of the shared canvas is written down.
	// @e2e flow-orchestration::flow-detail-renders-the-shared-canvas-and-survives-a-hard-reload
	test('flow detail renders the shared canvas with the seeded nodes, and survives a hard reload', async ({
		page,
	}: {
		page: Page
	}) => {
		const root = await resolveRoot(page)
		await page.goto(`${root}/flows/${flowId}`, { waitUntil: 'domcontentloaded' })
		await expectRouteMatched(page, `/flows/${flowId}`)

		// The Name field mirrors the loaded flow — proves the canvas mounted
		// bound to THIS flow, not an empty "new flow" shell.
		await expect(
			page.getByRole('textbox', { name: /^Name/ }).first(),
			'the canvas must mount and load the seeded flow, not an empty shell',
		).toHaveValue(FLOW_NAME, { timeout: 25_000 })

		// The two seeded nodes render as placed canvas nodes. Nodes' accessible
		// name IS their node id ("trigger1"/"step1") — the Steps palette's own
		// list items are never named this way, so this can't accidentally match
		// the palette instead of the canvas.
		await expect(
			page.getByRole('button', { name: 'trigger1' }),
			'the seeded trigger node must render on the canvas',
		).toBeVisible()
		await expect(
			page.getByRole('button', { name: 'step1' }),
			'the seeded synchronization-run node must render on the canvas',
		).toBeVisible()

		// A hard reload (not client-side nav) must still resolve to the same
		// flow. This is the router-history-mode conversion's own guarantee
		// (createWebHistory + the appinfo/routes.php catch-all) — only a real
		// document reload, not an in-app link click, proves it.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(
			page.getByRole('textbox', { name: /^Name/ }).first(),
			'a hard reload of the flow detail URL must resolve to the same flow, not the dashboard',
		).toHaveValue(FLOW_NAME, { timeout: 25_000 })
	})
})

/* ===========================================================================
 * execution-trace REQ-007 — the trace detail surface
 * ======================================================================== */
test.describe('Trace detail — timeline and replay safety (execution-trace REQ-007)', () => {
	const fx = new Fixtures()
	let traceId = ''

	test.beforeAll(async ({ browser, baseURL }) => {
		await fx.open(browser, baseURL!)
		// `traceId` is `format: uuid` and OpenRegister ENFORCES it even though
		// it is not in `required` — seeding a readable marker there was
		// rejected with 400. The run marker lives on `entryPointId` instead.
		const trace = await fx.makeUntracked('execution_trace', {
			traceId: crypto.randomUUID(),
			entryPoint: 'endpoint',
			entryPointId: `${runId}-endpoint`,
			status: 'failed',
			startedAt: '2026-08-11T10:00:00+00:00',
			finishedAt: '2026-08-11T10:00:03+00:00',
			durationMs: 3120,
			error: { message: `${runId} upstream refused the call` },
			steps: [
				{
					order: 1,
					type: 'mapping',
					name: `${runId} map inbound`,
					status: 'success',
					durationMs: 12,
					input: { token: '[REDACTED]', id: 'abc' },
					output: { ok: true },
				},
				{
					order: 2,
					type: 'call',
					name: `${runId} upstream call`,
					status: 'failed',
					durationMs: 3100,
					input: { url: 'https://example.invalid/alpha' },
					output: { error: 'refused' },
				},
			],
		})
		traceId = trace.id ?? trace.uuid
	})
	test.afterAll(async () => {
		await fx.close()
	})

	// @e2e execution-trace::operator-inspects-a-traces-step-timeline
	test('a failed trace renders an ordered timeline whose steps expand to their redacted payloads', async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/traces/${traceId}`, {
			waitUntil: 'domcontentloaded',
		})

		const timeline = page.getByTestId('trace-timeline')
		await expect(
			timeline,
			'the step timeline must mount — an empty page would satisfy every "is not shown" assertion below for free',
		).toBeVisible({ timeout: 25_000 })

		// Type, duration and status per step, in order.
		//
		// The widget TITLE-CASES the step type for display (`mapping` renders
		// as `Mapping`), so these match case-insensitively against the seeded
		// value rather than against the rendered casing — asserting the exact
		// rendered string would pin a presentation choice, not the requirement.
		const rendered = (await timeline.innerText()).replace(/\s+/g, ' ')
		expect(rendered, 'step 1 type').toMatch(/mapping/i)
		expect(rendered, 'step 2 type').toMatch(/call/i)
		expect(rendered, 'a per-step duration must be shown').toMatch(
			/3100\s*ms|3\.1\s*s/,
		)
		expect(rendered, 'a per-step status must be shown').toMatch(/failed/i)
		expect(
			rendered,
			'and the succeeding step is distinguished from the failing one',
		).toMatch(/success/i)
		// The two steps must render in `order`, not in whatever order the
		// payload happened to arrive in.
		expect(
			rendered.search(/map inbound/i),
			'the timeline is ORDERED — step 1 must render before step 2',
		).toBeLessThan(rendered.search(/upstream call/i))

		// The trace-level error surfaces as its own element, not only inside
		// the timeline text.
		await expect(page.getByTestId('trace-error')).toContainText(
			'upstream refused the call',
		)

		// EXPANSION IS THE REQUIREMENT — and "the payload is not shown" is an
		// absence claim, so it is asserted BEFORE and AFTER the same click on
		// the same locator.
		await expect(
			page.getByTestId('step-detail'),
			'payloads must be collapsed until asked for — a trace can carry redacted secrets',
		).toHaveCount(0)

		await timeline.locator('li').nth(1).getByRole('button').first().click()
		const detail = page.getByTestId('step-detail')
		await expect(
			detail,
			'expanding a step must reveal its snapshot',
		).toHaveCount(1)
		await expect(
			detail,
			'the expanded snapshot is the step input/output',
		).toContainText('example.invalid')
	})

	// @e2e execution-trace::replay-defaults-to-dry-run-with-a-confirmation-step-for-force
	test('Replay offers only a dry-run first, and a forced replay needs a second explicit confirmation', async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/traces/${traceId}`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.getByTestId('trace-timeline')).toBeVisible({
			timeout: 25_000,
		})

		// THE REQUIREMENT, first half: before anything is clicked, the ONLY
		// replay control offered is the dry run. Both halves are asserted —
		// the destructive controls are absent AND the safe one is present, so
		// an unrendered replay section fails rather than passes.
		await expect(
			page.getByRole('button', { name: 'Replay (dry-run preview)' }),
			'the safe control must be offered',
		).toBeVisible()
		await expect(
			page.getByRole('button', { name: 'Force replay (real write)' }),
			'a forced replay must not be reachable in one click',
		).toHaveCount(0)
		await expect(page.getByTestId('confirm-force-replay')).toHaveCount(0)

		await page.getByRole('button', { name: 'Replay (dry-run preview)' }).click()

		// A dry-run preview is shown, and it says in so many words that nothing
		// was written.
		await expect(
			page.getByTestId('replay-preview-notice'),
			'the dry-run preview must be shown before any write path is offered',
		).toBeVisible({ timeout: 25_000 })

		// Only NOW is the forced option offered — and it is still one step
		// away from executing.
		const force = page.getByRole('button', { name: 'Force replay (real write)' })
		await expect(
			force,
			'the forced option appears only after the preview',
		).toBeVisible()
		await expect(
			page.getByTestId('confirm-force-replay'),
			'and it is still not the button that writes',
		).toHaveCount(0)

		await force.click()
		await expect(
			page.getByTestId('force-confirm-notice'),
			'a separate, explicit confirmation is required',
		).toBeVisible()
		await expect(page.getByTestId('confirm-force-replay')).toBeVisible()

		// Cancel returns to the pre-confirmation state rather than leaving the
		// write button armed. This test never clicks the write button: the
		// requirement is about the gate, not about the replay succeeding.
		await page.getByRole('button', { name: 'Cancel' }).click()
		await expect(
			page.getByTestId('confirm-force-replay'),
			'Cancel must disarm it',
		).toHaveCount(0)
	})
})
