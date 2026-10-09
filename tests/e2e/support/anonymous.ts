/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * A request context that carries no session at all.
 *
 * WHY THIS EXISTS
 * ---------------
 * `playwright.config.ts` sets `use.storageState` to the admin login that
 * global-setup writes. Playwright applies that option to EVERY request context
 * a test creates, not only to the `request` fixture: both
 * `playwright.request.newContext()` and the imported
 * `request.newContext({ baseURL })` send the admin's session cookies.
 *
 * So a probe written as "a fresh context, so the admin session cannot leak"
 * ran as the admin. On 2026-10-08 that turned seven "an anonymous request is
 * refused" tests red (an admin is allowed in) and left the passing ones
 * hollow (they never asked as anyone but the admin).
 *
 * The fix is to name the empty state explicitly. `storageState` with no
 * cookies and no origins overrides the config default.
 */

import type { APIRequestContext } from '@playwright/test'

import { request } from '@playwright/test'
import { BASE_URL } from './baseUrl.ts'

/**
 * Open a request context with no cookies, against the instance under test.
 *
 * @param extraHTTPHeaders Headers every request in the context carries.
 * @return A context the caller must dispose.
 */
export async function anonymousRequest(
	extraHTTPHeaders: Record<string, string> = {},
): Promise<APIRequestContext> {
	return request.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders,
	})
}
