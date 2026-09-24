/**
 * OpenCode (Zen / Go) provider: settings screen and a generation through the
 * Anthropic Messages format (opencode.ai is faked by the E2E mu-plugin).
 */
const { test, expect } = require( '@playwright/test' );
const { admin, setupPlugin } = require( './helpers' );

test( 'OpenCode provider can be configured and generates a structure', async ( {
	page,
} ) => {
	await setupPlugin( page ); // Accepts the privacy notice.

	await page.goto( admin( 'providers' ) );
	const form = page.locator( 'form' ).filter( {
		has: page.locator( 'input[name="provider"][value="opencode"]' ),
	} );
	await expect( form ).toBeVisible();
	await form.getByLabel( 'Plan' ).selectOption( 'zen' );
	await form
		.getByLabel( 'API key', { exact: true } )
		.fill( 'oc-e2e-SECRET-KEY-987654' );
	await form.getByLabel( 'Model', { exact: true } ).fill( 'claude-sonnet-5' );
	await form.getByLabel( 'Make this the active provider' ).check();
	await form.getByRole( 'button', { name: 'Save provider' } ).click();
	await expect( page.getByText( 'Provider settings saved.' ) ).toBeVisible();
	expect( await page.content() ).not.toContain( 'oc-e2e-SECRET-KEY-987654' );

	const saved = page.locator( 'form' ).filter( {
		has: page.locator( 'input[name="provider"][value="opencode"]' ),
	} );
	await saved.getByRole( 'button', { name: 'Test connection' } ).click();
	await expect( saved.getByText( /Connection successful/ ) ).toBeVisible();

	await page.goto( admin( 'wizard' ) );
	await page.getByRole( 'button', { name: 'Continue' } ).click();
	await page.getByLabel( 'Topic (required)' ).fill( 'Yoga studio' );
	for ( let i = 0; i < 4; i++ ) {
		await page.getByRole( 'button', { name: 'Continue' } ).click();
	}
	await page.getByRole( 'button', { name: 'Propose structure' } ).click();
	await expect( page.getByRole( 'dialog' ) ).toContainText( 'OpenCode' );
	await page.getByRole( 'button', { name: 'Send request' } ).click();
	await expect( page.getByLabel( 'Section name' ).first() ).toBeVisible();

	// Restore the OpenAI-compatible provider for the other specs.
	await setupPlugin( page );
} );
