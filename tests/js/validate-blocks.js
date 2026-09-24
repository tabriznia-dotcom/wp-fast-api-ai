/* eslint-disable no-console -- Command-line reporter. */
/**
 * Parses block markup files with `@wordpress/blocks` and reports invalid blocks.
 *
 * Usage: node tests/js/validate-blocks.js tests/fixtures/*.html
 */
const fs = require( 'fs' );
const blocks = require( './env' );
const files = process.argv.slice( 2 );
let failures = 0;
function walk( list, file, depth = 0 ) {
	for ( const b of list ) {
		if ( ! b.name ) {
			continue;
		}
		if ( ! b.isValid ) {
			failures++;
			console.log( `INVALID ${ file }: ${ b.name }` );
			const issues = ( b.validationIssues || [] )
				.map( ( i ) =>
					i.args
						? i.args
								.map( ( a ) =>
									typeof a === 'string'
										? a.slice( 0, 600 )
										: JSON.stringify( a ).slice( 0, 600 )
								)
								.join( ' | ' )
						: String( i )
				)
				.join( '\n' );
			console.log( issues.slice( 0, 3000 ) );
		}
		walk( b.innerBlocks || [], file, depth + 1 );
	}
}
const origWarn = console.warn;
const origErr = console.error;
console.warn = () => {};
console.error = () => {};
console.info = () => {};
for ( const f of files ) {
	const parsed = blocks.parse( fs.readFileSync( f, 'utf8' ) );
	walk( parsed, f );
}
console.warn = origWarn;
console.error = origErr;
console.log(
	failures
		? `FAILED: ${ failures } invalid blocks`
		: `OK: all blocks valid in ${ files.length } files`
);
process.exit( failures ? 1 : 0 );
