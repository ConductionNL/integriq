/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
 * (REQ-EVS-002's wildcard refusal and REQ-EVS-003; the Playwright test of
 * task 6 in that change's tasks.md).
 *
 * What an administrator does here: on integriq's admin settings page
 * (/index.php/settings/admin/integriq), in the section "Environment variables
 * an expression may read" (ExpressionSourceSettings / AllowlistEditor), type
 * a variable name and press Add to the list. A wildcard is refused with the
 * backend's reason, an empty entry cannot be submitted, and a name that is
 * accepted is listed with who added it and when, never with its value.
 *
 * No register object is involved: the allowlist lives in integriq's app
 * config and is reached only through /api/admin/expression-sources, so
 * nothing is staged through OpenRegister's object API. The list is
 * instance-wide state; every name this file adds carries this run's marker
 * and is removed again at the end, and the test checks that a refused entry
 * left the list as it was.
 *
 * The value check needs a variable the server really has, with a value the
 * test can recognise. A browser run cannot put a secret into the server's
 * environment, so PATH stands in for it: every PHP process has it, and its
 * value contains `/usr/bin`. PATH is added only when it is not on the list
 * yet, and removed again only when this file added it.
 *
 * Left to PHPUnit, as the spec's `@e2e exclude` lines say: prefix dispatch,
 * an unregistered prefix, describe(), resolving an allowlisted and an unlisted
 * variable and refusing a write (ExpressionValueSourcesTest), a
 * non-administrator refused with 403 and both
 * guards present on every endpoint (ExpressionSourceControllerTest), and the
 * redaction of a resolved value before a trace buffers it (REQ-EVS-004). The
 * log line the controller writes for each change is not read here: a browser
 * run cannot read the server log.
 *
 * Runs as the admin the global setup logs in.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'
import { withRequestToken } from './support/requestToken.ts'

const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'
const SOURCES_URL = `${APP_BASE}/api/admin/expression-sources`

/** The administrator the global setup logs in. */
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'

/**
 * One run's marker. An environment variable name is letters, digits and
 * underscores only (EnvironmentAllowlist::KEY_PATTERN), so the marker is too.
 */
const RUN = `E2E_EVS_${Date.now().toString(36).toUpperCase()}`

/**
 * The name the administrator adds. The scenario names BRP_BASE_URL; this
 * run's marker is appended so the test neither meets a real entry of that
 * name nor removes one at the end.
 */
const ADDED_KEY = `BRP_BASE_URL_${RUN}`

/** The stand-in for a variable whose value is a secret. */
const VALUE_KEY = 'PATH'

/** A fragment of PATH's value on every server this runs on. */
const VALUE_FRAGMENT = '/usr/bin'

/** The names this file added, removed again at the end. */
const added: string[] = []

/** One entry of GET /api/admin/expression-sources. */
interface ListedKey {
	key: string
	addedBy: string
	addedAt: string
	[other: string]: unknown
}

/**
 * Headers for a call outside the app's own axios: the OCS marker and the
 * session's CSRF token.
 *
 * @param request The request fixture.
 * @return The headers.
 */
async function adminHeaders(request: APIRequestContext): Promise<Record<string, string>> {
	return { 'OCS-APIRequest': 'true', ...(await withRequestToken(request)) }
}

/**
 * The allowlist as the backend holds it.
 *
 * @param request The request fixture.
 * @return The listed keys.
 */
async function listedKeys(request: APIRequestContext): Promise<ListedKey[]> {
	const resp = await request.get(SOURCES_URL, { headers: await adminHeaders(request), failOnStatusCode: false })
	expect(resp.status(), await resp.text()).toBe(200)

	return ((await resp.json()).keys ?? []) as ListedKey[]
}

/**
 * Open the admin settings page at the environment allowlist section.
 *
 * @param page The page.
 * @return The section.
 */
async function openSection(page: Page): Promise<Locator> {
	await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
	const section = page.getByTestId('admin-expression-sources-section')
	await expect(section).toBeVisible({ timeout: 20_000 })
	await expect(section.getByText('Loading the allowlist…')).toHaveCount(0, { timeout: 20_000 })

	return section
}

/**
 * Type a name into the section and press Add to the list.
 *
 * @param page The page.
 * @param section The section.
 * @param key The name.
 * @return The status the add answered with.
 */
async function addThroughSection(page: Page, section: Locator, key: string): Promise<number> {
	// NcTextField puts data-testid on the input itself.
	await section.getByTestId('admin-expression-sources-key').fill(key)
	const answered = page.waitForResponse(
		(resp) => resp.url().includes('/api/admin/expression-sources/env') && resp.request().method() === 'POST',
	)
	await section.getByTestId('admin-expression-sources-add').click()

	return (await answered).status()
}

test.describe('allowlisted-expression-sources: the environment allowlist', () => {
	test.describe.configure({ mode: 'serial' })

	test.afterAll(async ({ request }) => {
		const headers = await adminHeaders(request)
		for (const key of added.splice(0)) {
			await request.delete(`${SOURCES_URL}/env/${encodeURIComponent(key)}`, { headers, failOnStatusCode: false })
		}
	})

	// @e2e expression-value-sources::no-wildcard-is-accepted
	test('a wildcard, a pattern and an empty entry are refused, and the list is unchanged', async ({ page, request }) => {
		const before = await listedKeys(request)
		const section = await openSection(page)

		for (const entry of ['*', 'DB_*']) {
			expect(await addThroughSection(page, section, entry), `"${entry}" is refused`).toBe(422)
			const alert = section.getByRole('alert')
			await expect(alert, 'the refusal is shown as the backend words it').toContainText(
				'every key must be listed by its exact name',
			)
			await expect(alert).toContainText(entry)
			await expect(
				section.getByTestId('admin-expression-sources-row').filter({ hasText: entry }),
				`"${entry}" is not listed`,
			).toHaveCount(0)
		}

		// The screen does not send an empty entry: Add to the list stays
		// disabled while the field is blank (AllowlistEditor.vue).
		await section.getByTestId('admin-expression-sources-key').fill('   ')
		await expect(section.getByTestId('admin-expression-sources-add')).toBeDisabled()

		// The backend refuses one sent around the screen.
		const empty = await request.post(`${SOURCES_URL}/env`, {
			headers: await adminHeaders(request),
			data: { key: '' },
			failOnStatusCode: false,
		})
		expect(empty.status(), 'an empty entry is refused').toBe(422)
		expect((await empty.json()).error).toContain('An empty entry is refused')

		const after = await listedKeys(request)
		expect(after.map((entry) => entry.key), 'the allowlist is unchanged').toEqual(before.map((entry) => entry.key))
	})

	// @e2e expression-value-sources::an-administrator-adds-a-key-and-the-addition-is-recorded
	test('an added name is listed with who added it and when', async ({ page, request }) => {
		const section = await openSection(page)

		expect(await addThroughSection(page, section, ADDED_KEY), 'the name is accepted').toBe(201)
		added.push(ADDED_KEY)
		await expect(page.getByText(`${ADDED_KEY} added to the allowlist.`)).toBeVisible({ timeout: 10_000 })
		await expect(section.getByRole('alert')).toHaveCount(0)
		await expect(section.getByTestId('admin-expression-sources-key'), 'the field is cleared').toHaveValue('')

		// Cells: Variable, Added by, Added on, Actions.
		const row = section.getByTestId('admin-expression-sources-row').filter({ hasText: ADDED_KEY })
		await expect(row, 'the name is listed').toHaveCount(1, { timeout: 10_000 })
		const cells = row.getByRole('cell')
		await expect(cells.nth(0)).toHaveText(ADDED_KEY)
		await expect(cells.nth(1), 'with who added it').toHaveText(ADMIN_USER)
		await expect(cells.nth(2), 'and when').toHaveText(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/)
		await expect(row.getByRole('button', { name: `Remove ${ADDED_KEY}` })).toBeVisible()

		// The stored record says the same.
		const entry = (await listedKeys(request)).find((item) => item.key === ADDED_KEY)
		expect(entry, 'the change is recorded').toBeTruthy()
		expect(entry!.addedBy).toBe(ADMIN_USER)
		expect(entry!.addedAt, 'with the time').not.toBe('')

		// A reload still lists it: the addition is stored, not only shown.
		const reloaded = await openSection(page)
		await expect(reloaded.getByTestId('admin-expression-sources-row').filter({ hasText: ADDED_KEY })).toHaveCount(1)
	})

	// @e2e expression-value-sources::adding-a-key-does-not-print-its-value
	test('a listed variable is shown by name and its value is not', async ({ page, request }) => {
		const alreadyListed = (await listedKeys(request)).some((item) => item.key === VALUE_KEY)
		const section = await openSection(page)

		if (alreadyListed === false) {
			expect(await addThroughSection(page, section, VALUE_KEY), `${VALUE_KEY} is accepted`).toBe(201)
			added.push(VALUE_KEY)
		}

		const row = section.getByTestId('admin-expression-sources-row').filter({ hasText: VALUE_KEY })
		await expect(row.getByRole('cell').first(), 'the name is shown').toHaveText(VALUE_KEY, { timeout: 10_000 })
		await expect(row.getByRole('cell'), 'name, who, when and the remove button; no value column').toHaveCount(4)
		await expect(section, 'the value is not on the screen').not.toContainText(VALUE_FRAGMENT)
		await expect(section.getByTestId('admin-expression-sources-list')).not.toContainText(VALUE_FRAGMENT)

		// The answer the screen reads carries no value and no "is it set" either.
		const resp = await request.get(SOURCES_URL, { headers: await adminHeaders(request) })
		expect(resp.status()).toBe(200)
		const body = await resp.text()
		expect(body, 'the list answer holds no value').not.toContain(VALUE_FRAGMENT)
		const entry = ((JSON.parse(body).keys ?? []) as ListedKey[]).find((item) => item.key === VALUE_KEY)
		expect(entry, `${VALUE_KEY} is listed`).toBeTruthy()
		expect(Object.keys(entry!).sort(), 'only the name, who and when').toEqual(['addedAt', 'addedBy', 'key'])
	})
})
