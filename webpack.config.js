const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
// Production must not ship a full 'source-map' devtool: it emits a separate
// .js.map exposing original unminified source alongside the publicly-served
// bundle, and — with `optimization.sideEffects = false` retaining the entire
// library module graph — a full source-map balloons the build's memory to an
// OOM. Use the non-source-exposing, lighter variant.
webpackConfig.devtool = isDev ? 'cheap-source-map' : false

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'openconnector'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	// Path-2 leaf bundle: a tiny entry that registers OpenConnector's
	// "Synced from" component on the OpenRegister integration registry.
	// Loaded GLOBALLY on every NC page via \OCP\Util::addInitScript
	// (lib/AppInfo/Application.php) so the component is present when a
	// foreign app (e.g. OpenCatalogi) renders its object detail page —
	// the main SPA bundle is never loaded there. Kept separate so the
	// global script stays small.
	integration: {
		import: path.join(__dirname, 'src', 'integration.js'),
		filename: appId + '-integration.js',
	},
	// ADR-023 admin settings entry — renders the action-authorization matrix
	// via templates/settings/admin.php (NC admin panel).
	settings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	// NC-core Dashboard API widget entries (jobQueueWidget / recentCallsWidget
	// / sourceSyncWidget) removed alongside lib/Dashboard/*Widget.php: the
	// widgets were never registered in appinfo/info.xml or Application.php,
	// so NC never loaded them. The manifest-driven CnDashboardPage replaces
	// them; the time-series widgets are blocked on the OR groupBy primitive
	// (opsx-driven) and will land via the manifest's dashboard page.
}

// Use local source when available (monorepo dev), otherwise fall back to npm package
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = process.env.USE_LOCAL_LIB !== 'false' && fs.existsSync(localLib)

webpackConfig.resolve = {
	extensions: ['.vue', '.js', '.ts'],
	// nc-vue's chunked ESM bundles @nextcloud/dialogs chunks that import
	// Node's `path`; webpack 5 ships no core-module polyfills, so a CLEAN
	// npm ci + build fails with "Can't resolve 'path'" without this
	// fallback. Same fix openbuild carries (openbuild#147) — pre-existing
	// breakage surfaced (not introduced) by connector-catalog-ui's clean
	// install; path-browserify is already a transitive dependency.
	fallback: { path: require.resolve('path-browserify') },
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		//
		// VUE 3 STAGING (ADR-066): route the runtime `vue` import to @vue/compat
		// (MODE 2, set per-SFC via vue-loader compilerOptions below) so the
		// un-migrated Vue-2 template syntax (.sync, $set, filters) stays correct
		// during the straddle. vue-loader still finds the real SFC compiler via
		// @vue/compiler-sfc. One ABSOLUTE file so the app + aliased lib source
		// share ONE Vue copy (dual-copy = two currentRenderingInstance states →
		// CnAppRoot null crash).
		'vue$': path.resolve(__dirname, 'node_modules/@vue/compat/dist/vue.runtime.esm-bundler.js'),
		'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
		// Dedupe vue-router to ONE copy (absolute file): a per-importer resolve
		// gives @nextcloud/vue's RouterLink a different router instance than
		// app.use(router) provided → NcAppNavigationItem's <router-link> crash.
		'vue-router$': path.resolve(__dirname, 'node_modules/vue-router/dist/vue-router.mjs'),
		// v9 is ESM-only: exports maps '.' -> ./dist/index.mjs with no main/module,
		// so a directory alias can't resolve it. Point at the explicit entry file.
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue/dist/index.mjs'),
		// Force @nextcloud/dialogs and @nextcloud/axios to resolve from this
		// app's node_modules, preventing the nextcloud-vue submodule's nested
		// deps from leaking in.
		'@nextcloud/dialogs': path.resolve(__dirname, 'node_modules/@nextcloud/dialogs'),
		'@nextcloud/axios$': path.resolve(__dirname, 'node_modules/@nextcloud/axios'),
	},
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
			options: {
				// @vue/compat MODE 2 (ADR-066 straddle): keep Vue-2 template
				// semantics (.sync, filters, v-on native mod) valid until the
				// per-SFC de-compat sweep lands. Removed once source is pure V3.
				compilerOptions: {
					compatConfig: { MODE: 2 },
				},
			},
		},
		{
			test: /\.ts$/,
			loader: 'ts-loader',
			options: { appendTsSuffixTo: [/\.vue$/], transpileOnly: true },
			exclude: /node_modules/,
		},
		{
			test: /\.css$/,
			use: ['style-loader', 'css-loader'],
		},
		{
			// SCSS used by aliased @conduction/nextcloud-vue components
			test: /\.scss$/,
			use: ['style-loader', 'css-loader', 'sass-loader'],
		},
		{
			// Image and icon assets
			test: /\.(png|jpe?g|gif|svg)$/,
			type: 'asset/resource',
			generator: {
				filename: 'img/[name][ext]',
			},
		},
	],
}

webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
	// Vue 3 (ADR-066): the app has ~70 script-context bare `t(...)` / `n(...)`
	// calls (computeds, methods, data — NOT `this.t`, NOT imported). Under Vue 2
	// these free identifiers resolved to Nextcloud's global `window.t` / `window.n`
	// because babel emitted non-strict code; the strict ESM Vue-3 bundle makes
	// them a `ReferenceError` (crashes e.g. the Catalog card/detail, Rule action
	// forms, Synchronization editors). ProvidePlugin auto-imports @nextcloud/l10n's
	// `translate`/`translatePlural` for every FREE `t`/`n` identifier only —
	// locally-declared `t`/`n`, `this.t`, and compiled template `_ctx.t` are
	// untouched. This is the idiomatic NC fix (the old vue2 mixin's job).
	new webpack.ProvidePlugin({
		t: ['@nextcloud/l10n', 'translate'],
		n: ['@nextcloud/l10n', 'translatePlural'],
	}),
	// Vue 3 build feature flags (ADR-066): silence the "feature flag not
	// explicitly defined" runtime warnings and tree-shake the Options-API /
	// devtools / hydration-mismatch paths. Options API stays ON — the app +
	// nc-vue components are Options-API SFCs.
	new webpack.DefinePlugin({
		__VUE_OPTIONS_API__: JSON.stringify(true),
		__VUE_PROD_DEVTOOLS__: JSON.stringify(false),
		__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.stringify(false),
	}),
]

// Published-dist tree-shaking guard (ADR-066): @conduction/nextcloud-vue@2
// (and @nextcloud/vue@9) attach each dual-Vue component's compiled Vue-3
// render via a side-effect-only `.vue.js` dispatcher import in the barrel
// (`import './CnAppRoot.vue.js'` does `script.render = render`). The published
// package's `sideEffects` allowlist covers only **/*.vue + **/*.css, NOT those
// compiled `.vue.js` dispatchers, so an isolated/CI build that resolves the
// PUBLISHED dist (not the src sibling) tree-shakes the dispatcher away and the
// render never attaches -> every library component (CnAppRoot, CnPageRenderer,
// CnAppNav, ...) renders a silent empty comment and the whole shell is blank.
// Disabling sideEffects pruning keeps those render-attach imports. A targeted
// per-module rule for .vue.js does NOT work; only turning the optimization off
// does (confirmed on the decidesk sister migration). Costs a modest bundle-size
// increase.
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	sideEffects: false,
}

module.exports = webpackConfig
