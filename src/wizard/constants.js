/**
 * Labels shared by the wizard. All strings are translatable.
 */
import { __ } from '@wordpress/i18n';

export const PAGE_TYPES = [
	{
		value: 'landing',
		label: __( 'Landing page', 'ai-page-designer' ),
		help: __(
			'One focused goal with a strong call to action.',
			'ai-page-designer'
		),
	},
	{
		value: 'services',
		label: __( 'Services', 'ai-page-designer' ),
		help: __(
			'Explain what you offer and how it works.',
			'ai-page-designer'
		),
	},
	{
		value: 'about',
		label: __( 'About us', 'ai-page-designer' ),
		help: __( 'Your story, team and values.', 'ai-page-designer' ),
	},
	{
		value: 'contact',
		label: __( 'Contact', 'ai-page-designer' ),
		help: __(
			'Ways to reach you and a contact form placeholder.',
			'ai-page-designer'
		),
	},
	{
		value: 'product',
		label: __( 'Product', 'ai-page-designer' ),
		help: __(
			'Features, benefits, pricing and FAQs for one product.',
			'ai-page-designer'
		),
	},
	{
		value: 'article',
		label: __( 'Article', 'ai-page-designer' ),
		help: __(
			'Long-form content with clear structure.',
			'ai-page-designer'
		),
	},
];

export const TONES = [
	{ value: 'professional', label: __( 'Professional', 'ai-page-designer' ) },
	{ value: 'friendly', label: __( 'Friendly', 'ai-page-designer' ) },
	{ value: 'bold', label: __( 'Bold', 'ai-page-designer' ) },
	{ value: 'luxurious', label: __( 'Luxurious', 'ai-page-designer' ) },
	{ value: 'playful', label: __( 'Playful', 'ai-page-designer' ) },
	{ value: 'technical', label: __( 'Technical', 'ai-page-designer' ) },
	{ value: 'warm', label: __( 'Warm', 'ai-page-designer' ) },
	{ value: 'formal', label: __( 'Formal', 'ai-page-designer' ) },
];

export const LANGUAGES = [
	{ value: 'en', label: __( 'English', 'ai-page-designer' ) },
	{ value: 'fa-IR', label: __( 'Persian (فارسی)', 'ai-page-designer' ) },
	{ value: 'ar', label: __( 'Arabic (العربية)', 'ai-page-designer' ) },
	{ value: 'he', label: __( 'Hebrew (עברית)', 'ai-page-designer' ) },
	{ value: 'ur', label: __( 'Urdu (اردو)', 'ai-page-designer' ) },
	{ value: 'de', label: __( 'German', 'ai-page-designer' ) },
	{ value: 'fr', label: __( 'French', 'ai-page-designer' ) },
	{ value: 'es', label: __( 'Spanish', 'ai-page-designer' ) },
	{ value: 'tr', label: __( 'Turkish', 'ai-page-designer' ) },
	{ value: 'other', label: __( 'Other (enter a code)', 'ai-page-designer' ) },
];

export const RTL = [
	'ar',
	'fa',
	'he',
	'ur',
	'ps',
	'sd',
	'ug',
	'yi',
	'ckb',
	'dv',
	'ku',
];

/**
 * Whether a language code is written right to left.
 *
 * @param {string} code Language code.
 * @return {boolean} RTL.
 */
export const isRtl = ( code ) =>
	RTL.includes(
		String( code || '' )
			.split( /[-_]/ )[ 0 ]
			.toLowerCase()
	);

export const SECTION_TYPES = [
	{ value: 'hero', label: __( 'Hero', 'ai-page-designer' ) },
	{ value: 'features', label: __( 'Features', 'ai-page-designer' ) },
	{ value: 'content', label: __( 'Content', 'ai-page-designer' ) },
	{ value: 'testimonials', label: __( 'Testimonials', 'ai-page-designer' ) },
	{ value: 'faq', label: __( 'FAQ', 'ai-page-designer' ) },
	{ value: 'pricing', label: __( 'Pricing', 'ai-page-designer' ) },
	{ value: 'cta', label: __( 'Call to action', 'ai-page-designer' ) },
	{ value: 'contact', label: __( 'Contact', 'ai-page-designer' ) },
	{ value: 'stats', label: __( 'Statistics', 'ai-page-designer' ) },
	{ value: 'steps', label: __( 'Steps', 'ai-page-designer' ) },
	{ value: 'gallery', label: __( 'Gallery', 'ai-page-designer' ) },
	{ value: 'team', label: __( 'Team', 'ai-page-designer' ) },
	{ value: 'logos', label: __( 'Logos', 'ai-page-designer' ) },
];

export const STEPS = [
	{ key: 'type', label: __( 'Page type', 'ai-page-designer' ) },
	{
		key: 'business',
		label: __( 'Business and audience', 'ai-page-designer' ),
	},
	{
		key: 'language',
		label: __( 'Language and direction', 'ai-page-designer' ),
	},
	{
		key: 'style',
		label: __( 'Colors, fonts and style', 'ai-page-designer' ),
	},
	{ key: 'builder', label: __( 'Page builder', 'ai-page-designer' ) },
	{ key: 'structure', label: __( 'Structure', 'ai-page-designer' ) },
	{ key: 'preview', label: __( 'Preview', 'ai-page-designer' ) },
	{ key: 'draft', label: __( 'Create draft', 'ai-page-designer' ) },
	{ key: 'done', label: __( 'Open in editor', 'ai-page-designer' ) },
];

export const DEVICES = [
	{ key: 'mobile', label: __( 'Mobile', 'ai-page-designer' ), width: 390 },
	{ key: 'tablet', label: __( 'Tablet', 'ai-page-designer' ), width: 820 },
	{ key: 'desktop', label: __( 'Desktop', 'ai-page-designer' ), width: 1280 },
];
