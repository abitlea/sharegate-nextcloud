const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	dashboard: path.join(__dirname, 'src', 'main.js'),
	download: path.join(__dirname, 'src', 'download', 'index.js'),
}

// Nextcloud Util::addScript(APP_ID, 'dashboard') → js/dashboard.js
webpackConfig.output.filename = '[name].js'
webpackConfig.output.chunkFilename = '[name].js'
webpackConfig.output.publicPath = '/apps/sharegate/js/'
// 勿清空整个 js/（应用还有 embed-create、download、admin-settings 等）
webpackConfig.output.clean = false

module.exports = webpackConfig
