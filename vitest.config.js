/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest configuration for OpenConnector frontend unit tests.
 *
 * Post the OR-cutover the app keeps almost no Pinia state (CnIndexPage /
 * CnDetailPage manage list/detail state against OR endpoints), so the
 * highest-value offline logic now lives in src/handlers/** and the bespoke
 * rule action-form helpers (src/views/Rule/actionForms/shared.js):
 *   • action handlers — POST to the right endpoint, toast success/error.
 *   • viewLogsHandler — actionId → route + query-param navigation.
 *   • routerRef — module-scoped router holder (set/get + null-safety).
 *   • fetchOpenRegisterCollection — OR list-envelope unwrap + option mapping.
 *   • patchMethod — immutable `update:value` emit for action forms.
 *
 * These need no DOM, so the environment is `node`. The @nextcloud/* runtime
 * deps that aren't mocked per-test are aliased to deterministic stubs.
 */

const path = require('path')

module.exports = {
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: [
			'tests/e2e/**',
			'tests/postman/**',
			'tests/integration/**',
			'src/**',
			'node_modules/**',
		],
		// @nextcloud/vue ships CSS side-effect imports next to its components.
		// Node's ESM loader cannot resolve a ".css" specifier, so the package
		// must be transformed by Vite rather than externalised, otherwise any
		// spec importing a real component dies with ERR_UNKNOWN_FILE_EXTENSION.
		server: {
			deps: {
				inline: [/@nextcloud\/vue/],
			},
		},
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
			{
				find: /^@nextcloud\/l10n$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/nextcloud-l10n.js',
				),
			},
			{
				find: /^@nextcloud\/router$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/nextcloud-router.js',
				),
			},
		],
	},
}
