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
	extends: compat.extends('@nextcloud'),

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
	},
}])
