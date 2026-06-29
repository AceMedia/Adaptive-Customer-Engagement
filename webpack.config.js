const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve(process.cwd(), 'assets/src/admin/index.js'),
		frontend: path.resolve(process.cwd(), 'assets/src/frontend/tracker.js'),
		monitor: path.resolve(process.cwd(), 'assets/src/monitor/index.js'),
	},
	output: {
		...defaultConfig.output,
		filename: '[name].js',
		path: path.resolve(process.cwd(), 'assets/build'),
	},
};
