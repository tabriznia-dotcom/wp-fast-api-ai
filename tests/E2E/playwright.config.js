/**
 * Playwright configuration for end-to-end, visual (RTL/LTR, responsive) and accessibility tests.
 *
 * Requires a WordPress site with the plugin active (see bin/e2e-setup.sh).
 * Environment:
 *  - WP_BASE_URL (default http://localhost:8889)
 *  - WP_USERNAME / WP_PASSWORD (default admin / password)
 */
const path = require( 'path' );
const { defineConfig, devices } = require( '@playwright/test' );

const ARTIFACTS = path.resolve( __dirname, '../../artifacts' );

module.exports = defineConfig( {
	testDir: '.',
	outputDir: path.join( ARTIFACTS, 'e2e-results' ),
	timeout: 90000,
	expect: { timeout: 15000 },
	fullyParallel: false,
	workers: 1,
	reporter: [ [ 'list' ], [ 'html', { outputFolder: path.join( ARTIFACTS, 'e2e-report' ), open: 'never' } ] ],
	globalSetup: require.resolve( './global-setup.js' ),
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
		storageState: path.join( ARTIFACTS, 'admin-state.json' ),
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		...devices[ 'Desktop Chrome' ],
		launchOptions: process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {},
	},
} );
