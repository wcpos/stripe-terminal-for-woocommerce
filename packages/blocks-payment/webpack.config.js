const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'src', 'index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, '../../assets/js/blocks' ),
		filename: '[name].js',
	},
	externals: {
		...defaultConfig.externals,
		'@woocommerce/blocks-registry': [ 'wc', 'wcBlocksRegistry' ],
		'@woocommerce/settings': [ 'wc', 'wcSettings' ],
	},
};
