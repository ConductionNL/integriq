const path = require('path')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
// Production must not ship a full 'source-map' devtool: it emits a separate
// .js.map exposing original unminified source alongside the publicly-served
// bundle. Use the non-source-exposing variant.
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

// NOTE: the ../nextcloud-vue source alias (USE_LOCAL_LIB) is gone. It aliased
// this app's build to whatever the sibling checkout happened to be on, and the
// library maintains two Vue lines — so a checkout parked on the Vue 2 branch
// silently compiled Vue 2 library source into this Vue 3 app and produced ~75
// errors that named nothing useful. CI never saw it (no sibling checkout), so
// it read as 'the build is broken' only to whoever ran it locally.
//
// Releases are fast enough now that developing against a published version is
// the shorter path: publish a prerelease from nextcloud-vue and bump here.
// One source of truth for what this app builds against - the lockfile.

webpackConfig.resolve = {
	extensions: ['.vue', '.js', '.ts'],
	// Keep a linked @conduction/nextcloud-vue (`npm i ../nextcloud-vue`, which
	// npm installs as a symlink) resolving its dependencies out of THIS app's
	// node_modules. Webpack resolves symlinks to their real path by default, so
	// the library's transitive requires would instead walk up from the sibling
	// checkout — where its devDependency tree is incomplete for a browser build
	// (`Can't resolve 'buffer'` from safe-buffer, reached via @nextcloud/files).
	// It also guarantees the linked library shares this app's single Vue/Pinia
	// copy, the same hazard the aliases below exist to prevent. No-op when the
	// dependency is a normal registry install rather than a link.
	symlinks: false,
	// nc-vue's chunked ESM bundles @nextcloud/dialogs chunks that import
	// Node's `path`; webpack 5 ships no core-module polyfills, so a CLEAN
	// npm ci + build fails with "Can't resolve 'path'" without this
	// fallback. Same fix openbuild carries (openbuild#147) — pre-existing
	// breakage surfaced (not introduced) by connector-catalog-ui's clean
	// install; path-browserify is already a transitive dependency.
	fallback: { path: require.resolve('path-browserify') },
	alias: {
		'@': path.resolve(__dirname, 'src'),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		//
		// PURE VUE 3 (ADR-066): the app source is now compat-construct-free
		// (.sync → v-model:arg, $set/$delete → assignment, no filters/$on), so the
		// build runs on the REAL Vue 3 runtime — NOT @vue/compat. @vue/compat
		// globally wraps every library component's compiled `render` as a Vue-2
		// RENDER_FUNCTION, which breaks the pure-Vue-3 @conduction/nextcloud-vue@2
		// + @nextcloud/vue@9 components at runtime (`this.$slots.default is not a
		// function`, `Cannot destructure 'href'`). One ABSOLUTE file so the app +
		// any aliased lib source share ONE Vue copy (dual-copy = two
		// currentRenderingInstance states → CnAppRoot null crash).
		vue$: path.resolve(
			__dirname,
			'node_modules/vue/dist/vue.runtime.esm-bundler.js',
		),
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
		// Dedupe vue-router to ONE copy (absolute file): a per-importer resolve
		// gives @nextcloud/vue's RouterLink a different router instance than
		// app.use(router) provided → NcAppNavigationItem's <router-link> crash.
		'vue-router$': path.resolve(
			__dirname,
			'node_modules/vue-router/dist/vue-router.mjs',
		),
		// v9 is ESM-only: exports maps '.' -> ./dist/index.mjs with no main/module,
		// so a directory alias can't resolve it. Point at the explicit entry file.
		'@nextcloud/vue$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/vue/dist/index.mjs',
		),
		// Force @nextcloud/dialogs and @nextcloud/axios to resolve from this
		// app's node_modules, preventing the nextcloud-vue submodule's nested
		// deps from leaking in.
		//
		// v7 is ESM-only and declares an exports map with NO main/module, so a
		// DIRECTORY alias cannot resolve it — the same trap as @nextcloud/vue@9
		// above, and the reason v6 (a vue@2.7 package) had been kept: it still
		// had a main, so the directory alias worked and nobody noticed a Vue 2
		// package was being bundled into a Vue 3 app. Point at the explicit
		// entry file, and alias the one subpath the library imports.
		'@nextcloud/dialogs$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/dialogs/dist/index.mjs',
		),
		'@nextcloud/dialogs/style.css$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/dialogs/dist/style.css',
		),
		'@nextcloud/axios$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/axios',
		),
	},
}

webpackConfig.module = {
	rules: [
		{
			// PURE VUE 3 (ADR-066): the @vue/compat MODE-2 compiler shim is gone
			// — the source is compat-construct-free, so vue-loader compiles the
			// SFC templates as native Vue 3.
			test: /\.vue$/,
			loader: 'vue-loader',
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
	new webpack.DefinePlugin({
		appVersion: JSON.stringify(process.env.npm_package_version),
	}),
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

// NOTE (ADR-066): this file used to carry `optimization.sideEffects = false`.
// @conduction/nextcloud-vue@2 attached each dual-Vue component's compiled Vue-3
// render through a side-effect-only `.vue.js` dispatcher import, which the
// published package's `sideEffects` allowlist did not cover — so a build that
// resolved the published dist tree-shook the dispatcher away and every library
// component rendered as a silent empty comment. Since 2.1.0-vue3.x the library
// anchors its SFC default exports module-locally, so neither rollup nor webpack
// can resolve past the render wiring and the workaround is obsolete. Turning
// tree-shaking off wholesale only cost bundle size; it is not reinstated.

module.exports = webpackConfig
