/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The CSRF token of the session a request context carries.
 *
 * The `request` fixture sends the admin's session cookies (see
 * playwright.config.ts `use.storageState`) but no `requesttoken`. Nextcloud
 * answers 412 to any route that does not declare `NoCSRFRequired` when a
 * session-cookie request arrives without one, and that includes GET routes.
 * The browser never meets that 412, because the app's axios sends the token
 * on every call; a spec calling the same route directly has to do the same.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect } from '@playwright/test'

/**
 * Read the session's token from Nextcloud's own endpoint.
 *
 * @param request A context that carries a logged-in session.
 * @return Headers to send with the call.
 */
export async function withRequestToken(
	request: APIRequestContext,
): Promise<Record<string, string>> {
	const resp = await request.get('/index.php/csrftoken', {
		failOnStatusCode: false,
	})
	expect(resp.status(), 'the session must hand out a request token').toBe(200)
	const token = String((await resp.json()).token ?? '')
	expect(token, 'the request token must not be empty').not.toBe('')

	return { requesttoken: token }
}
