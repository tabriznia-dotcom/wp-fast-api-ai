/**
 * Small shared UI components.
 */
import {
	Button,
	Modal,
	Notice,
	Spinner,
	BaseControl,
} from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { STEPS } from './constants';

/**
 * Accessible step indicator.
 *
 * @param {Object}                         props         Props.
 * @param {number}                         props.current Current index.
 * @param {number}                         props.reached Furthest reachable index.
 * @param {( ...args: unknown[] ) => void} props.onGo    Navigate callback.
 * @return {Element} Element.
 */
export function StepNav( { current, reached, onGo } ) {
	return (
		<nav
			className="aipd-steps"
			aria-label={ __( 'Wizard steps', 'ai-page-designer' ) }
		>
			<ol>
				{ STEPS.map( ( step, index ) => {
					let state = '';
					if ( index === current ) {
						state = 'is-current';
					} else if ( index < current ) {
						state = 'is-done';
					}
					const canGo =
						index <= reached && index !== current && index < 7;
					return (
						<li key={ step.key } className={ state }>
							{ canGo ? (
								<button
									type="button"
									className="aipd-steps__link"
									onClick={ () => onGo( index ) }
								>
									<span
										className="aipd-steps__num"
										aria-hidden="true"
									>
										{ index + 1 }
									</span>
									<span>{ step.label }</span>
								</button>
							) : (
								<span
									className="aipd-steps__link"
									aria-current={
										index === current ? 'step' : undefined
									}
								>
									<span
										className="aipd-steps__num"
										aria-hidden="true"
									>
										{ index + 1 }
									</span>
									<span>{ step.label }</span>
								</span>
							) }
						</li>
					);
				} ) }
			</ol>
		</nav>
	);
}

/**
 * Heading that receives focus when the step changes.
 *
 * @param {Object} props          Props.
 * @param {string} props.children Text.
 * @return {Element} Element.
 */
export function StepHeading( { children } ) {
	const ref = useRef();
	const mounted = useRef( false );
	useEffect( () => {
		// Move focus only when the step changes, not on first load.
		if ( mounted.current && ref.current ) {
			ref.current.focus();
		}
		mounted.current = true;
	}, [ children ] );
	return (
		<h2 className="aipd-step-title" tabIndex="-1" ref={ ref }>
			{ children }
		</h2>
	);
}

/**
 * Busy indicator with live status text.
 *
 * @param {Object} props       Props.
 * @param {string} props.label Status text.
 * @return {Element} Element.
 */
export function Busy( { label } ) {
	return (
		<div className="aipd-busy" role="status" aria-live="polite">
			<Spinner />
			<span>{ label }</span>
		</div>
	);
}

/**
 * Error notice with optional retry.
 *
 * @param {Object}                         props         Props.
 * @param {Object}                         props.error   Error { message, details }.
 * @param {( ...args: unknown[] ) => void} props.onRetry Retry callback.
 * @return {Element|null} Element.
 */
export function ErrorNotice( { error, onRetry } ) {
	if ( ! error ) {
		return null;
	}
	return (
		<Notice status="error" isDismissible={ false }>
			<p>{ error.message }</p>
			{ error.details && error.details.length > 0 && (
				<details>
					<summary>
						{ __( 'Technical details', 'ai-page-designer' ) }
					</summary>
					<ul>
						{ error.details.map( ( line, i ) => (
							<li key={ i }>{ String( line ) }</li>
						) ) }
					</ul>
				</details>
			) }
			{ onRetry && (
				<Button variant="secondary" onClick={ onRetry }>
					{ __( 'Try again', 'ai-page-designer' ) }
				</Button>
			) }
		</Notice>
	);
}

/**
 * Confirmation dialog shown before each request that may cost money.
 *
 * @param {Object}                         props           Props.
 * @param {Object}                         props.provider  Provider status.
 * @param {string}                         props.action    What will be generated.
 * @param {( ...args: unknown[] ) => void} props.onConfirm Confirm callback.
 * @param {( ...args: unknown[] ) => void} props.onCancel  Cancel callback.
 * @return {Element} Element.
 */
export function CostModal( { provider, action, onConfirm, onCancel } ) {
	const privacy = provider && provider.privacy ? provider.privacy : {};
	return (
		<Modal
			title={ __( 'Send this request?', 'ai-page-designer' ) }
			onRequestClose={ onCancel }
			className="aipd-modal"
		>
			<p>
				{ sprintf(
					/* translators: 1: what is generated, 2: service name. */
					__(
						'To create the %1$s, your brief will be sent to %2$s. This request may be billed by your AI provider.',
						'ai-page-designer'
					),
					action,
					privacy.service || ( provider && provider.name ) || ''
				) }
			</p>
			{ privacy.data_sent && (
				<p className="aipd-muted">{ privacy.data_sent }</p>
			) }
			<div className="aipd-modal__actions">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'ai-page-designer' ) }
				</Button>
				<Button variant="primary" onClick={ onConfirm }>
					{ __( 'Send request', 'ai-page-designer' ) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * Native color input with label.
 *
 * @param {Object}                         props          Props.
 * @param {string}                         props.id       Id.
 * @param {string}                         props.label    Label.
 * @param {string}                         props.value    Hex value.
 * @param {( ...args: unknown[] ) => void} props.onChange Change handler.
 * @return {Element} Element.
 */
export function ColorField( { id, label, value, onChange } ) {
	return (
		<BaseControl id={ id } label={ label } __nextHasNoMarginBottom>
			<div className="aipd-color">
				<input
					id={ id }
					type="color"
					value={ value }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
				<code>{ value }</code>
			</div>
		</BaseControl>
	);
}
