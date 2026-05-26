const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

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
const useLocalLib = fs.existsSync(localLib)

webpackConfig.resolve = {
	extensions: ['.vue', '.js', '.ts'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		'vue$': path.resolve(__dirname, 'node_modules/vue'),
		'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
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
]

module.exports = webpackConfig
