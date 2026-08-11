/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/dead-letter-replay/spec.md
 * — the UI half of REQ-DLR-012 (Sync dead letters view) and REQ-DLR-013
 * (action-kind badge + Nextcloud-event provenance filter).
 *
 * WHY A SEPARATE FILE FROM tests/e2e/regression/dead-letter-replay.spec.ts
 * ----------------------------------------------------------------------
 * That file covers the `event_message` REQ-DLR-001..006 set, and it drives the
 * ADMIN HTTP API. These scenarios are different: each one names a thing a
 * BROWSER must show — a per-row badge, a provenance toggle that narrows the
 * rendered list, an empty state instead of an empty table, and a detail modal
 * that shows the payload and prior attempts BEFORE the Replay action. Those
 * are assertions about the DOM, so they are made against the DOM.
 *
 * EVERY TEST HERE SEEDS ITS OWN ROWS AND ASSERTS ON THOSE ROWS ONLY.
 * The dead-letter queues are shared, instance-wide, admin views. A test that
 * counted rows, or asserted "the table is empty", would pass or fail on
 * whatever else happens to be in the queue — including rows another spec in
 * the same run just created. So:
 *   - fixtures carry a unique `runId` in their originId / error / payload,
 *   - assertions are scoped to the seeded rows by matching that marker,
 *   - the one test that legitimately needs an empty queue (REQ-DLR-012's empty
 *     state) does NOT assume it got one from declaration order — it asks the
 *     endpoint that feeds the view and skips if the queue is dirty.
 */
import { test, expect, type Page } from '@playwright/test'
import { APP_BASE, trackErrors, assertNoAppErrors } from './_helpers'
import {
	makeApiClient,
	createObject,
	deleteObject,
	type ApiClient,
} from '../workflows/_fixture'

/** Unique per-run marker so every assertion can scope to this run's rows. */
const runId = `dlui-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`

/*
 * `sync_item_dead_letter.synchronization` is `format: uuid` in the register
 * schema, and OpenRegister ENFORCES it: seeding `"<runId>-sync"` was rejected
 * with `400 Property 'synchronization' should match format 'uuid'`. So the two
 * synchronizations these fixtures distinguish between are real UUIDs, and the
 * human-readable run marker lives on `originId` / `error` / `payload` instead.
 * (Worth noting for the fleet: this schema's `required` list is EMPTY, so
 * "no required fields" does not mean "no validation" — the format keywords
 * still reject.)
 */
const SYNC_A = crypto.randomUUID()
const SYNC_B = crypto.randomUUID()

/**
 * Per-describe fixture ownership.
 *
 * THIS IS NOT STYLE — a file-scoped client did not work, and the way it failed
 * is worth recording. The `regression` project sets `fullyParallel: true`, so
 * Playwright splits this file into one run-group PER `test.describe`. A single
 * file-level `afterAll` therefore fires when the FIRST group finishes, and the
 * next group's `beforeAll` then hits
 * `apiRequestContext.post: Request context disposed`. That surfaced as a
 * 60s "beforeAll hook timeout", i.e. as a slow test rather than as a lifecycle
 * bug, and it took four other tests down as "did not run" with it.
 *
 * So each describe creates its own client and tears down only its own rows.
 */
class Fixtures {
	api!: ApiClient
	private created: Array<{ schema: string, id: string }> = []

	async open(browser: import('@playwright/test').Browser, baseURL: string): Promise<void> {
		this.api = await makeApiClient(browser, baseURL)
	}

	/** Create an object and register it for teardown. */
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	async make(schema: string, data: Record<string, any>): Promise<any> {
		const obj = await createObject(this.api, schema, data)
		const id = obj.id ?? obj.uuid
		if (!id) {
			throw new Error(`createObject(${schema}) returned no id/uuid — cannot track it for cleanup: ${JSON.stringify(obj).slice(0, 300)}`)
		}
		this.created.push({ schema, id })
		return obj
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
 * Open the Dead letters page on the requested queue and wait for the queue's
 * own component to mount.
 *
 * The page is `type: custom` (component `DeadLettersPage`) and hosts the two
 * queues as sibling components behind a tab strip; `?queue=sync` deep-links
 * the sync one. Waiting on the per-queue testid — not on a network call — is
 * what tells us the queue component itself rendered.
 */
async function openDeadLetters(page: Page, queue: 'events' | 'sync'): Promise<void> {
	const suffix = queue === 'sync' ? '?queue=sync' : ''
	await page.goto(`${APP_BASE}/dead-letters${suffix}`, { waitUntil: 'domcontentloaded' })
	await expect(
		page.getByTestId(`dead-letters-${queue}`),
		`the ${queue} queue component must mount`,
	).toBeAttached({ timeout: 20_000 })
	// The queue renders EITHER its table or its empty state once loading ends.
	// Waiting for "one of the two" is the honest ready signal; waiting only for
	// the table would hang for the full timeout on a legitimately empty queue,
	// and waiting for networkidle never settles on Nextcloud at all (ADR-074).
	await expect(
		page.getByTestId('dead-letters-table')
			.or(page.getByTestId('deliveries-table'))
			.or(page.getByTestId('empty-state'))
			.first(),
	).toBeVisible({ timeout: 20_000 })
}

/*
 * ---------------------------------------------------------------------------
 * REQ-DLR-012 — Sync dead letters view
 * ---------------------------------------------------------------------------
 *
 * The empty-state test needs a queue with no failed rows. It does NOT rely on
 * declaration order to get one (`fullyParallel` makes that a coin flip);
 * instead it asks the endpoint that feeds the view whether the queue is empty
 * and skips rather than assert a falsehood if it is not.
 */
test.describe('Sync dead letters — empty state (REQ-DLR-012)', () => {
	const fx = new Fixtures()
	test.beforeAll(async ({ browser, baseURL }) => { await fx.open(browser, baseURL!) })
	test.afterAll(async () => { await fx.close() })

	// @e2e dead-letter-replay::empty-sync-dead-letter-queue-shows-an-empty-state
	test('an empty sync queue renders an empty state, not an empty table', async ({ page }) => {
		const sink = trackErrors(page)

		// POSITIVE CONTROL FOR THE PRECONDITION. "The list is empty" is exactly
		// the claim a broken fetch manufactures for free — a 500, a wrong route
		// or an unmounted component all render as "no rows". So before trusting
		// the empty state, ask the endpoint that feeds it whether it agrees the
		// queue is empty. If it returns rows, this test is being run against a
		// dirty queue and must SKIP rather than assert a falsehood.
		const probe = await fx.api.request.get('/index.php/apps/openconnector/api/sync-dead-letter', {
			failOnStatusCode: false,
		})
		expect(probe.status(), 'the sync dead-letter endpoint must answer 200').toBe(200)
		const body = await probe.json()
		const rows = Array.isArray(body.results) ? body.results : []
		test.skip(rows.length > 0, `sync dead-letter queue is not empty (${rows.length} rows) — cannot assert the empty state`)

		await openDeadLetters(page, 'sync')

		const empty = page.getByTestId('dead-letters-sync').getByTestId('empty-state')
		await expect(empty, 'the empty state must render').toBeVisible()
		await expect(empty).toHaveText(/No dead-lettered sync items/i)
		// …and specifically NOT a table with a header row and no body.
		await expect(page.getByTestId('dead-letters-table')).toHaveCount(0)

		assertNoAppErrors(sink)
	})
})

test.describe('Sync dead letters — inspect and replay (REQ-DLR-012)', () => {
	const fx = new Fixtures()
	const originId = `${runId}-origin-1`

	test.beforeAll(async ({ browser, baseURL }) => {
		await fx.open(browser, baseURL!)
		await fx.make('sync_item_dead_letter', {
			synchronization: SYNC_A,
			originId,
			phase: 'write',
			status: 'failed',
			retryCount: 3,
			error: `${runId}: target rejected the mapped payload (422)`,
			payload: { subject: runId, name: 'seeded by dead-letters-ui.spec.ts' },
			attempts: [
				{ at: '2026-08-11T09:00:00+00:00', error: 'HTTP 503 from target' },
				{ at: '2026-08-11T09:05:00+00:00', error: 'HTTP 503 from target' },
				{ at: '2026-08-11T09:15:00+00:00', error: 'timeout after 30s' },
			],
		})
	})

	test.afterAll(async () => { await fx.close() })

	// @e2e dead-letter-replay::operator-inspects-and-replays-a-dead-lettered-sync-item
	test('the detail modal shows payload and prior attempts BEFORE the Replay action', async ({ page }) => {
		const sink = trackErrors(page)
		await openDeadLetters(page, 'sync')

		const row = page.getByTestId('dead-letters-table').locator('tr', { hasText: originId })
		await expect(row, 'the seeded dead letter must appear in the list').toBeVisible({ timeout: 20_000 })

		await row.getByRole('button', { name: /Inspect/i }).click()

		const modal = page.getByTestId('sync-dead-letter-detail-modal')
		await expect(modal).toBeVisible({ timeout: 10_000 })

		// THE ORDERING IS THE REQUIREMENT: "the modal SHALL show the payload and
		// prior attempts BEFORE the action". So both must already be on screen
		// while the primary action is still the un-confirmed "Replay" button.
		const replay = modal.getByRole('button', { name: /^Replay$/i })
		await expect(replay, 'Replay must be offered un-confirmed at this point').toBeEnabled()

		await expect(modal.getByTestId('payload-viewer')).toContainText(runId)
		const timeline = modal.getByTestId('attempt-timeline')
		await expect(timeline).toBeVisible()
		await expect(timeline.locator('li'), 'all three seeded attempts must be listed').toHaveCount(3)
		await expect(timeline).toContainText('HTTP 503 from target')
		await expect(timeline).toContainText('timeout after 30s')

		assertNoAppErrors(sink)
	})

	// @e2e dead-letter-replay::the-detail-view-explains-why-an-item-died
	test('the detail modal names the failure and the attempt count', async ({ page }) => {
		const sink = trackErrors(page)
		await openDeadLetters(page, 'sync')

		const row = page.getByTestId('dead-letters-table').locator('tr', { hasText: originId })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await row.getByRole('button', { name: /Inspect/i }).click()

		const modal = page.getByTestId('sync-dead-letter-detail-modal')
		await expect(modal).toBeVisible({ timeout: 10_000 })
		// The seeded error string carries runId, so this cannot be satisfied by
		// some other row's error text leaking into the modal.
		await expect(modal).toContainText('target rejected the mapped payload (422)')
		await expect(modal).toContainText(runId)
		await expect(modal, 'the attempt count must be stated, not just the list')
			.toContainText(/Attempts:\s*3/)

		assertNoAppErrors(sink)
	})

	// @e2e dead-letter-replay::default-listing-returns-failed-items-only
	// @e2e dead-letter-replay::a-discarded-item-is-excluded-from-the-default-listing
	test('the default view lists the failed entry and hides a discarded one', async ({ page }) => {
		const sink = trackErrors(page)

		// A second entry, identical except that it is already discarded.
		const discardedOrigin = `${runId}-origin-discarded`
		await fx.make('sync_item_dead_letter', {
			synchronization: SYNC_A,
			originId: discardedOrigin,
			phase: 'write',
			status: 'discarded',
			retryCount: 1,
			error: `${runId}: discarded on purpose`,
			payload: { subject: runId },
			discardedBy: 'admin',
			discardedAt: '2026-08-11T10:00:00+00:00',
		})

		await openDeadLetters(page, 'sync')
		const table = page.getByTestId('dead-letters-table')

		// The failed one is present — this is the POSITIVE CONTROL that makes
		// the absence assertion below meaningful. Without it, "the discarded row
		// is not visible" would also be satisfied by a page that renders nothing.
		await expect(table.locator('tr', { hasText: originId }),
			'the failed entry must be listed by default').toBeVisible({ timeout: 20_000 })
		await expect(table.locator('tr', { hasText: discardedOrigin }),
			'a discarded entry must NOT appear in the default listing').toHaveCount(0)

		assertNoAppErrors(sink)
	})

	// @e2e dead-letter-replay::filtering-by-synchronization-narrows-the-list
	test('the Synchronization filter narrows the list to one synchronization', async ({ page }) => {
		const sink = trackErrors(page)

		const otherOrigin = `${runId}-origin-other-sync`
		await fx.make('sync_item_dead_letter', {
			synchronization: SYNC_B,
			originId: otherOrigin,
			phase: 'write',
			status: 'failed',
			retryCount: 1,
			error: `${runId}: belongs to the other synchronization`,
			payload: { subject: runId },
		})

		await openDeadLetters(page, 'sync')
		const table = page.getByTestId('dead-letters-table')

		// Both are present before filtering — the positive control.
		await expect(table.locator('tr', { hasText: originId })).toBeVisible({ timeout: 20_000 })
		await expect(table.locator('tr', { hasText: otherOrigin })).toBeVisible()

		// The filter field is an NcTextField labelled "Synchronization" and
		// reloads the server-side listing on a 400ms debounce.
		await page.getByRole('textbox', { name: /Synchronization/i }).first().fill(SYNC_B)

		await expect(table.locator('tr', { hasText: otherOrigin }),
			'the matching entry must survive the filter').toBeVisible({ timeout: 20_000 })
		await expect(table.locator('tr', { hasText: originId }),
			'the non-matching entry must be filtered out').toHaveCount(0)

		assertNoAppErrors(sink)
	})
})

/*
 * ---------------------------------------------------------------------------
 * REQ-DLR-013 — action kind per row + Nextcloud-event provenance filter
 * ---------------------------------------------------------------------------
 */
test.describe('Event deliveries — action kind and provenance (REQ-DLR-013)', () => {
	// One subscription per action kind. `actionKind` on a row is RESOLVED
	// server-side from the subscription's `action.kind` (EventsController::
	// resolveSubscriptionActionKind) — it is not a field on the message — so the
	// subscriptions are load-bearing fixtures, not decoration. A message whose
	// subscription cannot be resolved falls back to 'webhook', which is exactly
	// the value one of the three rows expects; that is why the webhook row is
	// given a REAL subscription carrying an explicit `action.kind: 'webhook'`
	// rather than being left subscription-less. Otherwise the webhook assertion
	// would pass identically if subscription resolution were completely broken.
	const fx = new Fixtures()
	const kinds = ['webhook', 'synchronization', 'job'] as const
	const subIds: Record<string, string> = {}
	const eventTypeFor = (kind: string) => `com.conduction.${runId}.${kind}`

	test.beforeAll(async ({ browser, baseURL }) => {
		await fx.open(browser, baseURL!)
		for (const kind of kinds) {
			const sub = await fx.make('event_subscription', {
				reference: `${runId}-sub-${kind}`,
				source: '/nextcloud/files',
				types: [eventTypeFor(kind)],
				sink: 'http://127.0.0.1:9/never',
				protocol: 'HTTP',
				status: 'active',
				action: { kind },
			})
			subIds[kind] = sub.uuid ?? sub.id
		}

		for (const kind of kinds) {
			await fx.make('event_message', {
				subscription: subIds[kind],
				status: 'failed',
				retryCount: 2,
				lastAttempt: '2026-08-11T09:30:00+00:00',
				payload: {
					type: eventTypeFor(kind),
					// A Nextcloud-native producer: source under /nextcloud/.
					source: '/nextcloud/files',
					subject: runId,
				},
			})
		}

		// The discriminator row for the provenance filter: an OpenRegister
		// OBJECT event. Note its `type` still starts with `com.nextcloud.` —
		// that is the whole point of the requirement. Only `source` separates
		// it, so a filter implemented on `type` would wrongly keep this row and
		// this test would catch it.
		await fx.make('event_message', {
			subscription: subIds.webhook,
			status: 'failed',
			retryCount: 1,
			lastAttempt: '2026-08-11T09:31:00+00:00',
			payload: {
				type: `com.nextcloud.openregister.object.created.${runId}`,
				source: '/objects/person',
				subject: runId,
			},
		})
	})

	test.afterAll(async () => { await fx.close() })

	// @e2e dead-letter-replay::the-dead-letter-list-shows-the-action-kind-per-row
	test('each row shows a badge matching its OWN action kind', async ({ page }) => {
		const sink = trackErrors(page)
		await openDeadLetters(page, 'events')

		const table = page.getByTestId('deliveries-table')
		await expect(table).toBeVisible({ timeout: 20_000 })

		for (const kind of kinds) {
			const row = table.locator('tr', { hasText: eventTypeFor(kind) })
			await expect(row, `the ${kind} row must be listed`).toBeVisible({ timeout: 20_000 })
			await expect(
				row.getByTestId('action-kind-badge'),
				`the ${kind} row's badge must read "${kind}", not another row's kind`,
			).toHaveText(kind)
		}

		assertNoAppErrors(sink)
	})

	// @e2e dead-letter-replay::an-admin-filters-the-dead-letter-list-to-nextcloud-native-events-only
	test('the Nextcloud-event filter keeps /nextcloud/ sources and drops an OR-object event', async ({ page }) => {
		const sink = trackErrors(page)
		await openDeadLetters(page, 'events')

		const table = page.getByTestId('deliveries-table')
		const ncRow = table.locator('tr', { hasText: eventTypeFor('webhook') })
		const orRow = table.locator('tr', { hasText: `com.nextcloud.openregister.object.created.${runId}` })

		// Positive control: both are visible with the filter OFF. Without this,
		// "the OR row is gone" would also be true of a page that failed to load.
		await expect(ncRow).toBeVisible({ timeout: 20_000 })
		await expect(orRow, 'the OR-object row must be present before filtering').toBeVisible()

		// TURNING ON AN nc-vue SWITCH — two traps, both of which report as a 60s
		// `locator.check: Test timeout` rather than as a locator problem:
		//
		//  1. `data-testid` on an NcCheckboxRadioSwitch lands on the <input>
		//     ITSELF, not on a wrapper. `getByTestId(id).getByRole('checkbox')`
		//     therefore looks for a checkbox INSIDE a checkbox and matches
		//     nothing.
		//  2. Targeting the input directly does not work either. It is
		//     `opacity: 0` with a real bounding box — Playwright calls it
		//     "visible, enabled and stable" — but the sibling
		//     `<span class="checkbox-content …">` that draws the switch sits on
		//     top of it, so every click attempt logs
		//     `…checkbox-content… intercepts pointer events` and retries until
		//     the test times out.
		//
		// Click the wrapper, which is what a user clicks, then ASSERT the input
		// actually flipped rather than assuming the click landed.
		const filter = page.getByTestId('nextcloud-event-filter')
		await page.locator('.checkbox-radio-switch').filter({ has: filter }).click()
		await expect(filter, 'the provenance filter must actually be on').toBeChecked()

		await expect(orRow,
			'an event whose source is /objects/person must be filtered out despite its com.nextcloud. TYPE prefix',
		).toHaveCount(0, { timeout: 15_000 })
		await expect(ncRow, 'an event whose source is /nextcloud/files must survive').toBeVisible()

		assertNoAppErrors(sink)
	})
})
