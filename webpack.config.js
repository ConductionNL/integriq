const path = require('path')
const fs = require('fs')
const webpackConfig = require('@nextcloud/webpack-vue-config')

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
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	jobQueueWidget: {
		import: path.join(__dirname, 'src', 'jobQueueWidget.js'),
		filename: appId + '-jobQueueWidget.js',
	},
	recentCallsWidget: {
		import: path.join(__dirname, 'src', 'recentCallsWidget.js'),
		filename: appId + '-recentCallsWidget.js',
	},
	sourceSyncWidget: {
		import: path.join(__dirname, 'src', 'sourceSyncWidget.js'),
		filename: appId + '-sourceSyncWidget.js',
	},
}

// Use local source when available (monorepo dev), otherwise fall back to npm package
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib)

webpackConfig.resolve = webpackConfig.resolve || {}
webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
	...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
	'vue$': path.resolve(__dirname, 'node_modules/vue'),
	'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
	'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
	'@nextcloud/dialogs': path.resolve(__dirname, 'node_modules/@nextcloud/dialogs'),
}

// Drop the base config's ts-loader rule (its module-ID scheme conflicts with
// the base's babel-loader and breaks `chunks: 'all'` splitChunks — ADR-004
// 'Build / bundling — TypeScript apps'). Replace with a babel-loader rule
// using @babel/preset-typescript via .babelrc, so .ts files go through the
// SAME babel-loader as the .js files. Type-checking moves to `npx tsc --noEmit`.
webpackConfig.module.rules = webpackConfig.module.rules.filter(rule =>
	!(rule && rule.use && (
		(typeof rule.use === 'string' && rule.use === 'ts-loader')
		|| (Array.isArray(rule.use) && rule.use.some(u => (u?.loader || u) === 'ts-loader'))
		|| (typeof rule.use === 'object' && rule.use.loader === 'ts-loader')
	))
	&& !(rule && rule.loader === 'ts-loader')
)
webpackConfig.module.rules.push({
	test: /\.ts$/,
	exclude: /node_modules/,
	use: { loader: 'babel-loader' },
})

// Share Vue + @nextcloud/vue + pinia + icons + @conduction/nextcloud-vue
// across every entry-point so each widget bundle no longer inlines its own
// framework copy. Stable filenames mean each widget's `Util::addScript` PHP
// call can reference the chunk directly without a manifest. See ADR-004
// (Build / bundling) for the org-wide pattern.
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	// Single shared runtime — required for cross-chunk module resolution to
	// survive splitChunks on apps with broader module graphs (TypeScript +
	// many entries). See ADR-004.
	runtimeChunk: { name: 'runtime' },
	splitChunks: {
		...(webpackConfig.optimization?.splitChunks || {}),
		chunks: 'all',
		cacheGroups: {
			default: false,
			defaultVendors: false,
			ncVue: {
				name: appId + '-shared-nc-vue',
				test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
				priority: 30,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-nc-vue.js',
			},
			vendor: {
				name: appId + '-shared-vendor',
				test: /[\\/]node_modules[\\/](vue|pinia|vue-material-design-icons|@vueuse|core-js)[\\/]/,
				priority: 20,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-vendor.js',
			},
		},
	},
}

module.exports = webpackConfig
