/**
 * Full wizard flows, editor validity, RTL/LTR visual and responsive checks.
 */
const { test, expect } = require( '@playwright/test' );
const { artifact, admin, expectAccessible, expectNoHorizontalOverflow, setupPlugin } = require( './helpers' );

const VIEWPORTS = [
	{ name: 'mobile', width: 390, height: 844 },
	{ name: 'tablet', width: 820, height: 1180 },
	{ name: 'desktop', width: 1280, height: 900 },
];

/**
 * Asserts that the block editor loaded every block as valid.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {string}                          editUrl Edit URL.
 */
async function expectValidBlocks( page, editUrl ) {
	await page.goto( editUrl );
	await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/block-editor' ).getBlocks().length > 0, null, { timeout: 60000 } );
	const result = await page.evaluate( () => {
		const all = [];
		const walk = ( blocks ) =>
			blocks.forEach( ( b ) => {
				all.push( { name: b.name, isValid: b.isValid } );
				walk( b.innerBlocks );
			} );
		walk( window.wp.data.select( 'core/block-editor' ).getBlocks() );
		return all;
	} );
	expect( result.length ).toBeGreaterThan( 20 );
	expect( result.filter( ( b ) => ! b.isValid ) ).toEqual( [] );
	await expect( page.locator( '.block-editor-warning' ) ).toHaveCount( 0 );
}

/**
 * Runs the AI wizard to a Gutenberg draft.
 *
 * @param {import('@playwright/test').Page} page     Page.
 * @param {string}                          language Language option label.
 * @return {Promise<string>} Edit URL.
 */
async function runWizard( page, language ) {
	await page.goto( admin( 'wizard' ) );
	await page.getByText( 'Landing page', { exact: true } ).click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();
	// Focus moves to the new step heading for screen reader and keyboard users.
	await expect( page.getByRole( 'heading', { name: 'Business and audience', level: 2 } ) ).toBeFocused();

	await page.getByLabel( 'Topic (required)' ).fill( 'Online cooking classes' );
	await page.getByLabel( 'Main call to action' ).fill( 'Book a class' );
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await page.getByLabel( 'Content language' ).selectOption( { label: language } );
	await page.getByRole( 'button', { name: 'Continue' } ).click();
	await page.getByRole( 'button', { name: 'Continue' } ).click(); // Style.
	await page.getByText( 'Block editor (Gutenberg)' ).click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	// Structure: cost confirmation is required before each paid request.
	await page.getByRole( 'button', { name: 'Propose structure' } ).click();
	await expect( page.getByRole( 'dialog', { name: 'Send this request?' } ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Send request' } ).click();
	await expect( page.getByLabel( 'Section name' ).first() ).toBeVisible();
	await page.getByRole( 'button', { name: /Remove FAQ|Remove پرسش‌ها/ } ).click();
	await page.getByRole( 'button', { name: 'Approve and write content' } ).click();
	await page.getByRole( 'button', { name: 'Send request' } ).click();

	// Preview.
	const frame = page.frameLocator( 'iframe[title="Page preview"]' );
	await expect( frame.locator( 'h1' ) ).toBeVisible();
	for ( const device of [ 'Mobile', 'Tablet', 'Desktop' ] ) {
		await page.getByRole( 'button', { name: device, exact: true } ).click();
	}

	// Regenerate one section.
	await page.getByRole( 'button', { name: 'Regenerate' } ).first().click();
	await page.getByLabel( 'What should change?' ).fill( 'Make it shorter' );
	await page.getByRole( 'button', { name: 'Rewrite section' } ).click();
	await page.getByRole( 'button', { name: 'Send request' } ).click();
	await expect( page.locator( '.components-notice' ).getByText( 'Section updated in the preview.' ) ).toBeVisible();

	await page.getByRole( 'button', { name: 'Looks good, continue' } ).click();
	await page.getByRole( 'button', { name: 'Create draft' } ).click();
	await expect( page.locator( '.components-notice' ).getByText( /Your draft is ready\./ ) ).toBeVisible();
	return page.getByRole( 'link', { name: 'Open in editor' } ).getAttribute( 'href' );
}

test.describe( 'Page wizard', () => {
	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await setupPlugin( page );
		await page.close();
	} );

	test( 'English LTR landing page: wizard, editor and frontend', async ( { page } ) => {
		const editUrl = await runWizard( page, 'English' );
		await expectValidBlocks( page, editUrl );

		const postId = new URL( editUrl, 'http://x' ).searchParams.get( 'post' );
		for ( const vp of VIEWPORTS ) {
			await page.setViewportSize( vp );
			await page.goto( `/?page_id=${ postId }&preview=true` );
			await expect( page.locator( '.aipd-page h1' ) ).toHaveCount( 1 );
			expect( await page.locator( '.aipd-page' ).evaluate( ( el ) => getComputedStyle( el ).direction ) ).toBe( 'ltr' );
			await expectNoHorizontalOverflow( page );
			await page.screenshot( { path: artifact( `screenshots/landing-en-${ vp.name }.png` ), fullPage: true } );
		}
		await expectAccessible( page, '.aipd-page' );
	} );

	test( 'Persian RTL landing page: wizard, editor and frontend', async ( { page } ) => {
		const editUrl = await runWizard( page, 'Persian (فارسی)' );
		await expectValidBlocks( page, editUrl );

		const postId = new URL( editUrl, 'http://x' ).searchParams.get( 'post' );
		for ( const vp of VIEWPORTS ) {
			await page.setViewportSize( vp );
			await page.goto( `/?page_id=${ postId }&preview=true` );
			const pageEl = page.locator( '.aipd-page' );
			expect( await pageEl.evaluate( ( el ) => getComputedStyle( el ).direction ) ).toBe( 'rtl' );
			// The hero heading is right-aligned or centered, never left-aligned in RTL.
			const align = await page.locator( '.aipd-page h1' ).evaluate( ( el ) => getComputedStyle( el ).textAlign );
			expect( [ 'center', 'right', 'start' ] ).toContain( align );
			await expectNoHorizontalOverflow( page );
			await page.screenshot( { path: artifact( `screenshots/landing-fa-${ vp.name }.png` ), fullPage: true } );
		}
		await expectAccessible( page, '.aipd-page' );
	} );

	test( 'template path works without any AI request and creates a classic draft', async ( { page } ) => {
		await page.goto( admin( 'wizard' ) );
		await page.getByLabel( 'Start from a template (no AI request)' ).check();
		await page.getByRole( 'button', { name: 'Use this template' } ).nth( 1 ).click();
		const frame = page.frameLocator( 'iframe[title="Page preview"]' );
		await expect( frame.locator( 'html' ) ).toHaveAttribute( 'dir', 'rtl' );
		await expectAccessible( page, '.aipd-wizard' );
		await page.getByRole( 'button', { name: 'Looks good, continue' } ).click();
		await page.getByLabel( 'Page builder' ).selectOption( 'classic' );
		await page.getByRole( 'button', { name: 'Create draft' } ).click();
		await expect( page.locator( '.components-notice' ).getByText( /Your draft is ready\./ ) ).toBeVisible();
	} );

	test( 'wizard warns before leaving with unsaved changes', async ( { page } ) => {
		await page.goto( admin( 'wizard' ) );
		await page.getByRole( 'button', { name: 'Continue' } ).click();
		await page.getByLabel( 'Topic (required)' ).fill( 'Unsaved' );
		let dialogShown = false;
		page.on( 'dialog', async ( dialog ) => {
			dialogShown = 'beforeunload' === dialog.type();
			await dialog.dismiss();
		} );
		await page.goto( admin(), { waitUntil: 'commit' } ).catch( () => {} );
		expect( dialogShown ).toBe( true );
	} );

	test( 'wizard is keyboard operable and works in an RTL admin', async ( { page, context, baseURL } ) => {
		await context.addCookies( [ { name: 'aipd_e2e_rtl', value: '1', url: baseURL } ] );
		await page.goto( admin( 'wizard' ) );
		await expect( page.locator( '.aipd-wizard' ) ).toHaveAttribute( 'dir', 'rtl' );
		// Native radios: focus the group and use arrow keys.
		await page.locator( '#aipd-type-landing' ).focus();
		await page.keyboard.press( 'ArrowDown' );
		await expect( page.locator( 'input[value="services"]' ) ).toBeChecked();
		await page.setViewportSize( { width: 390, height: 844 } );
		await expectNoHorizontalOverflow( page );
		await page.screenshot( { path: artifact( 'screenshots/wizard-rtl-admin-mobile.png' ), fullPage: true } );
		await context.clearCookies();
	} );
} );

test( 'generated page has exactly one H1 and text icons', async ( { page } ) => {
	await page.goto( admin( 'wizard' ) );
	await page.getByLabel( 'Start from a template (no AI request)' ).check();
	await page.getByRole( 'button', { name: 'Use this template' } ).first().click();
	await page.getByRole( 'button', { name: 'Looks good, continue' } ).click();
	await page.getByLabel( 'Page builder' ).selectOption( 'gutenberg' );
	await page.getByRole( 'button', { name: 'Create draft' } ).click();
	const editUrl = await page.getByRole( 'link', { name: 'Open in editor' } ).getAttribute( 'href' );
	const postId = new URL( editUrl, 'http://x' ).searchParams.get( 'post' );
	await page.goto( `/?page_id=${ postId }&preview=true` );
	await expect( page.locator( 'h1' ) ).toHaveCount( 1 );
	await expect( page.locator( '.aipd-icon img' ) ).toHaveCount( 0 );
} );
