const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'chat/index': './src/chat/index.js',
		'search/index': './src/search/index.js',
		'index-panel/index': './src/index-panel/index.js',
	},
};
