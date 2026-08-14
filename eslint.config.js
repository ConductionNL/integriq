const { defineConfig } = require('@eslint/config-helpers')

const js = require('@eslint/js')

const { FlatCompat } = require('@eslint/eslintrc')

const { conductionVue3Fixes } = require('@conduction/nextcloud-vue/eslint')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([
	{
		// src/modals/ holds 16 pre-chain-C modal SFCs preserved as
		// extraction reference for the upcoming bespoke-modal PRs
		// (EditMapping / EditSynchronization / EditRule + action surfaces).
		// They have intentionally broken imports against deleted stores and
		// entity classes — lint would spam errors that wouldn't get fixed.
		// Once a modal is extracted into a fresh bespoke component wired to
		// nc-vue + useObjectStore, the legacy file is removed in the same
		// PR. See src/modals/README.md.
		//
		// `src/modals/v2/` is the post-extraction home for chain-C+ modals
		// (Test mapping #835, Add endpoint rule #836, Job form fields #847)
		// and must lint cleanly — kept out of the ignore set below.
		//
		// The globs name the legacy subdirectories one by one instead of the
		// shorter `['src/modals/**', '!src/modals/v2/**']`. Any ignore glob
		// that matches the `src/modals/v2` directory entry — `src/modals/**`
		// and `src/modals/*` both do — prunes the whole directory, and ESLint
		// cannot re-include a descendant of a pruned directory. The negation
		// therefore never fired and all 14 files in v2 shipped unlinted.
		// Adding a legacy modal directory means adding it here.
		ignores: [
			'src/modals/*.vue',
			'src/modals/Endpoint/**',
			'src/modals/EventDelivery/**',
			'src/modals/EventSubscription/**',
			'src/modals/NotificatiesAbonnement/**',
			'src/modals/Rule/**',
			'src/modals/Subscription/**',
			'src/modals/Synchronization/**',
		],
	},
	{
		ignores: ['scripts/'],
	},
	{
		extends: compat.extends('@nextcloud'),

		// The `@nextcloud` shared config, pulled in through FlatCompat, resolves to
		// `ecmaVersion: 6` (ES2015). The main lint pass doesn't notice because
		// vue-eslint-parser is driven with its own options, but `eslint-plugin-import`
		// re-parses every *imported* module using the ecmaVersion from here — so it
		// choked on optional chaining (`?.`, ES2020), nullish coalescing (`??`,
		// ES2020) and object spread (`...`, ES2018), and reported each failure as a
		// bogus `import/no-named-as-default` "Parse errors in imported module"
		// warning. That was 20 of them, against files whose only sin was modern
		// syntax. Pinning ecmaVersion to latest fixes the cause rather than muting
		// the rule.
		languageOptions: {
			ecmaVersion: 'latest',
			sourceType: 'module',
		},

		settings: {
			'import/resolver': {
				alias: {
					map: [
						['@', './src'],
						['@conduction/nextcloud-vue', '../nextcloud-vue/src'],
					],
					extensions: ['.js', '.ts', '.vue', '.json'],
				},
			},
			// import/parsers maps each file extension to its parser. Openconnector
			// has TS files in src/store/modules/search.ts and a few helpers — the
			// other fleet apps are JS-only. Keep this here, not on decidesk.
			'import/parsers': {
				espree: ['.js', '.mjs', '.cjs', '.jsx'],
				'@typescript-eslint/parser': ['.ts', '.tsx', '.mts', '.cts'],
				'vue-eslint-parser': ['.vue'],
			},
		},

		rules: {
			'jsdoc/require-jsdoc': 'off',
			'vue/first-attribute-linebreak': 'off',
			'@typescript-eslint/no-explicit-any': 'off',
			'n/no-missing-import': 'off',
			'vue/enforce-style-attribute': ['error', { allow: ['scoped'] }],
			// `import/namespace` + `import/named` + `import/default` walk every
			// named export of imported modules using node module resolution.
			// `@nextcloud/vue@8` ships an `exports` map that doesn't expose
			// `./dist/Directives/Tooltip.js`, so these rules crash the lint run
			// — `no-undef` already catches actually-missing imports.
			'import/namespace': 'off',
			'import/named': 'off',
			'import/default': 'off',
			'import/no-named-as-default-member': 'off',
			// `@spec` is the Conduction OpenSpec cross-reference tag, used to link
			// every JS function back to its specification task. It is not a standard
			// JSDoc tag, so we explicitly allowlist it here.
			'jsdoc/check-tag-names': ['warn', { definedTags: ['spec'] }],
		},

		// Vue 3 migration guard (ADR-066), now sourced from nc-vue rather than
		// hand-maintained here. The `@nextcloud` shared config extends
		// eslint-plugin-vue's *Vue 2* preset, so none of the `vue/no-deprecated-*`
		// rules are active by default — that is how this app shipped four
		// `beforeDestroy()` hooks Vue 3 silently never calls, leaking a 1Hz
		// setInterval and a live-object subscription per detail page with no console
		// error to show for it.
		//
		// `conductionVue3Fixes` is a pure fix layer: all three of its blocks declare
		// zero plugins, so it composes onto the `@nextcloud` base above without a
		// plugin-redefined error. It MUST spread last so its rules win.
		//
		// Import path: this file is CommonJS, so the extensionless subpath resolves.
		// The package ships no `exports` map, so an ESM `eslint.config.mjs` would
		// need the explicit `@conduction/nextcloud-vue/eslint/index.js` instead.
	},
	...conductionVue3Fixes,

	// `eslint-config-prettier` LAST OF ALL, and it has to be: it only turns rules
	// OFF — every stylistic rule prettier now owns (indent, quotes,
	// operator-linebreak, comma-dangle…). Anything spread after it would switch
	// some of them back on, and eslint and prettier would then demand opposite
	// things — the unfixable state this fleet already hit once with php-cs-fixer
	// and PHPCS.
	//
	// It disables no CORRECTNESS rule: the `vue/no-deprecated-*` family spread just
	// above — the layer that caught this app's four leaking `beforeDestroy()` hooks
	// — is still present and still ON, because prettier has no opinion about it.
	// `indent` is now off HERE and enforced by prettier's `useTabs: true` instead:
	// the same tab, from the tool that also covers CSS and SCSS, which
	// @nextcloud/stylelint-config no longer does.
	require('eslint-config-prettier'),
])
