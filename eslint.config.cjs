/**
 * ESLint config: the @wordpress/scripts defaults, plus the WordPress packages that
 * are loaded from WordPress at runtime (externals) instead of from node_modules.
 */
const defaults = require( '@wordpress/scripts/config/eslint.config.cjs' );

const runtimePackages = [
	'@wordpress/api-fetch',
	'@wordpress/block-editor',
	'@wordpress/blocks',
	'@wordpress/components',
	'@wordpress/compose',
	'@wordpress/element',
	'@wordpress/i18n',
	'@wordpress/server-side-render',
];

module.exports = [
	...defaults,
	{
		settings: {
			'import/core-modules': runtimePackages,
		},
	},
];
