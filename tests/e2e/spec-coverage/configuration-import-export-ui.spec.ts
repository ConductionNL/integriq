/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/configuration-export-import/spec.md
 * REQ-006..REQ-009 (connector-catalog-ui — the routed export/preview/
 * import UI over the pre-existing ConfigurationService).
 *
 * Denial-path scenarios (export/import action-matrix rejection, import
 * without confirmation over raw HTTP) carry `@e2e exclude` in the spec
 * and are covered by PHPUnit
 * (tests/Unit/Controller/ConfigurationControllerTest.php).
 *
 * This file was written per the test plan and never executed, then disabled
 * with a reason that turned out to describe the wrong problem. It now seeds its
 * own configuration group and drives the real UI; see openStoreActions() for
 * what the stand-down reason got right and what nobody had checked.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/integriq'

/** The page that actually carries the configuration header actions. */
const STORE_URL = `${APP_BASE}/store`

/** OpenRegister's native configuration-group table (not an object schema). */
const CONFIGURATIONS_API = '/index.php/apps/openregister/api/configurations'

/**
 * Ensure at least one configuration group exists, and return how many there are.
 *
 * The suite used to be disabled partly because it "needs a seeded configuration
 * group". It does — and it can make one: configuration groups are a NATIVE
 * OpenRegister table (`lib/Db/Configuration.php`) exposed at
 * `/apps/openregister/api/configurations`, whose `create()` accepts the entity's
 * own fields and requires admin, which is what the e2e session already is.
 * Seeding here is cheaper and more honest than standing the suite down.
 */
async function ensureConfigurationGroup(page: Page): Promise<number> {
	// NO QUERY STRING. This is a native-table controller, not the objects API:
	// ConfigurationsController::index() takes `$this->request->getParams()`,
	// unsets only `_route`, and passes the REST straight to the mapper as
	// column filters. A paging parameter borrowed from the objects API —
	// `?_limit=50` — therefore becomes a filter on a `_limit` column that does
	// not exist, and the endpoint fails rather than ignoring it. (The objects
	// API does the same thing more quietly: there an unknown parameter filters
	// to an empty set, which is how learniq's suite came to blame its fixtures
	// for data that was present all along.)
	const list = await page.request.get(CONFIGURATIONS_API, {
		headers: { Accept: 'application/json' },
	})
	expect(
		list.ok(),
		`the configurations endpoint must be reachable (HTTP ${list.status()})`,
	).toBe(true)
	const body = await list.json()
	const existing = body.results ?? body.configurations ?? body ?? []
	if (Array.isArray(existing) && existing.length > 0) return existing.length

	const created = await page.request.post(CONFIGURATIONS_API, {
		headers: { Accept: 'application/json' },
		data: {
			title: 'e2e configuration group',
			description: 'Seeded by configuration-import-export-ui.spec.ts',
			type: 'configuration',
			app: 'integriq',
			version: '1.0.0',
		},
	})
	expect(created.ok(), 'seeding a configuration group must succeed').toBe(true)
	return 1
}

/**
 * Open the Store page and its Actions overflow menu.
 *
 * TWO CORRECTIONS ARE BAKED IN HERE, both of which this suite got wrong.
 *
 * 1. THE PAGE. The old helper opened `${APP_BASE}/catalog` and its docblock
 *    said that page "hosts the configuration import/export header actions".
 *    No route containing "catalog" exists in this app's 36 manifest pages.
 *    `export-configuration` / `import-configuration` are `headerActions` on the
 *    STORE page (`/store`), exactly as that page's own `_configurationUiNote`
 *    records. Navigating to a route that does not exist resolves to the default
 *    page, so every assertion here would have run against the Dashboard.
 *
 * 2. THE ROUTER. The old comment claimed "Hash-routed SPA
 *    (createWebHashHistory)" and then used a path URL with no hash — the note
 *    and the code contradicted each other. integriq builds
 *    `createWebHistory(generateUrl('/apps/integriq'))`, so the PATH form is
 *    correct and the comment was simply wrong.
 *
 * The actions are not top-level buttons: CnActionsBar renders manifest
 * `headerActions` as `NcActionButton`s inside `<NcActions data-testid=
 * "cn-actions">`, after the built-in Refresh entry. So the menu has to be
 * opened first — which is what the old skip reason described but nothing did.
 */
async function openStoreActions(page: Page): Promise<void> {
	await page.goto(STORE_URL, { waitUntil: 'domcontentloaded' })
	const actions = page.getByTestId('cn-actions')
	await expect(actions, 'the Store page must render its actions bar').toBeVisible({
		timeout: 15_000,
	})
	await actions.getByRole('button').first().click()
}

/**
 * Page forward through an index table until a row containing `name` is visible.
 *
 * The index pages render 20 rows and a pager, so "the row I just created is not
 * on screen" and "the row was never written" look identical if you only ever
 * look at page 1. Returns whether the row was found anywhere in the list, so
 * the caller can assert on it rather than on the page size.
 *
 * Mirrors `walkToRow()` in `tests/e2e/workflows/source-mapping-crud.spec.ts`,
 * which is what let the Source CRUD cycle pass against a 2-page list.
 *
 * @param page The Playwright page, already on the index route.
 * @param name Text the target row must contain.
 * @return True when the row was found on some page of the list.
 */
async function walkToRow(page: Page, name: string): Promise<boolean> {
	// Bounded so a broken pager can never spin forever.
	for (let hop = 0; hop < 40; hop++) {
		const row = page.locator('tr', { hasText: name }).first()
		if (await row.isVisible({ timeout: 2_000 }).catch(() => false)) {
			return true
		}

		// `isEnabled()` is false on the last page (the button renders disabled)
		// and the call throws when there is no pager at all — a single-page
		// list. Both mean "nowhere left to look".
		const next = page.getByRole('button', { name: 'Next', exact: true }).first()
		if (
			(await next.isEnabled({ timeout: 2_000 }).catch(() => false)) === false
		) {
			return false
		}

		await next.click()
		await page.waitForTimeout(900)
	}

	return false
}

// RE-ENABLED (#1187). The stand-down reason named three obstacles; each has
// been dealt with rather than described:
//
//   "assumes top-level Export/Import buttons"  -> openStoreActions() opens the
//        NcActions overflow, which is where CnActionsBar renders manifest
//        headerActions.
//   "needs a seeded configuration group"       -> ensureConfigurationGroup()
//        creates one through OpenRegister's configurations API, which the admin
//        e2e session may write.
//   "upload fixtures"                          -> the import block below builds
//        its payload in-process with setInputFiles({ buffer }); no checked-in
//        binary is needed.
//
// A fourth obstacle was never in the reason because nobody had checked: the
// helper opened `/catalog`, a route this app does not have. See
// openStoreActions() for that and for the contradictory hash-router comment.
test.describe('REQ-006: Export a configuration from the UI', () => {
	// @e2e configuration-export-import::exporting-a-configuration-from-the-ui-produces-a-redacted-downloadable-file
	test('export dialog downloads a JSON file with no credential fields', async ({
		page,
	}) => {
		await ensureConfigurationGroup(page)
		await openStoreActions(page)

		await page
			.getByRole('menuitem', { name: /Export configuration/i })
			.first()
			.click()
		const dialog = page.getByTestId('export-configuration-dialog')
		await expect(dialog).toBeVisible({ timeout: 10_000 })

		// Pick a configuration group. NcSelect wraps vue-select, whose options
		// exist in the DOM only while the dropdown is open — clicking the
		// combobox focuses it but does not reliably open it, and the previous
		// version of this line then waited out the full timeout on
		// `getByRole('option')` against a dropdown that was never opened. The
		// failure snapshot shows the dialog with `combobox [active]` and no
		// listbox at all.
		//
		// Keyboard is the reliable path: ArrowDown opens the list and highlights
		// the first option, Enter takes it.
		const combobox = dialog.getByRole('combobox').first()
		await combobox.click()
		await combobox.press('ArrowDown')
		await combobox.press('Enter')

		// Assert the selection actually landed, rather than trusting the
		// keystrokes: the confirm button is disabled until a group is chosen, so
		// it becoming enabled IS the proof, and a failure here names the real
		// problem instead of surfacing later as a stuck download.
		await expect(
			page.getByTestId('export-configuration-confirm'),
			'choosing a configuration group must enable Export',
		).toBeEnabled({ timeout: 10_000 })

		const downloadPromise = page.waitForEvent('download')
		await page.getByTestId('export-configuration-confirm').click()
		const download = await downloadPromise

		const stream = await download.createReadStream()
		const chunks: Buffer[] = []
		for await (const chunk of stream) chunks.push(chunk as Buffer)
		const body = Buffer.concat(chunks).toString('utf8')
		const document = JSON.parse(body)

		expect(document).toHaveProperty('components')
		// REQ-005 redaction: no credential field may survive the export.
		for (const field of [
			'"apikey"',
			'"secret"',
			'"password"',
			'"jwt"',
			'"authorizationHeader"',
		]) {
			expect(body).not.toContain(field)
		}
	})
})

// RE-ENABLED (#1187) — same three obstacles as REQ-006, same treatment. The
// "upload fixtures" half of the reason was already untrue when it was written:
// every test in this block builds its payload in-process with
// `setInputFiles({ buffer })`, so no checked-in file was ever required.
test.describe('REQ-007/REQ-008: Import preview + confirmation', () => {
	// @e2e configuration-export-import::preview-classifies-creates-updates-and-collisions
	test('uploading a document shows the creates/updates preview without writing', async ({
		page,
	}) => {
		await ensureConfigurationGroup(page)
		await openStoreActions(page)

		await page
			.getByRole('menuitem', { name: /Import configuration/i })
			.first()
			.click()
		const dialog = page.getByTestId('import-preview-dialog')
		await expect(dialog).toBeVisible({ timeout: 10_000 })

		await page.getByTestId('import-file-input').setInputFiles({
			name: 'import.json',
			mimeType: 'application/json',
			buffer: Buffer.from(
				JSON.stringify({
					components: {
						sources: {
							'e2e-new-source': {
								slug: 'e2e-new-source',
								name: 'E2E new source',
								type: 'api',
							},
						},
					},
				}),
			),
		})

		await expect(page.getByTestId('preview-creates')).toBeVisible({
			timeout: 10_000,
		})
		await expect(page.getByTestId('preview-creates')).toContainText(
			'e2e-new-source',
		)
		await expect(page.getByTestId('preview-updates')).toBeVisible()
	})

	// @e2e configuration-export-import::preview-surfaces-an-unresolvable-slug-reference-as-a-blocking-warning
	test('an unresolvable slug reference blocks confirmation until acknowledged', async ({
		page,
	}) => {
		await ensureConfigurationGroup(page)
		await openStoreActions(page)

		await page
			.getByRole('menuitem', { name: /Import configuration/i })
			.first()
			.click()
		await page.getByTestId('import-file-input').setInputFiles({
			name: 'import-dangling.json',
			mimeType: 'application/json',
			buffer: Buffer.from(
				JSON.stringify({
					components: {
						rules: {
							'e2e-dangling-rule': {
								slug: 'e2e-dangling-rule',
								configuration: {
									sourceId: 'this-slug-does-not-exist-anywhere',
								},
							},
						},
					},
				}),
			),
		})

		await expect(page.getByTestId('preview-unresolved')).toBeVisible({
			timeout: 10_000,
		})
		await expect(page.getByTestId('preview-unresolved')).toContainText(
			'this-slug-does-not-exist-anywhere',
		)

		// Blocking: confirm is disabled until the operator acknowledges.
		const confirm = page.getByTestId('confirm-import')
		await expect(confirm).toBeDisabled()

		// TICK THE BOX BY KEYBOARD, NOT BY CLICKING THE INPUT.
		//
		// `data-testid` lands on the `<input>`: NcCheckboxRadioSwitch spreads
		// `$attrs` onto the input, not onto its wrapper. That input is
		// `position: absolute; z-index: -1; opacity: 0 !important`
		// (NcCheckboxRadioSwitch's own stylesheet), and the component renders
		// NO `<label for>` — its wrapper defaults to a plain `<span>`. So the
		// visible `<span class="checkbox-content">` sits on top of the input
		// and `.click()` can never reach it: Playwright retried for the full
		// 60s timeout reporting "…checkbox-content… intercepts pointer events".
		//
		// The input is nonetheless focusable, and `change` is the handler the
		// component binds, so Space on the focused checkbox is both the real
		// accessible interaction and the one that actually toggles the model.
		// (Same reason this suite drives NcSelect by keyboard.)
		//
		// `toBeChecked()` is not decoration — it is what stops this from
		// becoming a test that cannot fail. Without it a silently-swallowed
		// keypress would leave `confirm` disabled and the failure would point
		// at the button instead of at the box that was never ticked.
		const ack = page.getByTestId('unresolved-ack')
		await ack.focus()
		await ack.press(' ')
		await expect(ack, 'the acknowledgement box must actually tick').toBeChecked()

		await expect(confirm).toBeEnabled()
	})

	// @e2e configuration-export-import::confirmed-import-proceeds-and-reuses-the-existing-import-pipeline-unchanged
	// @e2e configuration-export-import::a-newly-created-source-from-import-is-flagged-for-credential-re-entry
	test('confirming the import writes the entities and flags credential re-entry', async ({
		page,
	}) => {
		await ensureConfigurationGroup(page)
		await openStoreActions(page)

		await page
			.getByRole('menuitem', { name: /Import configuration/i })
			.first()
			.click()
		await page.getByTestId('import-file-input').setInputFiles({
			name: 'import-confirm.json',
			mimeType: 'application/json',
			buffer: Buffer.from(
				JSON.stringify({
					components: {
						sources: {
							'e2e-import-source': {
								slug: 'e2e-import-source',
								name: 'E2E imported source (credentials stripped)',
								type: 'api',
							},
						},
					},
				}),
			),
		})

		await expect(page.getByTestId('preview-creates')).toBeVisible({
			timeout: 10_000,
		})
		// REQ-009 preview-side flag.
		await expect(page.getByTestId('preview-credentials')).toContainText(
			'e2e-import-source',
		)

		await page.getByTestId('confirm-import').click()
		await expect(page.getByTestId('import-success')).toBeVisible({
			timeout: 15_000,
		})
		// REQ-009 post-import summary names the source + missing fields.
		await expect(page.getByTestId('import-credentials-summary')).toContainText(
			'e2e-import-source',
		)
		await expect(page.getByTestId('import-credentials-summary')).toContainText(
			'apikey',
		)

		// REQ-008 written-check, asserted in two steps so a failure says WHICH
		// half broke.
		//
		// First the write itself, against the store: "the import writes the
		// entities" is a claim about persistence, and the import dialog has
		// already reported success by this point. If this assertion is the one
		// that fails, the dialog is lying — which is worth knowing plainly
		// rather than reading it off a missing table row.
		//
		// 🔴 ASK FOR THE WHOLE LIST. The objects API pages at 20 by default.
		// This check first ran unpaged against an instance holding 23 sources
		// (`total: 23, pages: 2, limit: 20`) and reported "the confirmed import
		// must have written the source" — while the very same run's import
		// response said `"written": {"sources": ["e2e-import-source"]}`. The
		// row was real and on page 2; the number being measured was the page
		// size. That is the trap `tests/e2e/ci-seed.sh` already documents
		// against three earlier specs, arriving here through a different door.
		const written = await page.request.get(
			'/index.php/apps/openregister/api/objects/integriq/source?_limit=200',
			{ headers: { Accept: 'application/json' } },
		)
		expect(
			written.ok(),
			`sources must be listable after import (HTTP ${written.status()})`,
		).toBe(true)
		const writtenBody = await written.json()
		const sources = writtenBody.results ?? []
		// Guard the guard: if the list ever outgrows one page again, say so
		// instead of quietly searching a prefix. Without this the `_limit`
		// above is a fix that expires silently the moment the fixture grows.
		expect(
			sources.length,
			`the whole source list must fit in one page for this check to be `
				+ `meaningful (got ${sources.length} of ${writtenBody.total})`,
		).toBe(Number(writtenBody.total ?? sources.length))
		expect(
			sources.some((s: Record<string, unknown>) =>
				String(s.name ?? '').includes('E2E imported source'),
			),
			'the confirmed import must have written the source',
		).toBe(true)

		// Then the surface a user would look at — which pages at 20 exactly as
		// the API does, so walk it rather than assuming page 1. Walking makes
		// this STRICTER than a page-1 check, not looser: it fails only when the
		// row is absent from the entire list.
		await page.goto(`${APP_BASE}/sources`, { waitUntil: 'domcontentloaded' })
		expect(
			await walkToRow(page, 'E2E imported source'),
			'the imported source must appear somewhere in the Sources list',
		).toBe(true)
		await expect(
			page.getByText('E2E imported source', { exact: false }).first(),
		).toBeVisible({ timeout: 15_000 })
	})
})
