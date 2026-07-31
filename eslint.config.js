const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([{
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
	// and must lint cleanly — explicitly un-ignored here so the global
	// `src/modals/**` glob doesn't swallow it.
	ignores: ['src/modals/**', '!src/modals/v2/**'],
}, {
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

		// Vue 3 migration guard (ADR-066). The `@nextcloud` shared config
		// extends eslint-plugin-vue's *Vue 2* preset, so none of the
		// `vue/no-deprecated-*` rules were active — this app shipped four
		// `beforeDestroy()` hooks that Vue 3 does not recognise and silently
		// never calls, leaking a 1Hz setInterval and a live-object subscription
		// per detail page, with no console error to show for it. Enabling the
		// whole vue3-recommended preset here would bury the signal under
		// hundreds of stylistic errors, so we turn on precisely the family that
		// catches Vue-2-isms the runtime accepts in silence.
		'vue/no-deprecated-destroyed-lifecycle': 'error',
		'vue/no-deprecated-dollar-listeners-api': 'error',
		'vue/no-deprecated-dollar-scopedslots-api': 'error',
		'vue/no-deprecated-events-api': 'error',
		'vue/no-deprecated-filter': 'error',
		'vue/no-deprecated-functional-template': 'error',
		'vue/no-deprecated-data-object-declaration': 'error',
		'vue/no-deprecated-html-element-is': 'error',
		'vue/no-deprecated-inline-template': 'error',
		// A prop `default()` factory has no `this` in Vue 3 — reading one
		// white-screens the page at first render.
		'vue/no-deprecated-props-default-this': 'error',
		'vue/no-deprecated-router-link-tag-prop': 'error',
		'vue/no-deprecated-scope-attribute': 'error',
		'vue/no-deprecated-slot-attribute': 'error',
		'vue/no-deprecated-slot-scope-attribute': 'error',
		// The `.sync` modifier is gone; v-model arguments replace it.
		'vue/no-deprecated-v-bind-sync': 'error',
		'vue/no-deprecated-v-on-native-modifier': 'error',
		'vue/no-deprecated-v-on-number-modifiers': 'error',
		'vue/no-deprecated-vue-config-keycodes': 'error',

		// The five rules below are the delta between this hand-rolled block
		// and `conductionVue3Fixes` from `@conduction/nextcloud-vue/eslint`.
		// That preset is the shared home for this rule family, but it is not
		// installable yet: the `eslint/` directory is absent from the
		// published package's `files` allowlist, so it ships in no npm
		// version up to and including 2.1.0-vue3.9 (the current `vue3`
		// dist-tag). Adding the delta here keeps this app preset-clean in
		// advance, so adopting the shared preset later is a no-op diff
		// rather than a fresh round of findings. Remove this block and
		// spread `conductionVue3Fixes` once a version that contains it is
		// published.
		'vue/no-deprecated-delete-set': 'error',
		// Catches the Vue-2 `model: { prop, event }` component option, which
		// Vue 3 ignores outright.
		'vue/no-deprecated-model-definition': 'error',
		'vue/no-deprecated-v-is': 'error',
		'vue/no-restricted-component-options': ['error', {
			name: 'filters',
			message: 'The `filters` component option was removed in Vue 3. Replace filters with a computed property or a method.',
		}],
		// Vue 3 resolves a kebab-case listener for `update:` (model) events
		// via its hyphenate fallback, so this is safe for those. It is NOT
		// safe to `--fix` blind: a non-`update:` camelCase event hyphenated
		// this way silently receives nothing at all.
		'vue/v-on-event-hyphenation': ['error', 'always', { ignore: ['update:modelValue'] }],
	},
}])
