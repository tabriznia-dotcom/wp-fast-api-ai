/**
 * Shared E2E helpers.
 */
const { expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const path = require( 'path' );

const artifact = ( name ) => path.resolve( __dirname, '../../artifacts', name );

const admin = ( page = '' ) => `/wp-admin/admin.php?page=${ page ? `aipd-${ page }` : 'aipd' }`;

/**
 * Runs axe and fails on serious or critical WCAG A/AA violations.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {string}                          include Optional selector to scope the scan.
 */
async function expectAccessible( page, include ) {
	let builder = new AxeBuilder( { page } ).withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] );
	if ( include ) {
		builder = builder.include( include );
	}
	const results = await builder.analyze();
	const serious = results.violations.filter( ( v ) => [ 'serious', 'critical' ].includes( v.impact ) );
	expect( serious.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target.join( ' ' ) ).slice( 0, 3 ).join( ', ' ) }` ) ).toEqual( [] );
}

/**
 * Asserts there is no horizontal page scroll.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function expectNoHorizontalOverflow( page ) {
	const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
	expect( overflow ).toBeLessThanOrEqual( 1 );
}

/**
 * Configures the fake provider and accepts the privacy notice through the UI.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function setupPlugin( page ) {
	await page.goto( admin( 'privacy' ) );
	const consent = page.getByLabel( /I understand that briefs are sent/ );
	if ( ! ( await consent.isChecked() ) ) {
		await consent.check();
	}
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await expect( page.getByText( 'Privacy settings saved.' ) ).toBeVisible();

	await page.goto( admin( 'providers' ) );
	const form = page.locator( 'form' ).filter( { has: page.locator( 'input[name="provider"][value="openai_compatible"]' ) } );
	await form.getByLabel( 'API base URL' ).fill( 'https://api.example.com/v1' );
	await form.getByLabel( 'API key', { exact: true } ).fill( 'sk-e2e-SECRET-KEY-123456' );
	await form.getByLabel( 'Model' ).fill( 'e2e-model' );
	await form.getByLabel( 'Make this the active provider' ).check();
	await form.getByRole( 'button', { name: 'Save provider' } ).click();
	await expect( page.getByText( 'Provider settings saved.' ) ).toBeVisible();
}

module.exports = { artifact, admin, expectAccessible, expectNoHorizontalOverflow, setupPlugin };
