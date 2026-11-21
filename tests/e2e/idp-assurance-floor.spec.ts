/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * tests/e2e/idp-assurance-floor.spec.ts
 *
 * Spec coverage: openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md
 * (REQ-IDPC-006, substantial is the assurance floor), with the refusal side
 * of openspec/changes/portal-idp-broker-config/specs/digid-eherkenning-auth-adapter/spec.md
 * (REQ-IDP-002, the callback returns the browser with a one-time code).
 * Task 12 of openspec/changes/portal-idp-broker-config/tasks.md.
 *
 * What a resident's browser meets here, without a session, on the public
 * routes of IdpBrokerController:
 *   - a start that asks for trust `low` gets integriq's own error page and is
 *     sent nowhere: no redirect, no identity provider;
 *   - a response that answers no stored state gets the same error page, not
 *     the below-floor page, and no code.
 *
 * Neither passes on the floor alone in this build: the container binds the
 * Log adapter (lib/Auth/AuthBindings.php) and IdpLoginService still starts
 * and finishes through `forProvider()`, so every start and every callback is
 * refused before a live adapter is reached. They pin what the browser must
 * never see (a redirect, a code, the refusal page without a refused login)
 * and keep doing so once a live adapter is bound; that the trust check itself
 * refuses `low` is PHPUnit's (IdpBrowserLoginTest, TrustLevelFloorTest).
 *
 * Deliberately not run: "DigiD Midden is refused with a clear message". It
 * needs a stored state and an assertion at Midden reaching the callback, which
 * takes a stub identity provider the instance can reach and a live adapter
 * bound to it. The nightly job has neither, and a login through a real
 * external identity provider cannot run there. The test below is a fixme that
 * names the blocker; the page itself (templates/idp/refused.php, the message,
 * the hint and the action with `error=assurance_below_floor` and the relay
 * state) is covered by PHPUnit (IdpBrokerControllerFloorTest) until then. The
 * other floor scenarios (EH3 accepted, EH2+ refused, the minimum comparison
 * in the request) carry `@e2e exclude` in the spec.
 *
 * Every request goes out without cookies (support/anonymous.ts): these are
 * the routes a resident reaches before anyone is logged in.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { anonymousRequest } from './support/anonymous.ts'

const IDP_BASE = '/index.php/apps/integriq/api/idp'

/** A consumer's registered return address and relay state: obvious test values. */
const RETURN_URL = 'https://portal.example.org/e2e/login/return'
const RELAY_STATE = `e2e-relay-${Date.now().toString(36)}`

/** An organisation uuid nobody registered. */
const ORGANISATION = 'e2e00002-0000-4000-8000-000000000f10'

/** The page integriq shows when a login cannot go anywhere safe. */
const ERROR_TITLE = 'You cannot sign in right now'

/** The title of the below-floor page, which only a refused assertion may show. */
const FLOOR_TITLE = 'This service needs a stronger login'

test.describe('portal-idp-broker-config: the assurance floor', () => {
	let anonymous: APIRequestContext

	test.beforeAll(async () => {
		anonymous = await anonymousRequest({ 'Accept-Language': 'en' })
	})

	test.afterAll(async () => {
		await anonymous.dispose()
	})

	// @e2e idp-broker-configuration::digid-midden-is-refused-with-a-clear-message
	test('DigiD Midden is refused with a clear message', () => {
		test.fixme(
			true,
			'needs a stub identity provider reachable from the instance and a live adapter bound to it; the nightly job has neither',
		)
	})

	// "A start asking for low is refused" carries `@e2e exclude` in the spec;
	// this pins the browser side only, so it has no @e2e tag.
	test('a start asking for low shows the error page and redirects nowhere', async () => {
		const resp = await anonymous.get(`${IDP_BASE}/digid/start`, {
			params: {
				organisation: ORGANISATION,
				consumer: 'portaliq',
				trust: 'low',
				returnUrl: RETURN_URL,
				relayState: RELAY_STATE,
			},
			maxRedirects: 0,
			failOnStatusCode: false,
		})

		expect(resp.status(), 'integriq answers itself').toBe(400)
		expect(resp.headers().location, 'the browser is sent nowhere').toBeUndefined()
		const body = await resp.text()
		expect(body).toContain(ERROR_TITLE)
		expect(body, 'no identity provider is named as a destination').not.toContain('SAMLRequest')
		expect(body).not.toContain(FLOOR_TITLE)
		expect(body).not.toContain('assurance_below_floor')
	})

	// REQ-IDP-002: only a refused assertion shows the below-floor page, and a
	// response that answers no stored state issues no code. That scenario
	// carries `@e2e exclude`; this pins the browser side only.
	test('a response that answers no stored state gets the error page and no code', async () => {
		const resp = await anonymous.post(`${IDP_BASE}/digid/callback`, {
			form: { SAMLResponse: 'e2e-not-a-saml-response', RelayState: RELAY_STATE },
			maxRedirects: 0,
			failOnStatusCode: false,
		})

		expect(resp.status()).toBe(400)
		expect(resp.headers().location, 'nothing is redirected').toBeUndefined()
		const body = await resp.text()
		expect(body).toContain(ERROR_TITLE)
		expect(body, 'the below-floor page is not shown for an unanswered response').not.toContain(FLOOR_TITLE)
		expect(body).not.toContain(RETURN_URL)
	})
})
