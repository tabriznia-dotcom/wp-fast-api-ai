/**
 * Logs in once and stores the admin session.
 */
const { chromium } = require( '@playwright/test' );
const path = require( 'path' );
const fs = require( 'fs' );

module.exports = async ( config ) => {
	const { baseURL, storageState } = config.projects[ 0 ].use;
	const statePath = storageState;
	fs.mkdirSync( path.dirname( statePath ), { recursive: true } );
	const browser = await chromium.launch();
	const page = await browser.newPage();
	await page.goto( `${ baseURL }/wp-login.php` );
	await page.fill( '#user_login', process.env.WP_USERNAME || 'admin' );
	await page.fill( '#user_pass', process.env.WP_PASSWORD || 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	await page.context().storageState( { path: statePath } );
	await browser.close();
};
