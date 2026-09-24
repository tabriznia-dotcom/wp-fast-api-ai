/**
 * Wizard entry point.
 */
import { createRoot } from '@wordpress/element';
import App from './wizard/App';
import './wizard.scss';

const root = document.getElementById( 'aipd-wizard-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
