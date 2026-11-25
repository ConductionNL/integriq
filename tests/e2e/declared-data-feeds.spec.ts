/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md
 * (REQ-DDF-001, task 4: the Data feeds page)
 *
 * An administrator opens Data feeds from the menu and finds each declared
 * feed with its app, address, fields and page size; ordinary endpoints are
 * not in that list, and nothing can be added there, because a feed exists
 * only when an app declares it. The feed endpoint is written through
 * OpenRegister's object API in the shape FeedDeclarationService stores, so the
 * page is tested without depending on shillinq being installed; the sync
 * itself is PHPUnit's (FeedDeclarationServiceTest) and the feed's answers are
 * Newman's (tests/postman/integriq-data-feeds.postman_collection.json).
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { withRequestToken } from './support/requestToken.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects/integriq'
const RUN = Date.now().toString(36)
const APP = `e2efeeds${RUN}`
const FEED_NAME = `E2E ledger lines ${RUN}`
const PLAIN_NAME = `E2E plain endpoint ${RUN}`

const created: string[] = []

/**
 * Write one endpoint through OpenRegister's object API.
 *
 * @param request The administrator's request context.
 * @param data    The endpoint.
 * @return the endpoint's id
 */
async function createEndpoint(request: APIRequestContext, data: Record<string, unknown>): Promise<string> {
	const resp = await request.post(`${OBJECTS}/endpoint`, {
		data,
		headers: await withRequestToken(request),
		failOnStatusCode: false,
	})
	expect(resp.status(), await resp.text()).toBeLessThan(300)
	const body = await resp.json()
	const id = String(body.id ?? body.uuid ?? body['@self']?.id)
	created.push(id)
	return id
}

test.describe('declared data feeds page', () => {
	test.beforeAll(async ({ request }) => {
		await createEndpoint(request, {
			name: FEED_NAME,
			description: 'Declared by the e2e test, as FeedDeclarationService stores a feed.',
			reference: `feed:${APP}:ledger-lines`,
			endpoint: `feeds/${APP}/ledger-lines`,
			endpointArray: ['feeds', APP, 'ledger-lines'],
			endpointRegex: `#^feeds/${APP}/ledger-lines$#`,
			method: 'GET',
			targetType: 'feed',
			targetId: `${APP}/ledger-line`,
			feed: {
				app: APP,
				key: 'ledger-lines',
				title: 'Ledger lines',
				register: APP,
				schema: 'ledger-line',
				fields: ['period', 'account', 'amount', 'administrationId'],
				filter: { status: 'posted' },
				scope: { parameter: 'administrationId', field: 'administrationId' },
				pageSize: 750,
			},
		})
		await createEndpoint(request, {
			name: PLAIN_NAME,
			endpoint: `e2e-plain-${RUN}`,
			method: 'GET',
			targetType: 'register/schema',
			targetId: 'integriq/source',
		})
	})

	test.afterAll(async ({ request }) => {
		const headers = await withRequestToken(request)
		for (const id of created) {
			await request.delete(`${OBJECTS}/endpoint/${id}`, { headers, failOnStatusCode: false })
		}
	})

	// @e2e declared-data-feeds::an-administrator-sees-the-declared-feeds
	test('Data feeds in the menu lists the declared feed with its address, fields and page size', async ({ page }) => {
		await page.goto('/index.php/apps/integriq/')
		// Data feeds sits in the Gateway group, which starts collapsed.
		const dataFeeds = page.getByRole('link', { name: 'Data feeds' }).first()
		if (!(await dataFeeds.isVisible())) {
			await page.getByRole('navigation').getByText('Gateway', { exact: true }).click()
		}
		await dataFeeds.click()
		await expect(page).toHaveURL(/targetType=feed/)

		const row = page.getByRole('row').filter({ hasText: FEED_NAME })
		await expect(row).toBeVisible({ timeout: 15_000 })
		await expect(row).toContainText(APP)
		await expect(row).toContainText(`feeds/${APP}/ledger-lines`)
		// A list cell shows the first three fields and counts the rest.
		await expect(row).toContainText('period, account, amount +1')
		await expect(row).toContainText('750')
	})

	// @e2e declared-data-feeds::a-feed-is-declared-not-added
	test('the list holds feeds only, and offers nothing to add', async ({ page }) => {
		await page.goto('/index.php/apps/integriq/endpoints?targetType=feed')
		await expect(page.getByRole('row').filter({ hasText: FEED_NAME })).toBeVisible({ timeout: 15_000 })
		await expect(page.getByRole('row').filter({ hasText: PLAIN_NAME })).toHaveCount(0)
		await expect(page.getByText('A feed is declared by its app in lib/Settings/feeds.json.')).toBeVisible()
		await expect(page.getByRole('button', { name: /^(Add|New|Create)/ })).toHaveCount(0)
	})
})
