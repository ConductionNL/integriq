/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The single source of truth for which Nextcloud the e2e suite talks to.
 *
 * Every spec, helper and the Playwright config itself must resolve the target
 * instance through this module. Two rules, both learned the hard way:
 *
 *  1. There is NO hardcoded default. The config used to read
 *     `process.env.NEXTCLOUD_URL || 'http://localhost:8080'` and several specs
 *     hardcoded `http://localhost:8080` outright — that is the *shared* dev
 *     container. A suite that silently falls back to it creates fixtures in
 *     other people's environment and reports measurements taken somewhere
 *     nobody intended. Failing loudly on an unset variable is strictly better
 *     than defaulting to someone else's instance.
 *
 *     `PLAYWRIGHT_BASE_URL` wins when set, but `BASE_URL` is accepted too:
 *     that is the name the shared `ConductionNL/.github` quality workflow
 *     exports. An earlier revision of this module read `PLAYWRIGHT_BASE_URL`
 *     *only*, and integriq's "E2E Tests (Playwright)" job hard-failed on
 *     every CI run since with `Error: PLAYWRIGHT_BASE_URL is not set.` —
 *     locally correct, and dead everywhere it mattered. Strict about never
 *     inventing a target; permissive about which variable names it.
 *
 *     `NEXTCLOUD_URL` and `NC_BASE_URL` are accepted for the same reason.
 *     The shared quality workflow's "Run Playwright tests" step exports all
 *     three of BASE_URL / NEXTCLOUD_URL / NC_BASE_URL (verified in
 *     `ConductionNL/.github/.github/workflows/quality.yml`), and 15 of the 21
 *     fleet repos resolve their target as `process.env.NEXTCLOUD_URL || ...`.
 *     Accepting every name the fleet uses costs nothing; the thing that must
 *     stay gone is the literal fallback, not the alternate spellings.
 *
 *  2. Absolute and relative navigation must not be able to disagree. Specs that
 *     build their own `http://host:port/...` strings drifted away from
 *     `use.baseURL` the moment either changed. `absoluteUrl()` derives those
 *     strings from the same value Playwright navigates with, so there is only
 *     one thing to get right.
 */

const CANDIDATES = [
	'PLAYWRIGHT_BASE_URL',
	'BASE_URL',
	'NEXTCLOUD_URL',
	'NC_BASE_URL',
] as const

const RAW =
	CANDIDATES.map((name) => process.env[name]?.trim()).find(
		(value) => value !== undefined && value !== '',
	) ?? ''

if (!RAW) {
	throw new Error(
		`None of ${CANDIDATES.join(', ')} is set.\n\n`
			+ 'The e2e suite deliberately has no default: it used to fall back to\n'
			+ 'http://localhost:8080, which is the SHARED dev container, and tests\n'
			+ 'then wrote fixtures into an environment other sessions were using.\n\n'
			+ 'Point it at your own isolated instance, e.g.\n'
			+ '  PLAYWRIGHT_BASE_URL=http://localhost:8097 npm run test:e2e\n\n'
			+ 'In CI the shared quality workflow exports BASE_URL, NEXTCLOUD_URL and\n'
			+ 'NC_BASE_URL, all of which are accepted; if you are seeing this in CI,\n'
			+ 'those exports are missing.\n',
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

/**
 * The instance under test, split for Node's `http`/`https` request options.
 *
 * Some specs bypass Playwright entirely and use `http.request()` so the call
 * carries no cookies from `storageState` — a legitimate need when asserting an
 * unauthenticated response. The trap is that `http.request()` takes `hostname`
 * and `port` as *separate structured fields*, so a hardcoded `port: 8080`
 * neither reads `use.baseURL` nor looks like a URL to anyone grepping for
 * "localhost:8080". Two specs pointed their raw login POSTs at the shared dev
 * container that way, firing failed-login attempts — and therefore brute-force
 * lockouts on `admin` — into an environment other people were using.
 *
 * @return `{ protocol, hostname, port }` for the instance under test, with the
 *   port defaulted from the protocol when the URL omits it.
 */
export function baseUrlParts(): {
	protocol: string
	hostname: string
	port: number
} {
	const url = new URL(BASE_URL)
	return {
		protocol: url.protocol,
		hostname: url.hostname,
		port: Number(url.port || (url.protocol === 'https:' ? 443 : 80)),
	}
}
