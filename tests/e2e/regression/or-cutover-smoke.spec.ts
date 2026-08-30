/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MIGRATED — this spec used to drive API-direct (pwRequest) HTTP assertions
 * against a running Nextcloud container. That is the Playwright anti-pattern:
 * Playwright e2e is UI-only; HTTP/contract assertions belong in Newman.
 *
 * All assertions previously here (OR source list, integriq register
 * schema count, graceful 4xx on a non-existent source uuid, deleted legacy
 * routes 404, preserved settings/rebase route) now live in the Newman
 * collection:
 *
 *   tests/postman/integriq.postman_collection.json
 *     - folder "02 — OR-backed CRUD (post-cutover smoke)"  (source list)
 *     - folder "11 — Settings"                              (settings/rebase)
 *     - folder "12 — OR cutover smoke (migrated from Playwright …)"
 *         (register schema count, non-existent-uuid 4xx, deleted routes 404)
 *
 * This file is intentionally a no-op skipped placeholder so the migration is
 * discoverable in git history and the spec name is not silently dropped.
 */

import { test } from '@playwright/test'

test.describe('OR cutover — end-to-end smoke (migrated to Newman)', () => {
	// `test.skip(true, reason)` inside the test, not `test.skip(title, fn)`.
	// The modifier form records NO description in the report, so this deliberate
	// migration placeholder appeared there as an unexplained skip — the same
	// shape as a spec someone disabled and forgot. The title said why; the
	// artifact did not. See ConductionNL/.github#559.
	test('migrated to the Newman collection', async () => {
		test.skip(
			true,
			'assertions moved to tests/postman/integriq.postman_collection.json '
				+ 'folders 02 (OR-backed CRUD), 11 (Settings) and 12 (OR cutover '
				+ 'smoke). This placeholder keeps the migration discoverable in git '
				+ 'history rather than silently dropping the spec name.',
		)
	})
})
