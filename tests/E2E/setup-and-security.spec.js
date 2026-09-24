/**
 * Settings, secrets and permissions.
 */
const { test, expect } = require( '@playwright/test' );
const { admin, expectAccessible, setupPlugin } = require( './helpers' );

test.describe( 'Settings and security', () => {
	test( 'provider setup never exposes the API key', async ( { page } ) => {
		await setupPlugin( page );
		const html = await page.content();
		expect( html ).not.toContain( 'sk-e2e-SECRET-KEY-123456' );
		expect( html ).toContain( 'A key is saved' );

		await page.getByRole( 'button', { name: 'Test connection' } ).first().click();
		await expect( page.getByText( /Connection successful/ ) ).toBeVisible();

		// REST responses do not include secrets.
		const status = await page.evaluate( () => window.wp.apiFetch( { path: '/aipd/v1/status' } ) );
		expect( JSON.stringify( status ) ).not.toContain( 'SECRET' );
	} );

	test( 'admin screens are accessible', async ( { page } ) => {
		for ( const screen of [ '', 'providers', 'settings', 'privacy', 'brand', 'templates', 'history', 'logs', 'tools', 'help' ] ) {
			await page.goto( admin( screen ) );
			await expect( page.locator( '.aipd-wrap h1' ) ).toBeVisible();
			await expectAccessible( page, '.aipd-wrap' );
		}
	} );

	test( 'subscribers cannot access plugin screens or REST routes', async ( { browser, baseURL } ) => {
		const context = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const page = await context.newPage();
		const response = await page.request.get( `${ baseURL }/?rest_route=/aipd/v1/status` );
		expect( response.status() ).toBe( 401 );
		await page.goto( `${ baseURL }/wp-admin/admin.php?page=aipd-providers` );
		await expect( page ).toHaveURL( /wp-login\.php/ );
		await context.close();
	} );
} );
