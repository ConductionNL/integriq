/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * connectors-lti-platform-launch, as an administrator and a tool's browser see
 * it.
 *
 * REQ-LTIL-004: the LTI tool detail page shows the six values a tool's
 * administrator pastes into the tool's platform registration (issuer, client
 * id, deployment ids, authorization URL, token URL, key set URL), each with a
 * copy action. The tool and one deployment of it are written through
 * OpenRegister's object API, the store the registration catalogue reads.
 *
 * REQ-LTIL-002: the authorization endpoint answers a request it cannot accept
 * with an error page naming the failed check, and posts nothing. A request
 * without a login hint is the one a browser can make on its own here.
 *
 * The two scenarios that need a real login hint (the id_token reaching the
 * tool, and another user's hint refused) stay `fixme`: a hint is only issued
 * when a sibling app raises LtiLaunchRequestedEvent (learniq's lesson page),
 * and no route of this app raises it. They run once learniq's launch and the
 * reference tool fixture are on the test instance. PHPUnit covers both checks
 * in LtiPlatformLaunchTest.
 *
 * Runs as the admin the global setup logs in. The tool and the deployment are
 * removed at the end.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

/** One run's marker, so reruns never meet each other's objects. */
const RUN = `e2e-lti-${Date.now().toString(36)}`

/**
 * The id of a created object, whatever envelope OpenRegister answers with.
 *
 * @param body The response body.
 * @return The id.
 */
function idOf(body: Record<string, unknown>): string {
	const self = (body['@self'] ?? {}) as Record<string, unknown>
	return String(body.uuid ?? body.id ?? self.id ?? '')
}

/**
 * Create an object in the integriq register.
 *
 * @param request The request fixture.
 * @param schema The schema slug.
 * @param data The object.
 * @return The new object's id.
 */
async function createObject(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const resp = await request.post(`${OR_BASE}/${schema}`, { data, failOnStatusCode: false })
	expect([200, 201], `creating the ${schema} answers`).toContain(resp.status())
	const id = idOf(await resp.json())
	expect(id, `the new ${schema} has an id`).not.toBe('')
	return id
}

test.describe('connectors-lti-platform-launch', () => {
	const created: Array<[string, string]> = []

	test.afterAll(async ({ request }) => {
		for (const [schema, id] of created.reverse()) {
			await request.delete(`${OR_BASE}/${schema}/${id}`, { failOnStatusCode: false })
		}
	})

	// @e2e lti-platform::an-administrator-registers-a-tool-at-the-vendor
	test('the tool detail page shows the six platform values, each with a copy action', async ({ page, request }) => {
		const clientId = `${RUN}-client`
		const deploymentId = `${RUN}-deployment`
		const toolId = await createObject(request, 'lti_tool', {
			name: `${RUN} reference tool`,
			clientId,
			oidcLoginUrl: 'https://tool.example.test/lti/login',
			launchUrl: 'https://tool.example.test/lti/launch',
			redirectUris: ['https://tool.example.test/lti/launch'],
			jwksUri: 'https://tool.example.test/.well-known/jwks.json',
			status: 'approved',
		})
		created.push(['lti_tool', toolId])
		const deploymentUuid = await createObject(request, 'lti_deployment', {
			name: `${RUN} placement`,
			deploymentId,
			ltiToolId: toolId,
		})
		created.push(['lti_deployment', deploymentUuid])

		await page.goto(`${APP_BASE}/lti-tools/${toolId}`, { waitUntil: 'domcontentloaded' })

		const panel = page.getByTestId('lti-platform-details')
		await expect(panel).toBeVisible({ timeout: 15_000 })
		const rows = panel.getByTestId('lti-platform-detail')
		await expect(rows).toHaveCount(6)
		await expect(rows.locator('dt')).toHaveText([
			'Issuer',
			'Client ID',
			'Deployment IDs',
			'Authorization URL',
			'Token URL',
			'Key set URL',
		])

		await expect(panel.locator('[data-key="clientId"]')).toContainText(clientId)
		await expect(panel.locator('[data-key="deploymentIds"]')).toContainText(deploymentId)
		await expect(panel.locator('[data-key="authorizationUrl"]')).toContainText('/api/lti/platform/authorize')
		await expect(panel.locator('[data-key="tokenUrl"]')).toContainText('/api/lti/token')
		await expect(panel.locator('[data-key="keySetUrl"]')).toContainText('jwks.json')

		for (const label of ['Issuer', 'Client ID', 'Deployment IDs', 'Authorization URL', 'Token URL', 'Key set URL']) {
			await expect(panel.getByRole('button', { name: `Copy ${label}` })).toBeVisible()
		}
	})

	// REQ-LTIL-002: "On any failure it MUST show an error page naming the failed check and MUST NOT post anything."
	test('a request without a login hint gets an error page naming the hint, and nothing is posted', async ({ page }) => {
		const posts: string[] = []
		page.on('request', (req) => {
			if (req.method() === 'POST' && req.url().startsWith('https://tool.example.test')) {
				posts.push(req.url())
			}
		})

		const query = new URLSearchParams({
			client_id: `${RUN}-client`,
			redirect_uri: 'https://tool.example.test/lti/launch',
			nonce: `${RUN}-nonce`,
			state: `${RUN}-state`,
			response_type: 'id_token',
			response_mode: 'form_post',
			scope: 'openid',
			prompt: 'none',
		})
		const resp = await page.goto(`${APP_BASE}/api/lti/platform/authorize?${query}`, { waitUntil: 'domcontentloaded' })

		expect(resp?.status()).toBe(400)
		await expect(page.getByText('Failed check: hint')).toBeVisible()
		await expect(page.locator('form[method="post"]')).toHaveCount(0)
		expect(posts).toEqual([])
	})

	// @e2e lti-platform::the-tool-receives-an-id-token-it-accepts
	test.fixme('the browser posts an id_token with the tool nonce and state to the redirect URI', async () => {
		// Needs a login hint, issued only when learniq raises LtiLaunchRequestedEvent
		// from a lesson placement, and the reference tool fixture to receive the post.
	})

	// @e2e lti-platform::another-users-hint-is-refused
	test.fixme('a hint issued for another learner is refused naming the user', async () => {
		// Needs a login hint issued for one learner (learniq's launch) and a
		// second signed-in user presenting it.
	})
})
