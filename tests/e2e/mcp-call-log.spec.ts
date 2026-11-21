/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md
 * (REQ-MCPX-002, the call log names the MCP tool).
 *
 * What an administrator does here: open the source logs and pick the MCP
 * tool `search` in its filter, so only the calls to that tool are listed,
 * each without its arguments.
 *
 * The calls are staged as the call_log rows an mcp endpoint's relay writes
 * (an outbound call to the tool server, stamped with `mcpMethod` and
 * `mcpTool` by McpProxyHandler::stamp()), because relaying real calls needs a
 * tool server and a consumer key a browser run does not hold. That the relay
 * stamps the method and the tool, and keeps the arguments out unless the
 * endpoint stores them, is covered by PHPUnit on McpProxyHandler.
 *
 * Every row this file seeds carries this run's marker and is deleted
 * afterwards.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** This run's marker, so the rows are this run's and nothing else's. */
const RUN = `e2e-mcp-${Date.now()}`

/** Tool names of this run, so the picker offers them and nothing else matches. */
const SEARCH = `search-${RUN}`
const FETCH = `fetch-${RUN}`

/** Every object this file stored, as `[schema, id]`, removed at the end. */
const seeded: Array<[string, string]> = []

/**
 * Store one object and remember it for removal.
 *
 * @param request The request context (logged in as the administrator).
 * @param schema The integriq schema slug.
 * @param data The object's properties.
 * @return The object's id.
 */
async function seed(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, { failOnStatusCode: false, headers: HEADERS, data })
	expect(resp.status(), `seeding a ${schema} must succeed`).toBeLessThan(300)
	const body = await resp.json()
	const id = String(body.id ?? body['@self']?.id ?? body.uuid ?? '')
	expect(id, `the seeded ${schema} must have an id`).not.toBe('')
	seeded.push([schema, id])

	return id
}

/**
 * Pick an option of a log page's filter control.
 *
 * @param page The page.
 * @param key The control's key in src/manifest.json.
 * @param option The option's label.
 */
async function choose(page: Page, key: string, option: string): Promise<void> {
	const control = page.getByTestId(`cn-logs-page-filter-${key}`)
	await expect(control, `the ${key} control must be drawn`).toBeVisible({ timeout: 30_000 })
	const input = control.locator('input').first()
	await input.click()
	await input.fill(RUN)
	await page.getByRole('option', { name: option, exact: true }).first().click()
}

test.describe('gateway-mcp-proxy: the call log names the MCP tool', () => {
	test.beforeAll(async ({ request }) => {
		const calls = [
			{ mcpMethod: 'tools/call', mcpTool: SEARCH, statusMessage: `${RUN} search one` },
			{ mcpMethod: 'tools/call', mcpTool: SEARCH, statusMessage: `${RUN} search two` },
			{ mcpMethod: 'tools/call', mcpTool: FETCH, statusMessage: `${RUN} fetch one` },
			{ mcpMethod: 'tools/list', statusMessage: `${RUN} tool list` },
		]
		for (const call of calls) {
			// As the relay writes it with the endpoint's argument storage off: no request body.
			await seed(request, 'call_log', {
				direction: 'outbound',
				statusCode: 200,
				request: { method: 'POST', url: 'https://tools.e2e.invalid/mcp' },
				created: new Date().toISOString(),
				...call,
			})
		}
	})

	test.afterAll(async ({ request }) => {
		for (const [schema, id] of seeded.splice(0).reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false, headers: HEADERS })
		}
	})

	// @e2e endpoint-runtime::an-administrator-finds-every-call-to-one-tool
	test('filtering on tool search lists only the search calls, without their arguments', async ({ page }) => {
		await page.goto(`${APP_BASE}/sources/logs`, { waitUntil: 'domcontentloaded' })
		const logs = page.getByTestId('cn-logs-page')
		await expect(logs).toBeVisible({ timeout: 30_000 })
		await expect(logs.getByText(`${RUN} fetch one`)).toBeVisible({ timeout: 30_000 })

		await choose(page, 'mcpTool', SEARCH)
		await expect(page).toHaveURL(new RegExp(`mcpTool=${SEARCH}`))

		await expect(logs.getByText(`${RUN} search one`)).toBeVisible({ timeout: 15_000 })
		await expect(logs.getByText(`${RUN} search two`)).toBeVisible()
		await expect(logs.getByText(`${RUN} fetch one`), 'another tool is not listed').toHaveCount(0)
		await expect(logs.getByText(`${RUN} tool list`), 'a call that names no tool is not listed').toHaveCount(0)
		await expect(logs.getByText('"arguments"'), 'no listed call shows its arguments').toHaveCount(0)
	})
})
