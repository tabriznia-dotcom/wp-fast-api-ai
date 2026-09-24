/**
 * REST helpers for the wizard.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const NS = '/aipd/v1';

/**
 * RFC 4122 v4 UUID used as the idempotency key of a request.
 *
 * @return {string} UUID.
 */
export function uuid() {
	if ( window.crypto && window.crypto.randomUUID ) {
		return window.crypto.randomUUID();
	}
	const bytes = window.crypto.getRandomValues( new Uint8Array( 16 ) );
	/* eslint-disable no-bitwise -- RFC 4122 version and variant bits. */
	bytes[ 6 ] = ( bytes[ 6 ] & 0x0f ) | 0x40;
	bytes[ 8 ] = ( bytes[ 8 ] & 0x3f ) | 0x80;
	/* eslint-enable no-bitwise */
	const hex = Array.from( bytes, ( b ) =>
		b.toString( 16 ).padStart( 2, '0' )
	).join( '' );
	return `${ hex.slice( 0, 8 ) }-${ hex.slice( 8, 12 ) }-${ hex.slice( 12, 16 ) }-${ hex.slice( 16, 20 ) }-${ hex.slice( 20 ) }`;
}

const wait = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

/**
 * Normalizes any thrown value into { code, message, details }.
 *
 * @param {unknown} error Error.
 * @return {{code: string, message: string, details: Array}} Error.
 */
export function toError( error ) {
	if ( error && error.message && error.code ) {
		const details = error.data && error.data.details;
		return {
			code: error.code,
			message: error.message,
			details: Array.isArray( details ) ? details : [],
		};
	}
	return {
		code: 'aipd_network',
		message: __(
			'The request could not be completed. Check your connection and try again.',
			'ai-page-designer'
		),
		details: [],
	};
}

export const getStatus = () => apiFetch( { path: `${ NS }/status` } );
export const getTemplates = () => apiFetch( { path: `${ NS }/templates` } );
export const getTemplate = ( id ) =>
	apiFetch( { path: `${ NS }/templates/${ encodeURIComponent( id ) }` } );
export const saveTemplate = ( title, schema ) =>
	apiFetch( {
		path: `${ NS }/templates`,
		method: 'POST',
		data: { title, schema },
	} );
export const getTokens = ( brief ) =>
	apiFetch( { path: `${ NS }/tokens`, method: 'POST', data: { brief } } );
export const preview = ( schema ) =>
	apiFetch( { path: `${ NS }/preview`, method: 'POST', data: { schema } } );
export const createDraft = ( data ) =>
	apiFetch( { path: `${ NS }/drafts`, method: 'POST', data } );
export const getDraftSchema = ( postId ) =>
	apiFetch( { path: `${ NS }/drafts/${ postId }/schema` } );
export const updateSection = ( postId, sectionId, schema ) =>
	apiFetch( {
		path: `${ NS }/drafts/${ postId }/sections/${ sectionId }`,
		method: 'PUT',
		data: { schema },
	} );

/**
 * Polls a job until it finishes.
 *
 * @param {string}                  id     Job id.
 * @param {( job: Object ) => void} onTick Progress callback.
 * @return {Promise<Object>} Job.
 */
async function poll( id, onTick ) {
	for ( let attempt = 0; attempt < 120; attempt++ ) {
		await wait( attempt < 10 ? 2000 : 5000 );
		const job = await apiFetch( { path: `${ NS }/jobs/${ id }` } );
		if ( onTick ) {
			onTick( job );
		}
		if ( 'completed' === job.status || 'failed' === job.status ) {
			return job;
		}
	}
	throw {
		code: 'aipd_poll_timeout',
		message: __(
			'The request is taking longer than expected. You can check its status later in Generation History.',
			'ai-page-designer'
		),
	};
}

/**
 * Creates and runs a generation job. The same id makes retries idempotent.
 *
 * @param {string}                  type        outline|page|section.
 * @param {Object}                  payload     Request data.
 * @param {boolean}                 confirmCost User confirmed a possibly paid request.
 * @param {( job: Object ) => void} onTick      Progress callback.
 * @return {Promise<Object>} Completed job.
 */
export async function runJob( type, payload, confirmCost, onTick ) {
	const id = uuid();
	await apiFetch( {
		path: `${ NS }/jobs`,
		method: 'POST',
		data: { ...payload, id, type, confirm_cost: !! confirmCost },
	} );

	let job;
	try {
		job = await apiFetch( {
			path: `${ NS }/jobs/${ id }/run`,
			method: 'POST',
		} );
	} catch ( error ) {
		// A proxy timeout does not stop the server; keep polling.
		if (
			error &&
			error.code &&
			'fetch_error' !== error.code &&
			'invalid_json' !== error.code
		) {
			throw error;
		}
		job = await poll( id, onTick );
	}
	if ( 'queued' === job.status || 'running' === job.status ) {
		job = await poll( id, onTick );
	}
	if ( 'failed' === job.status ) {
		throw (
			job.error || {
				code: 'aipd_failed',
				message: __( 'The request failed.', 'ai-page-designer' ),
			}
		);
	}
	return job;
}
