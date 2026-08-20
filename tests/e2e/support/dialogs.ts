/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Locating *the app's own* modal, as opposed to whatever chrome happens to be
 * on top of it.
 *
 * `page.getByRole('dialog').first()` is the obvious way to grab a modal and the
 * wrong one. Two other things on a Nextcloud page also claim `role="dialog"`:
 *
 *   - `#firstrunwizard`, Nextcloud's own welcome overlay, and
 *   - `.cn-support-dialog`, the @conduction/nextcloud-vue support prompt.
 *
 * Both are full-screen masks that hide nothing a visibility assertion inspects,
 * so they break clicks rather than renders — the button under them stays
 * `toBeVisible()` while the click is swallowed with "subtree intercepts pointer
 * events". The trap is what happens next: because they are themselves dialogs,
 * a spec that clicks (and misses) and then asserts on
 * `getByRole('dialog').first()` finds the overlay, goes green, and reports that
 * a modal it never opened is showing.
 *
 * `global-setup.ts` retires both before any spec runs, so in a healthy run this
 * helper and the naive locator agree. It exists so that when the setup step
 * regresses the specs fail honestly instead of passing against NC chrome.
 */
import type { Locator, Page } from '@playwright/test'

/** Selectors for overlays that are not the application's own modal. */
const CHROME_DIALOGS = [
	'#firstrunwizard',
	'.cn-support-dialog',
	'[data-testid-modal="cn-support-dialog"]',
]

/**
 * The first `role="dialog"` that belongs to the application itself.
 *
 * @param page The page under test.
 * @return A locator for the app's modal, excluding Nextcloud and nc-vue chrome.
 */
export function appDialog(page: Page): Locator {
	// Self-exclusion, not descendant-exclusion: `filter({ hasNot })` asks about
	// a dialog's children, whereas the overlays we are ruling out ARE the
	// matched element. A `:not()` chain in the selector is the honest way.
	const notChrome = CHROME_DIALOGS.map((sel) => `:not(${sel})`).join('')
	return page.locator(`[role="dialog"]${notChrome}`).first()
}
