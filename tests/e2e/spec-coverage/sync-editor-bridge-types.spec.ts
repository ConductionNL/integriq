/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: the sync-editor type-selector rules of
 *   openspec/specs/tables-bridge/spec.md            (REQ-004)
 *   openspec/specs/nextcloud-forms-connector/spec.md (REQ-001, REQ-002)
 *
 * These three scenarios are all of the shape "type X is NOT offered in the
 * selector". An absence assertion is the single easiest thing in a browser
 * suite to satisfy for the wrong reason: a selector that failed to open, a
 * page that never mounted, a locator that matches nothing — every one of them
 * renders "the option is not there" indistinguishably from the requirement
 * being met.
 *
 * So EVERY absence assertion in this file is paired with a positive control
 * that makes the same locator, on the same page, in the same run, produce a
 * match. The controls are not decoration; they are what makes the absence
 * mean anything.
 *
 * The controls come from a real behaviour of the editor rather than from a
 * second fixture that happens to differ. `SynchronizationDetailPage` keeps an
 * ALREADY-CONFIGURED bridge kind in its option list even when the companion
 * app is disabled, so that a stored `nextcloud-table` / `nextcloud-form` sync
 * still renders a label for its own type:
 *
 *     typeOptions()        -> TYPE_OPTIONS + NEXTCLOUD_TABLE_OPTION
 *                             iff tablesEnabled OR the draft already uses it
 *     sourceTypeOptions()  -> typeOptions   + NEXTCLOUD_FORM_OPTION
 *                             iff formsEnabled OR the draft already uses it
 *
 * That gives a discriminator that works on an instance where NEITHER app is
 * installed: seed one synchronization that already uses the bridge kind and
 * one that does not, and the option must appear on exactly one of them. A
 * broken selector cannot produce that difference.
 *
 * It also makes the target-type rule (REQ-002 / REQ-SYNCUI-008) testable at
 * full strength here: `NEXTCLOUD_FORM_OPTION` is appended to
 * `sourceTypeOptions` only, never to the `typeOptions` the TARGET selector
 * reads. On a sync whose sourceType IS `nextcloud-form`, the source selector
 * must offer it and the target selector must not — in the same DOM, at the
 * same moment.
 */
import { test, expect, type Page } from '@playwright/test'
import { APP_BASE, trackErrors, assertNoAppErrors } from './_helpers'
import {
	makeApiClient,
	createObject,
	deleteObject,
	type ApiClient,
} from '../workflows/_fixture'

const runId = `sedt-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`

let api: ApiClient
const created: Array<{ schema: string, id: string }> = []

/** uuid of the plain sync (sourceType api / targetType register/schema). */
let plainId = ''
/** uuid of the sync whose sourceType is already `nextcloud-form`. */
let formSyncId = ''
/** uuid of the sync whose targetType is already `nextcloud-table`. */
let tableSyncId = ''

/**
 * Whitespace-insensitive key for an option label.
 *
 * `allInnerTexts()` returns RENDERED text, and vue-select's option box is
 * narrow enough that the browser wraps INSIDE A WORD. Measured on this page:
 *
 *     "Register/Schema"  ->  "Register\n/Schema"
 *     "Nextcloud Table"  ->  "Nextclou\nd Table"
 *     "Nextcloud Form"   ->  "Nextclo\nud Form"
 *
 * Collapsing runs of whitespace to a single space is NOT enough — it yields
 * `"Nextclou d Table"`. The wrap point is a property of the viewport, not of
 * the label, so the only stable comparison is one that ignores whitespace
 * entirely. These assertions are about WHICH OPTIONS EXIST, not about label
 * formatting, so dropping whitespace loses nothing they care about.
 */
function optionKey(text: string): string {
	return text.replace(/\s+/g, '').toLowerCase()
}

/** The three kinds every selector offers unconditionally, as option keys. */
const BASE_KINDS = ['API', 'Register/Schema', 'File'].map(optionKey)

/**
 * Read the labels the given NcSelect currently offers, as whitespace-
 * insensitive keys (see `optionKey`).
 *
 * NcSelect wraps vue-select, which renders its options into
 * `.vs__dropdown-option` elements ONLY while the dropdown is open, so the
 * combobox has to be opened first. `input-id` is set on both selectors by
 * SynchronizationDetailPage (`sync-source-type` / `sync-target-type`), which
 * is what makes them individually addressable.
 */
async function optionLabels(page: Page, inputId: string): Promise<string[]> {
	const input = page.locator(`#${inputId}`)
	await expect(input, `the ${inputId} combobox must be present`).toBeVisible({ timeout: 20_000 })
	await input.click()
	const dropdown = page.locator('.vs__dropdown-menu').filter({ has: page.locator('.vs__dropdown-option') })
	await expect(dropdown.first(), `${inputId} must open a non-empty option list`).toBeVisible({ timeout: 10_000 })
	const labels = (await dropdown.first().locator('.vs__dropdown-option').allInnerTexts())
		.map(optionKey)
		.filter(Boolean)
	// Close it again so the next selector on the same page is clickable.
	await page.keyboard.press('Escape')
	return labels
}

/** Open a synchronization's detail page and wait for the editor to mount. */
async function openSync(page: Page, id: string): Promise<void> {
	await page.goto(`${APP_BASE}/synchronizations/${id}`, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('#sync-source-type'), 'the sync editor must mount').toBeVisible({ timeout: 25_000 })
}

test.beforeAll(async ({ browser, baseURL }) => {
	api = await makeApiClient(browser, baseURL!)

	const mk = async (data: Record<string, unknown>) => {
		const obj = await createObject(api, 'synchronization', data)
		const id = obj.id ?? obj.uuid
		created.push({ schema: 'synchronization', id })
		return obj.uuid ?? obj.id
	}

	plainId = await mk({
		name: `${runId} plain`,
		description: 'seeded by sync-editor-bridge-types.spec.ts — uses neither bridge kind',
		sourceType: 'api',
		targetType: 'register/schema',
	})
	formSyncId = await mk({
		name: `${runId} form-source`,
		description: 'seeded by sync-editor-bridge-types.spec.ts — sourceType is already nextcloud-form',
		sourceType: 'nextcloud-form',
		targetType: 'register/schema',
	})
	tableSyncId = await mk({
		name: `${runId} table-target`,
		description: 'seeded by sync-editor-bridge-types.spec.ts — targetType is already nextcloud-table',
		sourceType: 'api',
		targetType: 'nextcloud-table',
	})
})

test.afterAll(async () => {
	for (const { schema, id } of created.reverse()) {
		await deleteObject(api, schema, id)
	}
	await api?.dispose()
})

test.describe('Sync editor type selectors — companion-app gating', () => {
	/**
	 * Guard the GIVEN of every scenario in this file: "the app is not
	 * installed on this Nextcloud instance". The editor asks
	 * `/api/synchronizations/<bridge>-bridge/status`, so that is the same
	 * source of truth the UI uses — not a guess about the instance.
	 *
	 * If a companion app IS installed, these scenarios' precondition does not
	 * hold and the test must say so rather than assert something else.
	 */
	async function bridgeEnabled(bridge: 'tables' | 'forms'): Promise<boolean> {
		const res = await api.request.get(
			`/index.php/apps/openconnector/api/synchronizations/${bridge}-bridge/status`,
			{ failOnStatusCode: false },
		)
		expect(res.status(), `${bridge}-bridge status endpoint must answer 200`).toBe(200)
		return Boolean((await res.json())?.enabled)
	}

	// @e2e tables-bridge::tables-app-absent-hides-the-type-in-the-editor
	test('with Tables absent, "Nextcloud Table" is not offered — but still renders for a sync that uses it', async ({ page }) => {
		const sink = trackErrors(page)
		test.skip(await bridgeEnabled('tables'), 'Tables IS installed here — this scenario\'s GIVEN does not hold')

		// THE REQUIREMENT.
		await openSync(page, plainId)
		const plainSource = await optionLabels(page, 'sync-source-type')
		const plainTarget = await optionLabels(page, 'sync-target-type')
		expect(plainSource, 'source selector must not offer Nextcloud Table').not.toContain(optionKey('Nextcloud Table'))
		expect(plainTarget, 'target selector must not offer Nextcloud Table').not.toContain(optionKey('Nextcloud Table'))

		// POSITIVE CONTROL #1 — the selectors are alive and DO list the
		// unconditional kinds. Without this, both assertions above would pass on
		// a page that rendered no options at all.
		expect(plainSource).toEqual(expect.arrayContaining(BASE_KINDS))
		expect(plainTarget).toEqual(expect.arrayContaining(BASE_KINDS))

		// POSITIVE CONTROL #2 — the option is not merely absent from the build.
		// The same locator, on the same instance with Tables still absent, DOES
		// find "Nextcloud Table" on a sync that already uses it. So the absence
		// above is the gating rule, not a missing option or a dead locator.
		await openSync(page, tableSyncId)
		expect(
			await optionLabels(page, 'sync-target-type'),
			'a sync already configured as nextcloud-table must keep the option visible',
		).toContain(optionKey('Nextcloud Table'))

		assertNoAppErrors(sink)
	})

	// @e2e nextcloud-forms-connector::forms-app-absent-hides-the-source-type-in-the-editor
	test('with Forms absent, "Nextcloud Form" is not offered as a source — but still renders for a sync that uses it', async ({ page }) => {
		const sink = trackErrors(page)
		test.skip(await bridgeEnabled('forms'), 'Forms IS installed here — this scenario\'s GIVEN does not hold')

		await openSync(page, plainId)
		const plainSource = await optionLabels(page, 'sync-source-type')
		expect(plainSource, 'source selector must not offer Nextcloud Form').not.toContain(optionKey('Nextcloud Form'))
		// Positive control #1: the list is real.
		expect(plainSource).toEqual(expect.arrayContaining(BASE_KINDS))

		// Positive control #2: same locator, same Forms-less instance, option
		// present on a sync that already uses the kind.
		await openSync(page, formSyncId)
		expect(
			await optionLabels(page, 'sync-source-type'),
			'a sync already configured as nextcloud-form must keep the option visible',
		).toContain(optionKey('Nextcloud Form'))

		assertNoAppErrors(sink)
	})

	// @e2e nextcloud-forms-connector::nextcloud-form-is-never-selectable-as-a-target-type
	test('"Nextcloud Form" is offered as a source and NOT as a target on the very same page', async ({ page }) => {
		const sink = trackErrors(page)

		// This one deliberately does NOT skip on the Forms app's state. The
		// requirement is "regardless of whether the Forms app is enabled", and
		// the discriminator used here — a sync whose sourceType already IS
		// nextcloud-form — makes the source option appear in EITHER state
		// (`this.formsEnabled || usesForm`). So the test is at full strength
		// with Forms installed or absent.
		await openSync(page, formSyncId)

		const source = await optionLabels(page, 'sync-source-type')
		const target = await optionLabels(page, 'sync-target-type')

		// The positive control and the requirement are the same two reads of the
		// same page: the option demonstrably CAN be listed here, and it is not
		// listed on the target.
		expect(source, 'source selector must offer Nextcloud Form for a form-sourced sync').toContain(optionKey('Nextcloud Form'))
		expect(target, 'nextcloud-form must NEVER be offered as a target kind').not.toContain(optionKey('Nextcloud Form'))
		// And the target list is otherwise the full set, so "not contain" is not
		// standing in for "empty".
		expect(target).toEqual(expect.arrayContaining(BASE_KINDS))

		assertNoAppErrors(sink)
	})
})
