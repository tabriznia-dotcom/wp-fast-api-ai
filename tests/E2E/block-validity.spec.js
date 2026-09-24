/**
 * Loads the block markup produced by the PHP serializer (tests/fixtures, written by
 * GutenbergFixturesTest) into the real block editor and asserts every block is valid.
 *
 * Run PHPUnit against the same WordPress version as the E2E site first, so the
 * fixtures match that version's block APIs.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );

const dir = path.resolve( __dirname, '../fixtures' );
const fixtures = fs.existsSync( dir )
	? fs.readdirSync( dir ).filter( ( f ) => f.endsWith( '.html' ) )
	: [];

test.describe( 'Block editor validity of generated markup', () => {
	test.skip(
		! fixtures.length,
		'Run PHPUnit first to generate tests/fixtures/*.html'
	);

	for ( const file of fixtures ) {
		test( `${ file } opens without invalid blocks`, async ( { page } ) => {
			await page.goto( '/wp-admin/' );
			const post = await page.evaluate(
				( { title, content } ) =>
					window.wp.apiFetch( {
						path: '/wp/v2/pages',
						method: 'POST',
						data: { title, content, status: 'draft' },
					} ),
				{
					title: `fixture ${ file }`,
					content: fs.readFileSync( path.join( dir, file ), 'utf8' ),
				}
			);
			await page.goto(
				`/wp-admin/post.php?post=${ post.id }&action=edit`
			);
			await page.waitForFunction(
				() =>
					window.wp.data.select( 'core/block-editor' ).getBlocks()
						.length > 0,
				null,
				{ timeout: 60000 }
			);
			const invalid = await page.evaluate( () => {
				const out = [];
				const walk = ( blocks ) =>
					blocks.forEach( ( b ) => {
						if ( ! b.isValid ) {
							out.push( b.name );
						}
						walk( b.innerBlocks );
					} );
				walk(
					window.wp.data.select( 'core/block-editor' ).getBlocks()
				);
				return out;
			} );
			expect( invalid ).toEqual( [] );
		} );
	}
} );
