/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/flow-orchestration/spec.md (REQ-009/010/011)
 *                openspec/specs/execution-trace/spec.md    (REQ-007)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THESE SIX AND NOT THE OTHERS
 * ─────────────────────────────────────────────────────────────────────────────
 * Four of the six scenarios below are ABSENCE claims in one direction:
 * "no Save control is offered", "Save is disabled", "the picker's options are
 * scoped to Mappings ONLY". An absence assertion is satisfied for free by a
 * page that failed to mount, by a selector that never opened, and by a locator
 * that matches nothing — so on its own it is worth approximately zero.
 *
 * Every one of them is therefore written as a DIFFERENTIAL over the SAME
 * locator, driven by a real behaviour of the editor:
 *
 *   - Save/Discard are `v-if="dirty"`, so the same button locator must be
 *     absent on load and PRESENT after one keystroke in the Name field.
 *   - Save is `:disabled="!canSave"` where canSave requires a non-empty name,
 *     so the same button must be ENABLED after a rename and DISABLED after the
 *     name is cleared — in the same page, seconds apart.
 *   - The config-ref picker's options are scoped by the picked step type, so
 *     the same picker must list the seeded Mapping and not the seeded Source
 *     when type=mapping, and the inverse when type=call.
 *
 * A dead selector, an unmounted page or a wrong URL cannot produce a
 * DIFFERENCE. It can only produce the same (empty) answer twice, which fails
 * the positive half of every pair. That is the only reason these are worth
 * counting as coverage.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IS DELIBERATELY *NOT* COVERED HERE
 * ─────────────────────────────────────────────────────────────────────────────
 * `flow-orchestration::flows-index-page-mounts-and-lists-flows` stays
 * UNCOVERED on purpose. The index does mount and does list flows, but the
 * scenario also requires each flow's LAST-RUN STATUS to be shown, and the
 * rendered table's columns are Name / Enabled / Description and nothing else
 * (measured: `th` texts are `['', 'Name', 'Enabled', 'Description', '']`).
 * Tagging this file for that scenario would credit coverage for a column that
 * does not exist. The honest state is an uncovered scenario describing a UI
 * gap — filed as openconnector#1214.
 *
 * ⚠️ The `#` in APP_BASE is required — the in-app router is hash-mode
 * (`createWebHashHistory()`, src/main.js). Without it the URL serves the SPA
 * shell and renders the DASHBOARD, which looks like the page failing to render
 * its own content.
 */
import { test, expect, type Page, type Locator } from '@playwright/test'
import { APP_BASE } from './_helpers'
import {
	makeApiClient,
	createObject,
	deleteObject,
	type ApiClient,
} from '../workflows/_fixture'

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

	async open(
		browser: import('@playwright/test').Browser,
		baseURL: string,
	): Promise<void> {
		this.api = await makeApiClient(browser, baseURL)
	}

	// eslint-disable-next-line @typescript-eslint/no-explicit-any
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
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
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
 * Open a flow's detail page and wait for the step-list editor to mount.
 *
 * Waiting on the editor's own row testid — not on a network call — is what
 * tells us the page rendered its own content rather than the dashboard.
 * `networkidle` is not used: it never settles on Nextcloud and it hides the
 * race it appears to fix (ADR-074).
 */
async function openFlow(page: Page, id: string): Promise<void> {
	await page.goto(`${APP_BASE}/flows/${id}`, { waitUntil: 'domcontentloaded' })
	await expect(
		page.getByTestId('flow-step-row').first(),
		'the step-list editor must mount — if this times out the page rendered the dashboard, not the flow',
	).toBeVisible({ timeout: 25_000 })
}

/** The Name field of the General card (the only NcTextField labelled `Name*`). */
function nameField(page: Page): Locator {
	return page.getByRole('textbox', { name: /^Name/ }).first()
}

/** The Save control. `v-if="dirty"` — absent entirely while the draft is clean. */
function saveButton(page: Page): Locator {
	return page.getByRole('button', { name: 'Save changes' })
}

/** The Discard control. Same `v-if="dirty"`. */
function discardButton(page: Page): Locator {
	return page.getByRole('button', { name: 'Discard' })
}

/**
 * The step-type NcSelect of one step row, by row index.
 *
 * FlowStepRow gives each select a stable `input-id` (`flow-step-type-<uid>`),
 * but the uid is assigned per component instance and does NOT track array
 * position — which is precisely what the reorder test needs to observe. So
 * rows are addressed positionally through the row testid and the select is
 * found by its `inputLabel`, which nc-vue renders as the combobox's
 * accessible name.
 */
function stepRow(page: Page, index: number): Locator {
	return page.getByTestId('flow-step-row').nth(index)
}

/**
 * The label of the option currently selected in a step row's type picker.
 *
 * TWO measured traps are encoded here, and both produce an assertion that
 * cannot fail rather than one that fails loudly:
 *
 * 1. `.vs__selected` is vue-select's display node for the chosen option. The
 *    `<input>` inside an NcSelect is the SEARCH box and is EMPTY when nothing
 *    is being typed (measured: `flow-step-type-1` has `value=""` on a row
 *    whose type plainly reads `Call`). Reading the input would have compared
 *    "" to "" for every row and passed regardless of the reorder.
 *
 * 2. nc-vue renders each option through `NcEllipsisedOption`, which SPLITS any
 *    label of ~10 characters or more into two spans — `Synchronization` comes
 *    back from `innerText()` as `"Synchron ization"` (measured on the first
 *    run of this spec). That is an upstream accessible-name defect
 *    (ConductionNL/.github#350, `nextcloud-libraries/nextcloud-vue`), and
 *    loosening the expected string to absorb it would HIDE the defect. The
 *    component keeps the unsplit text in a `title` attribute, so read that and
 *    fall back to the rendered text — a row where neither exists still fails.
 */
async function stepTypeLabel(page: Page, index: number): Promise<string> {
	const selected = stepRow(page, index).locator('.vs__selected').first()
	const titled = selected.locator('xpath=descendant-or-self::*[@title]').first()
	if ((await titled.count()) > 0) {
		const title = (await titled.getAttribute('title'))?.trim()
		if (title) {
			return title
		}
	}
	return (await selected.innerText()).replace(/\s+/g, ' ').trim()
}

/**
 * Open an NcSelect's dropdown and return its option labels, normalised.
 *
 * ⚠️ Two things this deliberately does NOT do:
 *
 *  - It does not treat "no options came back" as an empty list. vue-select
 *    renders a `li.vs__dropdown-option--disabled` ("Sorry, no matching
 *    options") rather than nothing at all, and a picker that is still LOADING
 *    renders the same way. Returning `[]` there would make every
 *    `not.toContain(...)` assertion in this file pass for free — the exact
 *    failure mode these tests exist to rule out. So the no-options row is
 *    detected explicitly and raised.
 *  - It does not normalise away vue-select's line WRAPS by accident: long
 *    option text is wrapped by the renderer and `allInnerTexts()` returns what
 *    is RENDERED, not the source string (`Register/Schema` comes back as
 *    `"Register\n/Schema"`). Whitespace is collapsed, nothing else.
 */
async function optionLabels(page: Page, select: Locator): Promise<string[]> {
	await select.locator('.vs__dropdown-toggle').click()
	const menu = page.locator('.vs__dropdown-menu')
	await expect(menu.first(), 'the dropdown must actually open').toBeVisible({
		timeout: 10_000,
	})

	const options = menu.locator('li[role="option"]')
	// The list is fetched (`configRefLoading`), so give it a moment to arrive
	// before deciding it is empty. Polling on the count is the honest wait:
	// a picker that never populates fails on the explicit check below rather
	// than silently yielding an empty array.
	await expect
		.poll(async () => await options.count(), {
			timeout: 15_000,
			message: 'the picker never populated',
		})
		.toBeGreaterThan(0)
		.catch(async () => {
			const rendered = (await menu.first().innerText())
				.replace(/\s+/g, ' ')
				.trim()
			throw new Error(
				`the dropdown opened but offered no selectable option — it rendered: ${JSON.stringify(rendered)}`,
			)
		})

	const labels: string[] = []
	for (const opt of await options.all()) {
		const titled = opt.locator('xpath=descendant-or-self::*[@title]').first()
		const title =
			(await titled.count()) > 0
				? (await titled.getAttribute('title'))?.trim()
				: undefined
		labels.push(title || (await opt.innerText()).replace(/\s+/g, ' ').trim())
	}
	await page.keyboard.press('Escape')
	return labels
}

/* ===========================================================================
 * REQ-010 — the draft/dirty contract
 * ======================================================================== */
test.describe('Flow detail — the draft is only saveable once it differs (REQ-010/011)', () => {
	const fx = new Fixtures()
	let flowId = ''

	test.beforeAll(async ({ browser, baseURL }) => {
		await fx.open(browser, baseURL!)
		const flow = await fx.make('flow', {
			name: `${runId} draft contract`,
			description: 'fixture for the dirty/canSave differentials',
			isEnabled: false,
			// `config: {}` is REJECTED by OpenRegister for a nested object
			// property ("expects object but got empty"); the key is omitted
			// instead. Seeding a readable marker into every field is not an
			// option here either — see the dead-letter spec's note on
			// `required: []` not meaning "validates nothing".
			steps: [
				{
					order: 1,
					type: 'event',
					onError: 'stop',
					config: { type: 'com.example.seed', source: `urn:${runId}` },
				},
			],
		})
		flowId = flow.id ?? flow.uuid
	})
	test.afterAll(async () => {
		await fx.close()
	})

	// @e2e flow-orchestration::an-untouched-flow-offers-nothing-to-save
	test('a freshly loaded flow offers no Save or Discard, and one keystroke offers both', async ({
		page,
	}) => {
		await openFlow(page, flowId)

		// THE REQUIREMENT (an absence claim — worthless alone).
		await expect(
			saveButton(page),
			'a flow that has just loaded must not offer Save — a page that reports itself dirty on load teaches its user to ignore the indicator',
		).toHaveCount(0)
		await expect(discardButton(page), 'nor Discard').toHaveCount(0)

		// THE CONTROL, over the SAME locators. `dirty` is a real computed over
		// a normalised diff, so a single keystroke in the Name field must make
		// both controls appear. If the page were broken, unmounted, or if these
		// locators were wrong, this half fails — which is what makes the half
		// above mean anything.
		await nameField(page).fill(`${runId} draft contract EDITED`)
		await expect(
			saveButton(page),
			'one real edit must offer Save — if this fails, the absence above proves nothing',
		).toBeVisible({ timeout: 10_000 })
		await expect(discardButton(page), 'and Discard').toBeVisible()

		// And the diff is computed against a NORMALISED copy, so restoring the
		// original value must retract the offer again rather than latching
		// dirty forever.
		await nameField(page).fill(`${runId} draft contract`)
		await expect(
			saveButton(page),
			'restoring the loaded value must retract the Save offer — a latched dirty flag is the same defect in the other direction',
		).toHaveCount(0)
	})

	// @e2e flow-orchestration::a-flow-with-no-name-cannot-be-saved
	test('clearing the name leaves Save offered but disabled, and restoring it re-enables', async ({
		page,
	}) => {
		await openFlow(page, flowId)

		// Make the draft dirty with a VALID name first, so that the disabled
		// state below is attributable to the empty name and not to the control
		// simply not being rendered. This ordering is the whole point: without
		// it, "Save is not clickable" and "Save does not exist" are the same
		// observation.
		await nameField(page).fill(`${runId} renamed`)
		const save = saveButton(page)
		await expect(
			save,
			'a dirty draft with a valid name offers Save',
		).toBeVisible({ timeout: 10_000 })
		await expect(save, 'and it is ENABLED — this is the control').toBeEnabled()

		// THE REQUIREMENT. `canSave` requires a non-empty trimmed name.
		await nameField(page).fill('')
		await expect(
			save,
			'an unnamed flow must not be submittable — the runner rejects it',
		).toBeDisabled({ timeout: 10_000 })

		// Whitespace is not a name either — `canSave` trims.
		await nameField(page).fill('   ')
		await expect(save, 'whitespace is not a name').toBeDisabled()

		// Back to enabled, proving the disable is driven by the value and not
		// by some sticky state the first fill introduced.
		await nameField(page).fill(`${runId} renamed again`)
		await expect(save, 'a valid name re-enables Save').toBeEnabled({
			timeout: 10_000,
		})
	})
})

/* ===========================================================================
 * REQ-009 — the typed step-list editor
 * ======================================================================== */
test.describe('Flow detail — the typed step list is the keyboard-operable editor (REQ-009)', () => {
	const fx = new Fixtures()
	let flowId = ''
	const MAPPING_NAME = `${runId} Mapping Beta`
	const SOURCE_NAME = `${runId} Source Alpha`

	test.beforeAll(async ({ browser, baseURL }) => {
		await fx.open(browser, baseURL!)
		// Two config-ref candidates of DIFFERENT entity types. The picker test
		// below is only meaningful because both exist at the same moment: a
		// picker that lists nothing would satisfy "no Sources are offered"
		// trivially.
		await fx.make('source', {
			name: SOURCE_NAME,
			description: 'flow picker fixture',
			location: 'https://example.invalid/alpha',
			type: 'api',
			isEnabled: true,
		})
		await fx.make('mapping', {
			name: MAPPING_NAME,
			description: 'flow picker fixture',
			mapping: { a: 'b' },
		})
		const flow = await fx.make('flow', {
			name: `${runId} three steps`,
			description: 'fixture for the reorder + picker scenarios',
			isEnabled: false,
			steps: [
				{
					order: 1,
					type: 'call',
					onError: 'stop',
					config: { endpoint: '/alpha', method: 'GET' },
				},
				{
					order: 2,
					type: 'event',
					onError: 'continue',
					config: { type: 'com.example.beta', source: `urn:${runId}` },
				},
				{ order: 3, type: 'synchronization', onError: 'dead_letter' },
			],
		})
		flowId = flow.id ?? flow.uuid
	})
	test.afterAll(async () => {
		await fx.close()
	})

	// @e2e flow-orchestration::reordering-is-possible-without-a-pointer-drag
	test('Move up swaps two steps by keyboard alone, with no pointer drag', async ({
		page,
	}) => {
		await openFlow(page, flowId)
		await expect(page.getByTestId('flow-step-row')).toHaveCount(3)

		// The three seeded steps carry three DIFFERENT types, which is what
		// makes a swap observable at all. Read the order as rendered rather
		// than assuming the seed order survived load.
		const before = [
			await stepTypeLabel(page, 0),
			await stepTypeLabel(page, 1),
			await stepTypeLabel(page, 2),
		]
		expect(
			before,
			'the three seeded steps must render as three distinguishable rows',
		).toEqual(['Call', 'Event', 'Synchronization'])

		// The first row's "Move step up" is disabled and the last row's "Move
		// step down" is disabled — the editor knows where the ends are. This
		// is a cheap control on the locator itself: if `Move step up` matched
		// nothing, `toHaveCount(3)` below would fail rather than the reorder
		// silently no-op'ing.
		const moveUp = page.getByRole('button', { name: 'Move step up' })
		await expect(moveUp).toHaveCount(3)
		await expect(moveUp.nth(0), 'the first step cannot move up').toBeDisabled()
		await expect(
			page.getByRole('button', { name: 'Move step down' }).nth(2),
			'the last step cannot move down',
		).toBeDisabled()

		// KEYBOARD ONLY. `.focus()` + Enter is a real keyboard activation of
		// the button; no drag, no pointer. WCAG 2.1 AA 2.1.1 is the reason the
		// spec forbids a drag-only editor, so exercising the pointer path here
		// would test the wrong thing.
		await moveUp.nth(1).focus()
		await expect(
			moveUp.nth(1),
			'the control must be reachable by focus for the keyboard path to exist',
		).toBeFocused()
		await page.keyboard.press('Enter')

		const after = [
			await stepTypeLabel(page, 0),
			await stepTypeLabel(page, 1),
			await stepTypeLabel(page, 2),
		]
		expect(
			after,
			'Move up on step 2 must swap it with step 1 — order is the step identity, so the ROWS move and the `order` values stay 1,2,3',
		).toEqual(['Event', 'Call', 'Synchronization'])

		// The `#order` badges stay 1..3 — moveStep swaps the two `order`
		// VALUES and re-sorts, it does not renumber. Asserting this pins the
		// behaviour the branch targets depend on: `nextStepOrder` references
		// `order` BY VALUE, so a reorder that renumbered would silently
		// invalidate every branch target in the flow.
		await expect(stepRow(page, 0).locator('.flow-step-row__order')).toHaveText(
			'#1',
		)
		await expect(stepRow(page, 2).locator('.flow-step-row__order')).toHaveText(
			'#3',
		)

		// A reorder is an edit, so the draft must now be dirty. Same
		// differential shape as the REQ-010 tests: the swap above is only
		// believable if the page agrees something changed.
		await expect(
			saveButton(page),
			'a reorder is an edit and must offer Save',
		).toBeVisible({ timeout: 10_000 })
	})

	// @e2e flow-orchestration::the-step-list-editor-adds-a-step-with-a-typed-config-picker
	test('Add step yields a row whose config-ref picker is scoped to the picked type', async ({
		page,
	}) => {
		await openFlow(page, flowId)
		await expect(page.getByTestId('flow-step-row')).toHaveCount(3)

		await page.getByRole('button', { name: 'Add step' }).click()
		const rows = page.getByTestId('flow-step-row')
		await expect(rows, 'Add step must append a fourth row').toHaveCount(4)

		const newRow = rows.nth(3)
		const typeSelect = newRow.locator('.flow-step-row__field').first()

		// Pick `mapping` through the type NcSelect exactly as an operator would.
		await typeSelect.locator('.vs__dropdown-toggle').click()
		await page
			.locator('.vs__dropdown-menu li[role="option"]', {
				hasText: /^Mapping$/,
			})
			.first()
			.click()

		// THE REQUIREMENT: the config-ref picker is now scoped to Mappings.
		const configRef = newRow.locator('.flow-step-row__field').nth(1)
		await expect(
			configRef.locator('.vs__dropdown-toggle'),
			'picking `mapping` must reveal a config-ref picker',
		).toBeVisible({ timeout: 10_000 })

		const mappingOptions = await optionLabels(page, configRef)
		// POSITIVE half — the seeded Mapping IS offered. Without this, the
		// negative half below is satisfied by a picker that lists nothing.
		expect(
			mappingOptions,
			`the config-ref picker must offer the seeded mapping. Got: ${JSON.stringify(mappingOptions.slice(0, 12))}`,
		).toContain(MAPPING_NAME)
		// NEGATIVE half — the seeded Source is NOT offered, in the same
		// dropdown, at the same moment.
		expect(
			mappingOptions,
			'a mapping step must not offer Sources as its config ref',
		).not.toContain(SOURCE_NAME)

		// THE INVERSE, over the SAME locator. Switching the type to `call`
		// must flip both memberships. A dead selector cannot produce this
		// difference; only the scoping rule actually working can.
		await typeSelect.locator('.vs__dropdown-toggle').click()
		await page
			.locator('.vs__dropdown-menu li[role="option"]', { hasText: /^Call$/ })
			.first()
			.click()

		const callOptions = await optionLabels(page, configRef)
		expect(
			callOptions,
			`a call step must offer the seeded source. Got: ${JSON.stringify(callOptions.slice(0, 12))}`,
		).toContain(SOURCE_NAME)
		expect(
			callOptions,
			'a call step must not offer Mappings as its config ref',
		).not.toContain(MAPPING_NAME)
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
