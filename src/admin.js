/**
 * Enhancements for server-rendered settings screens:
 * - "Test connection" for AI providers (no secrets are ever sent to the browser),
 * - unsaved changes warning on settings forms.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

document.querySelectorAll( '.aipd-test-connection' ).forEach( ( button ) => {
	button.addEventListener( 'click', async () => {
		const form = button.closest( 'form' );
		const output = form.querySelector( '.aipd-test-result' );
		const provider = button.dataset.provider;
		button.disabled = true;
		output.textContent = __( 'Testing…', 'ai-page-designer' );
		output.className = 'aipd-test-result';
		try {
			const res = await apiFetch( {
				path: `/aipd/v1/providers/${ encodeURIComponent( provider ) }/test`,
				method: 'POST',
			} );
			const models = Array.isArray( res.models ) ? res.models : [];
			output.textContent = models.length
				? sprintf(
						/* translators: %d: number of models. */
						__(
							'Connection successful. %d models available.',
							'ai-page-designer'
						),
						models.length
					)
				: __( 'Connection successful.', 'ai-page-designer' );
			output.classList.add( 'is-success' );
			const list = form.querySelector( 'datalist' );
			if ( list ) {
				list.replaceChildren(
					...models.map( ( id ) => {
						const option = document.createElement( 'option' );
						option.value = id;
						return option;
					} )
				);
			}
		} catch ( error ) {
			output.textContent =
				( error && error.message ) ||
				__( 'The connection test failed.', 'ai-page-designer' );
			output.classList.add( 'is-error' );
		} finally {
			button.disabled = false;
		}
	} );
} );

document
	.querySelectorAll( 'form[data-aipd-dirty-check]' )
	.forEach( ( form ) => {
		let dirty = false;
		form.addEventListener( 'input', () => {
			dirty = true;
		} );
		form.addEventListener( 'change', () => {
			dirty = true;
		} );
		form.addEventListener( 'submit', () => {
			dirty = false;
		} );
		window.addEventListener( 'beforeunload', ( event ) => {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	} );
