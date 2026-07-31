/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The single source of truth for which Nextcloud the e2e suite talks to.
 *
 * Every spec, helper and the Playwright config itself must resolve the target
 * instance through this module. Two rules, both learned the hard way:
 *
 *  1. `PLAYWRIGHT_BASE_URL` is authoritative and there is NO default. The
 *     config used to read `process.env.NEXTCLOUD_URL || 'http://localhost:8080'`
 *     and several specs hardcoded `http://localhost:8080` outright — that is
 *     the *shared* dev container. A suite that silently falls back to it
 *     creates fixtures in other people's environment and reports measurements
 *     taken somewhere nobody intended. Failing loudly on an unset variable is
 *     strictly better than defaulting to someone else's instance.
 *
 *  2. Absolute and relative navigation must not be able to disagree. Specs that
 *     build their own `http://host:port/...` strings drifted away from
 *     `use.baseURL` the moment either changed. `absoluteUrl()` derives those
 *     strings from the same value Playwright navigates with, so there is only
 *     one thing to get right.
 */

const RAW = process.env.PLAYWRIGHT_BASE_URL ?? ''

if (!RAW.trim()) {
	throw new Error(
		'PLAYWRIGHT_BASE_URL is not set.\n\n'
		+ 'The e2e suite deliberately has no default: it used to fall back to\n'
		+ 'http://localhost:8080, which is the SHARED dev container, and tests\n'
		+ 'then wrote fixtures into an environment other sessions were using.\n\n'
		+ 'Point it at your own isolated instance, e.g.\n'
		+ '  PLAYWRIGHT_BASE_URL=http://localhost:8097 npm run test:e2e\n',
	)
}

/**
 * The base URL of the Nextcloud under test, without a trailing slash.
 */
export const BASE_URL: string = RAW.trim().replace(/\/+$/, '')

/**
 * Build an absolute URL against the instance under test.
 *
 * Use this anywhere a bare string URL is unavoidable (a raw `http.get`, an
 * `apiRequest.newContext({ baseURL })`, a printed diagnostic). Prefer plain
 * relative paths with `page.goto('/index.php/apps/...')` where Playwright
 * applies `use.baseURL` for you.
 *
 * @param pathname Absolute path beginning with `/`, e.g. `/status.php`.
 * @return The path resolved against BASE_URL.
 */
export function absoluteUrl(pathname: string): string {
	return `${BASE_URL}${pathname.startsWith('/') ? pathname : `/${pathname}`}`
}
