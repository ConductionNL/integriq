const path = require('path')
const fs = require('fs')
const { VueLoaderPlugin } = require('vue-loader')
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
	// Force a single copy of @nextcloud/files (v3.12.2). Without this, webpack
	// can resolve to v4 hoisted into apps-extra/nextcloud-vue/node_modules,
	// which dropped the `dav*` re-exports that @nextcloud/dialogs@6 still imports.
	'@nextcloud/files$': path.resolve(__dirname, 'node_modules/@nextcloud/files'),
	// @nextcloud/axios@2.6 ships ESM only ("import" condition); the CJS chunks
	// in @nextcloud/vue try to require() it. Alias to the actual file to
	// bypass the exports-field condition check.
	'@nextcloud/axios$': path.resolve(__dirname, 'node_modules/@nextcloud/axios/dist/index.js'),
	'@floating-ui/dom$': path.resolve(__dirname, 'src/shims/floating-ui-dom.js'),
	'@floating-ui/dom-actual': path.resolve(__dirname, 'node_modules/@floating-ui/dom'),
}

webpackConfig.resolve.extensionAlias = {
	'.js': ['.cjs', '.js'],
	...webpackConfig.resolve.extensionAlias,
}

webpackConfig.resolve.modules = [
	path.resolve(__dirname, 'node_modules'),
	...(webpackConfig.resolve.modules || ['node_modules']),
]

// Replace VueLoaderPlugin (don't push — duplicates break templates when using local package)
const otherPlugins = (webpackConfig.plugins || []).filter((p) => p.constructor.name !== 'VueLoaderPlugin')
webpackConfig.plugins = [new VueLoaderPlugin(), ...otherPlugins]

module.exports = webpackConfig
