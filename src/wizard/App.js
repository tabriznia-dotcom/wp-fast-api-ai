/**
 * Page wizard.
 */
import {
	Button,
	Card,
	CardBody,
	Notice,
	RadioControl,
	RangeControl,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import * as api from './api';
import {
	Busy,
	ColorField,
	CostModal,
	ErrorNotice,
	StepHeading,
	StepNav,
} from './components';
import {
	DEVICES,
	LANGUAGES,
	PAGE_TYPES,
	SECTION_TYPES,
	STEPS,
	TONES,
	isRtl,
} from './constants';

const config = window.aipdWizard || {};

const DEFAULT_BRIEF = {
	page_type: 'landing',
	title: '',
	topic: '',
	goal: '',
	business: '',
	brand_name: '',
	audience: '',
	tone: 'professional',
	language: 'en',
	style: 'corporate',
	colors: {},
	heading_font: 'system-sans',
	body_font: 'system-sans',
	cta_text: '',
	cta_url: '',
	sections_count: 6,
	builder: 'gutenberg',
	notes: '',
};

/**
 * Root component.
 *
 * @return {Element} Element.
 */
export default function App() {
	const [ status, setStatus ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );
	const [ step, setStep ] = useState( 0 );
	const [ reached, setReached ] = useState( 0 );
	const [ brief, setBrief ] = useState( DEFAULT_BRIEF );
	const [ languageOther, setLanguageOther ] = useState( '' );
	const [ tokens, setTokens ] = useState( null );
	const [ outline, setOutline ] = useState( null );
	const [ schema, setSchema ] = useState( null );
	const [ warnings, setWarnings ] = useState( [] );
	const [ previewHtml, setPreviewHtml ] = useState( '' );
	const [ device, setDevice ] = useState( 'desktop' );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ confirm, setConfirm ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ jobId, setJobId ] = useState( '' );
	const [ dirty, setDirty ] = useState( false );
	const [ source, setSource ] = useState( 'ai' );
	const [ templates, setTemplates ] = useState( [] );
	const [ editPostId ] = useState( config.postId || 0 );
	const [ notice, setNotice ] = useState( '' );

	// Initial load: environment status, templates, optional template or existing page.
	useEffect( () => {
		( async () => {
			try {
				const s = await api.getStatus();
				setStatus( s );
				const kit = s.brand_kit || {};
				const available = ( s.builders || [] )
					.filter( ( b ) => b.available )
					.map( ( b ) => b.id );
				setBrief( ( b ) => ( {
					...b,
					brand_name: kit.brand_name || '',
					tone: kit.tone || b.tone,
					style: kit.style || b.style,
					colors: kit.colors || {},
					heading_font: kit.font_heading || b.heading_font,
					body_font: kit.font_body || b.body_font,
					language:
						kit.language ||
						( s.site_language && s.site_language.startsWith( 'fa' )
							? 'fa-IR'
							: b.language ),
					builder: available.includes( s.default_builder )
						? s.default_builder
						: available[ 0 ] || 'classic',
				} ) );
				setTemplates( await api.getTemplates() );

				if ( editPostId ) {
					const res = await api.getDraftSchema( editPostId );
					await showPreview( res.schema );
					setDraft( { post_id: editPostId, builder: res.builder } );
					setReached( 6 );
					setStep( 6 );
				} else if ( config.templateId ) {
					await loadTemplate( config.templateId );
				}
			} catch ( e ) {
				setLoadError( api.toError( e ) );
			}
		} )();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Warn before leaving with unsaved work.
	useEffect( () => {
		const handler = ( event ) => {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', handler );
		return () => window.removeEventListener( 'beforeunload', handler );
	}, [ dirty ] );

	const update = ( changes ) => {
		setBrief( ( b ) => ( { ...b, ...changes } ) );
		setDirty( true );
	};

	const go = ( index ) => {
		setError( null );
		setStep( index );
		setReached( ( r ) => Math.max( r, index ) );
	};

	const effectiveLanguage =
		'other' === brief.language ? languageOther : brief.language;
	const direction = isRtl( effectiveLanguage ) ? 'rtl' : 'ltr';
	const briefPayload = useMemo(
		() => ( { ...brief, language: effectiveLanguage } ),
		[ brief, effectiveLanguage ]
	);

	const aiReady =
		status &&
		status.privacy_acknowledged &&
		status.provider &&
		status.provider.configured;

	/**
	 * Runs an AI action, asking for cost confirmation first when required.
	 *
	 * @param {string}                         label  Human label of what is generated.
	 * @param {( ...args: unknown[] ) => void} action Async action receiving confirmCost.
	 */
	const withConfirm = ( label, action ) => {
		setError( null );
		if ( status && status.confirm_cost ) {
			setConfirm( { label, action } );
		} else {
			action( false );
		}
	};

	const showPreview = useCallback( async ( nextSchema ) => {
		const res = await api.preview( nextSchema );
		setSchema( res.schema );
		setWarnings( res.warnings || [] );
		setPreviewHtml( res.html );
		return res.schema;
	}, [] );

	async function loadTemplate( id ) {
		setBusy( __( 'Loading template…', 'ai-page-designer' ) );
		try {
			const res = await api.getTemplate( id );
			await showPreview( res.schema );
			setSource( 'template' );
			setDirty( true );
			setReached( 6 );
			setStep( 6 );
		} catch ( e ) {
			setError( api.toError( e ) );
		} finally {
			setBusy( '' );
		}
	}

	const requestOutline = () =>
		withConfirm(
			__( 'page structure', 'ai-page-designer' ),
			async ( confirmCost ) => {
				setBusy(
					__( 'Designing the page structure…', 'ai-page-designer' )
				);
				try {
					const t = await api.getTokens( briefPayload );
					setTokens( t.tokens );
					const job = await api.runJob(
						'outline',
						{ brief: briefPayload },
						confirmCost,
						() =>
							setBusy(
								__(
									'Still working on the structure…',
									'ai-page-designer'
								)
							)
					);
					setOutline( job.result.outline );
					setDirty( true );
				} catch ( e ) {
					setError( api.toError( e ) );
				} finally {
					setBusy( '' );
				}
			}
		);

	const requestPage = () =>
		withConfirm(
			__( 'page content', 'ai-page-designer' ),
			async ( confirmCost ) => {
				setBusy(
					__(
						'Writing the page content. This can take a minute…',
						'ai-page-designer'
					)
				);
				try {
					const job = await api.runJob(
						'page',
						{ brief: briefPayload, outline, tokens },
						confirmCost,
						() =>
							setBusy(
								__(
									'Still writing… large pages take longer.',
									'ai-page-designer'
								)
							)
					);
					setJobId( job.id );
					await showPreview( job.result.schema );
					setDirty( true );
					go( 6 );
				} catch ( e ) {
					setError( api.toError( e ) );
				} finally {
					setBusy( '' );
				}
			}
		);

	const regenerateSection = ( sectionId, instruction ) =>
		withConfirm(
			__( 'new section', 'ai-page-designer' ),
			async ( confirmCost ) => {
				setBusy( __( 'Rewriting the section…', 'ai-page-designer' ) );
				try {
					const job = await api.runJob(
						'section',
						{
							schema,
							section_id: sectionId,
							instruction,
							brief: briefPayload,
						},
						confirmCost
					);
					await showPreview( job.result.schema );
					setDirty( true );
					setNotice(
						__(
							'Section updated in the preview.',
							'ai-page-designer'
						)
					);
				} catch ( e ) {
					setError( api.toError( e ) );
				} finally {
					setBusy( '' );
				}
			}
		);

	const applySection = async ( sectionId ) => {
		setBusy( __( 'Updating the page…', 'ai-page-designer' ) );
		try {
			await api.updateSection( draft.post_id, sectionId, schema );
			setNotice(
				__(
					'The section was replaced in the draft. The previous version is available in Revisions.',
					'ai-page-designer'
				)
			);
		} catch ( e ) {
			setError( api.toError( e ) );
		} finally {
			setBusy( '' );
		}
	};

	const makeDraft = async () => {
		setBusy( __( 'Creating the draft…', 'ai-page-designer' ) );
		setError( null );
		try {
			const res = await api.createDraft( {
				schema,
				builder: brief.builder,
				job_id: jobId,
			} );
			setDraft( res );
			setDirty( false );
			go( 8 );
		} catch ( e ) {
			setError( api.toError( e ) );
		} finally {
			setBusy( '' );
		}
	};

	const saveAsTemplate = async () => {
		setBusy( __( 'Saving template…', 'ai-page-designer' ) );
		try {
			await api.saveTemplate( schema.meta.title, schema );
			setNotice( __( 'Saved as a template.', 'ai-page-designer' ) );
		} catch ( e ) {
			setError( api.toError( e ) );
		} finally {
			setBusy( '' );
		}
	};

	if ( loadError ) {
		return (
			<ErrorNotice
				error={ loadError }
				onRetry={ () => window.location.reload() }
			/>
		);
	}
	if ( ! status ) {
		return <Busy label={ __( 'Loading…', 'ai-page-designer' ) } />;
	}

	const next = ( disabled = false ) => (
		<div className="aipd-actions">
			{ step > 0 && (
				<Button variant="tertiary" onClick={ () => go( step - 1 ) }>
					{ __( 'Back', 'ai-page-designer' ) }
				</Button>
			) }
			<Button
				variant="primary"
				onClick={ () => go( step + 1 ) }
				disabled={ disabled }
			>
				{ __( 'Continue', 'ai-page-designer' ) }
			</Button>
		</div>
	);

	const setupNotice = ! aiReady && (
		<Notice status="warning" isDismissible={ false }>
			<p>
				{ ! status.privacy_acknowledged
					? __(
							'AI generation is not enabled yet: an administrator must accept the data sharing notice.',
							'ai-page-designer'
						)
					: __(
							'AI generation is not enabled yet: no AI provider is configured.',
							'ai-page-designer'
						) }{ ' ' }
				{ __(
					'You can still start from a template.',
					'ai-page-designer'
				) }
			</p>
			{ status.can_manage && (
				<p>
					<a
						href={
							status.privacy_acknowledged
								? config.providerUrl
								: config.privacyUrl
						}
					>
						{ __( 'Complete setup', 'ai-page-designer' ) }
					</a>
				</p>
			) }
		</Notice>
	);

	let body;
	switch ( STEPS[ step ].key ) {
		case 'type':
			body = (
				<>
					{ setupNotice }
					<RadioControl
						label={ __(
							'How do you want to start?',
							'ai-page-designer'
						) }
						selected={ source }
						options={ [
							{
								value: 'ai',
								label: __(
									'Describe my page and let AI draft it',
									'ai-page-designer'
								),
							},
							{
								value: 'template',
								label: __(
									'Start from a template (no AI request)',
									'ai-page-designer'
								),
							},
						] }
						onChange={ setSource }
					/>
					{ 'template' === source ? (
						<TemplatePicker
							templates={ templates }
							onPick={ loadTemplate }
						/>
					) : (
						<>
							<fieldset className="aipd-choice-grid">
								<legend>
									{ __( 'Page type', 'ai-page-designer' ) }
								</legend>
								{ PAGE_TYPES.map( ( t ) => (
									<label
										key={ t.value }
										htmlFor={ `aipd-type-${ t.value }` }
										className={
											'aipd-choice' +
											( brief.page_type === t.value
												? ' is-selected'
												: '' )
										}
									>
										<input
											type="radio"
											name="aipd-page-type"
											id={ `aipd-type-${ t.value }` }
											value={ t.value }
											checked={
												brief.page_type === t.value
											}
											onChange={ () =>
												update( { page_type: t.value } )
											}
										/>
										<strong>{ t.label }</strong>
										<span>{ t.help }</span>
									</label>
								) ) }
							</fieldset>
							{ next( ! aiReady ) }
						</>
					) }
				</>
			);
			break;

		case 'business':
			body = (
				<>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Page title', 'ai-page-designer' ) }
						value={ brief.title }
						onChange={ ( v ) => update( { title: v } ) }
						maxLength={ 120 }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Topic (required)', 'ai-page-designer' ) }
						help={ __(
							'For example: online Persian cooking classes.',
							'ai-page-designer'
						) }
						value={ brief.topic }
						onChange={ ( v ) => update( { topic: v } ) }
						maxLength={ 300 }
						required
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Goal of the page', 'ai-page-designer' ) }
						value={ brief.goal }
						onChange={ ( v ) => update( { goal: v } ) }
						maxLength={ 500 }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'About the business', 'ai-page-designer' ) }
						help={ __(
							'Do not include personal or confidential data. This text is sent to the AI service.',
							'ai-page-designer'
						) }
						value={ brief.business }
						onChange={ ( v ) => update( { business: v } ) }
						maxLength={ 1000 }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Brand name', 'ai-page-designer' ) }
						value={ brief.brand_name }
						onChange={ ( v ) => update( { brand_name: v } ) }
						maxLength={ 100 }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Target audience', 'ai-page-designer' ) }
						value={ brief.audience }
						onChange={ ( v ) => update( { audience: v } ) }
						maxLength={ 500 }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Tone', 'ai-page-designer' ) }
						value={ brief.tone }
						options={ TONES }
						onChange={ ( v ) => update( { tone: v } ) }
					/>
					<div className="aipd-row">
						<TextControl
							__nextHasNoMarginBottom
							label={ __(
								'Main call to action',
								'ai-page-designer'
							) }
							help={ __(
								'For example: Book a free call.',
								'ai-page-designer'
							) }
							value={ brief.cta_text }
							onChange={ ( v ) => update( { cta_text: v } ) }
							maxLength={ 60 }
						/>
						<TextControl
							__nextHasNoMarginBottom
							type="url"
							label={ __(
								'Call to action link',
								'ai-page-designer'
							) }
							value={ brief.cta_url }
							onChange={ ( v ) => update( { cta_url: v } ) }
						/>
					</div>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Number of sections', 'ai-page-designer' ) }
						value={ brief.sections_count }
						min={ 3 }
						max={ 10 }
						onChange={ ( v ) => update( { sections_count: v } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Additional notes', 'ai-page-designer' ) }
						value={ brief.notes }
						onChange={ ( v ) => update( { notes: v } ) }
						maxLength={ 1000 }
					/>
					{ next( '' === brief.topic.trim() ) }
				</>
			);
			break;

		case 'language':
			body = (
				<>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Content language', 'ai-page-designer' ) }
						help={ __(
							'The language of the generated page. It can differ from your admin language.',
							'ai-page-designer'
						) }
						value={
							LANGUAGES.some(
								( l ) => l.value === brief.language
							)
								? brief.language
								: 'other'
						}
						options={ LANGUAGES }
						onChange={ ( v ) => update( { language: v } ) }
					/>
					{ 'other' === brief.language && (
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Language code', 'ai-page-designer' ) }
							help={ __(
								'BCP 47 code such as nl, pt-BR or ckb.',
								'ai-page-designer'
							) }
							value={ languageOther }
							onChange={ ( v ) =>
								setLanguageOther(
									v.replace( /[^A-Za-z0-9_-]/g, '' )
								)
							}
						/>
					) }
					<p className="aipd-direction" dir={ direction }>
						{ 'rtl' === direction
							? __(
									'Direction: right to left. Layout, alignment and reading order will be mirrored.',
									'ai-page-designer'
								)
							: __(
									'Direction: left to right.',
									'ai-page-designer'
								) }
					</p>
					{ next(
						'other' === brief.language &&
							! /^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/.test(
								languageOther
							)
					) }
				</>
			);
			break;

		case 'style':
			body = (
				<>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Design style', 'ai-page-designer' ) }
						value={ brief.style }
						options={ Object.entries( status.styles || {} ).map(
							( [ value, label ] ) => ( { value, label } )
						) }
						onChange={ ( v ) => update( { style: v } ) }
					/>
					<div className="aipd-color-grid">
						{ [
							[ 'primary', __( 'Primary', 'ai-page-designer' ) ],
							[
								'secondary',
								__( 'Secondary', 'ai-page-designer' ),
							],
							[ 'accent', __( 'Accent', 'ai-page-designer' ) ],
							[ 'text', __( 'Text', 'ai-page-designer' ) ],
							[
								'background',
								__( 'Background', 'ai-page-designer' ),
							],
						].map( ( [ key, label ] ) => (
							<ColorField
								key={ key }
								id={ `aipd-color-${ key }` }
								label={ label }
								value={ brief.colors[ key ] || '#000000' }
								onChange={ ( v ) =>
									update( {
										colors: { ...brief.colors, [ key ]: v },
									} )
								}
							/>
						) ) }
					</div>
					<p className="aipd-muted">
						{ __(
							'Colors are adjusted automatically if text would not meet WCAG AA contrast.',
							'ai-page-designer'
						) }
					</p>
					<div className="aipd-row">
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Heading font', 'ai-page-designer' ) }
							value={ brief.heading_font }
							options={ ( status.fonts || [] ).map( ( f ) => ( {
								value: f.id,
								label: f.label,
							} ) ) }
							onChange={ ( v ) => update( { heading_font: v } ) }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Body font', 'ai-page-designer' ) }
							value={ brief.body_font }
							options={ ( status.fonts || [] ).map( ( f ) => ( {
								value: f.id,
								label: f.label,
							} ) ) }
							onChange={ ( v ) => update( { body_font: v } ) }
						/>
					</div>
					<p className="aipd-muted">
						{ __(
							'No font files are downloaded. Choose a system font or a font provided by your theme.',
							'ai-page-designer'
						) }
					</p>
					{ next() }
				</>
			);
			break;

		case 'builder':
			body = (
				<>
					<fieldset className="aipd-choice-grid">
						<legend>
							{ __(
								'Where should the page be created?',
								'ai-page-designer'
							) }
						</legend>
						{ ( status.builders || [] ).map( ( b ) => (
							<label
								key={ b.id }
								htmlFor={ `aipd-builder-${ b.id }` }
								className={
									'aipd-choice' +
									( brief.builder === b.id
										? ' is-selected'
										: '' ) +
									( b.available ? '' : ' is-disabled' )
								}
							>
								<input
									type="radio"
									name="aipd-builder"
									id={ `aipd-builder-${ b.id }` }
									value={ b.id }
									disabled={ ! b.available }
									checked={ brief.builder === b.id }
									onChange={ () =>
										update( { builder: b.id } )
									}
								/>
								<strong>{ b.name }</strong>
								<span>
									{ b.available
										? __( 'Available', 'ai-page-designer' )
										: __(
												'Not active on this site',
												'ai-page-designer'
											) }
								</span>
							</label>
						) ) }
					</fieldset>
					{ next() }
				</>
			);
			break;

		case 'structure':
			body = (
				<>
					{ ! outline && ! busy && (
						<Card>
							<CardBody>
								<p>
									{ __(
										'The AI will propose a section-by-section structure. You can edit it before any content is written.',
										'ai-page-designer'
									) }
								</p>
								<Button
									variant="primary"
									onClick={ requestOutline }
									disabled={ ! aiReady }
								>
									{ __(
										'Propose structure',
										'ai-page-designer'
									) }
								</Button>
							</CardBody>
						</Card>
					) }
					{ outline && (
						<OutlineEditor
							outline={ outline }
							onChange={ ( o ) => {
								setOutline( o );
								setDirty( true );
							} }
						/>
					) }
					{ outline && (
						<div className="aipd-actions">
							<Button
								variant="tertiary"
								onClick={ () => go( step - 1 ) }
							>
								{ __( 'Back', 'ai-page-designer' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ requestOutline }
							>
								{ __( 'Propose again', 'ai-page-designer' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ requestPage }
								disabled={ ! outline.sections.length }
							>
								{ __(
									'Approve and write content',
									'ai-page-designer'
								) }
							</Button>
						</div>
					) }
				</>
			);
			break;

		case 'preview':
			body = schema && (
				<>
					{ notice && (
						<Notice
							status="success"
							onRemove={ () => setNotice( '' ) }
						>
							<p>{ notice }</p>
						</Notice>
					) }
					{ warnings.length > 0 && (
						<Notice status="info" isDismissible={ false }>
							<details>
								<summary>
									{ sprintf(
										/* translators: %d: number of automatic fixes. */
										__(
											'%d automatic quality and accessibility fixes were applied. Review them.',
											'ai-page-designer'
										),
										warnings.length
									) }
								</summary>
								<ul>
									{ warnings.slice( 0, 30 ).map( ( w, i ) => (
										<li key={ i }>{ w }</li>
									) ) }
								</ul>
							</details>
						</Notice>
					) }
					<div
						className="aipd-preview-toolbar"
						role="group"
						aria-label={ __( 'Preview size', 'ai-page-designer' ) }
					>
						{ DEVICES.map( ( d ) => (
							<Button
								key={ d.key }
								variant={
									device === d.key ? 'primary' : 'secondary'
								}
								aria-pressed={ device === d.key }
								onClick={ () => setDevice( d.key ) }
							>
								{ d.label }
							</Button>
						) ) }
					</div>
					<div className="aipd-preview-frame">
						<iframe
							title={ __( 'Page preview', 'ai-page-designer' ) }
							sandbox=""
							srcDoc={ previewHtml }
							style={ {
								width: `${ DEVICES.find( ( d ) => d.key === device ).width }px`,
							} }
						/>
					</div>
					<SectionList
						schema={ schema }
						aiReady={ aiReady }
						canApply={ !! ( draft && draft.post_id ) }
						onRegenerate={ regenerateSection }
						onApply={ applySection }
					/>
					<div className="aipd-actions">
						{ ! editPostId && (
							<Button
								variant="tertiary"
								onClick={ () =>
									go( 'template' === source ? 0 : 5 )
								}
							>
								{ __( 'Back', 'ai-page-designer' ) }
							</Button>
						) }
						<Button variant="secondary" onClick={ saveAsTemplate }>
							{ __( 'Save as template', 'ai-page-designer' ) }
						</Button>
						{ ! editPostId && (
							<Button variant="primary" onClick={ () => go( 7 ) }>
								{ __(
									'Looks good, continue',
									'ai-page-designer'
								) }
							</Button>
						) }
						{ editPostId > 0 && draft && (
							<Button
								variant="primary"
								href={ `${ config.adminUrl }post.php?post=${ editPostId }&action=edit` }
							>
								{ __(
									'Back to the editor',
									'ai-page-designer'
								) }
							</Button>
						) }
					</div>
				</>
			);
			break;

		case 'draft':
			body = (
				<>
					<p>
						{ sprintf(
							/* translators: 1: page title, 2: builder name. */
							__(
								'"%1$s" will be saved as a draft for %2$s. It will not be published.',
								'ai-page-designer'
							),
							schema ? schema.meta.title : '',
							(
								( status.builders || [] ).find(
									( b ) => b.id === brief.builder
								) || {}
							).name || brief.builder
						) }
					</p>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Page builder', 'ai-page-designer' ) }
						value={ brief.builder }
						options={ ( status.builders || [] )
							.filter( ( b ) => b.available )
							.map( ( b ) => ( {
								value: b.id,
								label: b.name,
							} ) ) }
						onChange={ ( v ) => update( { builder: v } ) }
					/>
					<div className="aipd-actions">
						<Button variant="tertiary" onClick={ () => go( 6 ) }>
							{ __( 'Back', 'ai-page-designer' ) }
						</Button>
						<Button variant="primary" onClick={ makeDraft }>
							{ __( 'Create draft', 'ai-page-designer' ) }
						</Button>
					</div>
				</>
			);
			break;

		case 'done':
			body = draft && (
				<Notice status="success" isDismissible={ false }>
					<p>
						{ __(
							'Your draft is ready. Review and edit it, then publish when you are ready.',
							'ai-page-designer'
						) }
					</p>
					<p className="aipd-actions">
						<Button variant="primary" href={ draft.edit_url }>
							{ __( 'Open in editor', 'ai-page-designer' ) }
						</Button>
						{ draft.preview_url && (
							<Button
								variant="secondary"
								href={ draft.preview_url }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Preview draft', 'ai-page-designer' ) }
								<span className="screen-reader-text">
									{ __(
										'(opens in a new tab)',
										'ai-page-designer'
									) }
								</span>
							</Button>
						) }
						<Button
							variant="tertiary"
							href={ `${ config.adminUrl }admin.php?page=aipd-wizard` }
						>
							{ __( 'Create another page', 'ai-page-designer' ) }
						</Button>
					</p>
				</Notice>
			);
			break;
	}

	return (
		<div className="aipd-wizard" dir={ config.isRtl ? 'rtl' : 'ltr' }>
			<StepNav current={ step } reached={ reached } onGo={ go } />
			<main className="aipd-wizard__panel" aria-busy={ !! busy }>
				<StepHeading>{ STEPS[ step ].label }</StepHeading>
				<ErrorNotice
					error={ error }
					onRetry={
						error && 'structure' === STEPS[ step ].key
							? requestOutline
							: null
					}
				/>
				{ busy ? <Busy label={ busy } /> : body }
			</main>
			{ confirm && (
				<CostModal
					provider={ status.provider }
					action={ confirm.label }
					onCancel={ () => setConfirm( null ) }
					onConfirm={ () => {
						const { action } = confirm;
						setConfirm( null );
						action( true );
					} }
				/>
			) }
		</div>
	);
}

/**
 * Editable outline (text wireframe).
 *
 * @param {Object}                         props          Props.
 * @param {Object}                         props.outline  Outline.
 * @param {( ...args: unknown[] ) => void} props.onChange Change handler.
 * @return {Element} Element.
 */
function OutlineEditor( { outline, onChange } ) {
	const sections = outline.sections;
	const set = ( index, changes ) =>
		onChange( {
			...outline,
			sections: sections.map( ( s, i ) =>
				i === index ? { ...s, ...changes } : s
			),
		} );
	const move = ( index, delta ) => {
		const list = [ ...sections ];
		const [ item ] = list.splice( index, 1 );
		list.splice( index + delta, 0, item );
		onChange( { ...outline, sections: list } );
	};
	const remove = ( index ) =>
		onChange( {
			...outline,
			sections: sections.filter( ( s, i ) => i !== index ),
		} );
	const add = () =>
		onChange( {
			...outline,
			sections: [
				...sections,
				{
					id: `section-${ sections.length + 1 }`,
					type: 'content',
					label: __( 'New section', 'ai-page-designer' ),
					purpose: '',
				},
			],
		} );

	return (
		<>
			{ outline.notes && <p className="aipd-muted">{ outline.notes }</p> }
			<ol className="aipd-outline">
				{ sections.map( ( section, index ) => (
					<li
						key={ section.id + index }
						className="aipd-outline__item"
					>
						<div className="aipd-row">
							<TextControl
								__nextHasNoMarginBottom
								label={ __(
									'Section name',
									'ai-page-designer'
								) }
								value={ section.label }
								onChange={ ( v ) => set( index, { label: v } ) }
							/>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Type', 'ai-page-designer' ) }
								value={ section.type }
								options={ SECTION_TYPES }
								onChange={ ( v ) => set( index, { type: v } ) }
							/>
						</div>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __(
								'Content and layout',
								'ai-page-designer'
							) }
							value={ section.purpose || '' }
							onChange={ ( v ) => set( index, { purpose: v } ) }
							rows={ 2 }
						/>
						<div className="aipd-outline__tools">
							<Button
								size="small"
								variant="tertiary"
								disabled={ 0 === index }
								onClick={ () => move( index, -1 ) }
								label={ sprintf(
									/* translators: %s: section name. */ __(
										'Move %s up',
										'ai-page-designer'
									),
									section.label
								) }
							>
								{ __( 'Up', 'ai-page-designer' ) }
							</Button>
							<Button
								size="small"
								variant="tertiary"
								disabled={ index === sections.length - 1 }
								onClick={ () => move( index, 1 ) }
								label={ sprintf(
									/* translators: %s: section name. */ __(
										'Move %s down',
										'ai-page-designer'
									),
									section.label
								) }
							>
								{ __( 'Down', 'ai-page-designer' ) }
							</Button>
							<Button
								size="small"
								variant="tertiary"
								isDestructive
								onClick={ () => remove( index ) }
								label={ sprintf(
									/* translators: %s: section name. */ __(
										'Remove %s',
										'ai-page-designer'
									),
									section.label
								) }
							>
								{ __( 'Remove', 'ai-page-designer' ) }
							</Button>
						</div>
					</li>
				) ) }
			</ol>
			{ sections.length < 12 && (
				<Button variant="secondary" onClick={ add }>
					{ __( 'Add section', 'ai-page-designer' ) }
				</Button>
			) }
		</>
	);
}

/**
 * Section list with regeneration controls.
 *
 * @param {Object}                         props              Props.
 * @param {Object}                         props.schema       Schema.
 * @param {boolean}                        props.aiReady      AI available.
 * @param {boolean}                        props.canApply     An existing draft can be updated.
 * @param {( ...args: unknown[] ) => void} props.onRegenerate Regenerate callback.
 * @param {( ...args: unknown[] ) => void} props.onApply      Apply callback.
 * @return {Element} Element.
 */
function SectionList( { schema, aiReady, canApply, onRegenerate, onApply } ) {
	const [ open, setOpen ] = useState( '' );
	const [ instruction, setInstruction ] = useState( '' );
	return (
		<section
			className="aipd-sections"
			aria-labelledby="aipd-sections-title"
		>
			<h3 id="aipd-sections-title">
				{ __( 'Sections', 'ai-page-designer' ) }
			</h3>
			<ul>
				{ schema.sections.map( ( section ) => (
					<li key={ section.id }>
						<span className="aipd-sections__name">
							{ section.label || section.id }
						</span>{ ' ' }
						<code>{ section.type }</code>
						<span className="aipd-sections__tools">
							{ aiReady && (
								<Button
									size="small"
									variant="secondary"
									aria-expanded={ open === section.id }
									onClick={ () =>
										setOpen(
											open === section.id
												? ''
												: section.id
										)
									}
								>
									{ __( 'Regenerate', 'ai-page-designer' ) }
								</Button>
							) }
							{ canApply && (
								<Button
									size="small"
									variant="tertiary"
									onClick={ () => onApply( section.id ) }
								>
									{ __(
										'Apply to page',
										'ai-page-designer'
									) }
								</Button>
							) }
						</span>
						{ open === section.id && (
							<div className="aipd-sections__form">
								<TextareaControl
									__nextHasNoMarginBottom
									label={ __(
										'What should change?',
										'ai-page-designer'
									) }
									help={ __(
										'For example: make it shorter and add three benefits.',
										'ai-page-designer'
									) }
									value={ instruction }
									onChange={ setInstruction }
									maxLength={ 1000 }
									rows={ 2 }
								/>
								<Button
									variant="primary"
									onClick={ () => {
										onRegenerate( section.id, instruction );
										setOpen( '' );
										setInstruction( '' );
									} }
								>
									{ __(
										'Rewrite section',
										'ai-page-designer'
									) }
								</Button>
							</div>
						) }
					</li>
				) ) }
			</ul>
		</section>
	);
}

/**
 * Template chooser.
 *
 * @param {Object}                 props           Props.
 * @param {Array}                  props.templates Templates.
 * @param {( id: string ) => void} props.onPick    Pick callback.
 * @return {Element} Element.
 */
function TemplatePicker( { templates, onPick } ) {
	if ( ! templates.length ) {
		return (
			<p className="aipd-empty">
				{ __( 'No templates found.', 'ai-page-designer' ) }
			</p>
		);
	}
	return (
		<ul className="aipd-template-list">
			{ templates.map( ( t ) => (
				<li key={ t.id }>
					<Card>
						<CardBody>
							<h3>{ t.title }</h3>
							<p className="aipd-muted">{ `${ t.language } · ${ t.direction.toUpperCase() }` }</p>
							<Button
								variant="secondary"
								onClick={ () => onPick( t.id ) }
							>
								{ __(
									'Use this template',
									'ai-page-designer'
								) }
							</Button>
						</CardBody>
					</Card>
				</li>
			) ) }
		</ul>
	);
}
