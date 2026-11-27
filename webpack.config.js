/**
 * Build configuration for the block checkout scripts (src/blocks -> assets/js/build).
 *
 * Uses the WordPress build tool (@wordpress/scripts) plus WooCommerce's dependency-extraction plugin, so the
 * "@woocommerce/..." imports resolve to the scripts WooCommerce already loads (wc-blocks-registry, wc-settings).
 */
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const WooCommerceDependencyExtractionWebpackPlugin = require('@woocommerce/dependency-extraction-webpack-plugin');

module.exports = {
	...defaultConfig,
	plugins: [
		...defaultConfig.plugins.filter(
			(plugin) => 'DependencyExtractionWebpackPlugin' !== plugin.constructor.name
		),
		new WooCommerceDependencyExtractionWebpackPlugin(),
	],
};
