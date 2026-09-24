<?php
/**
 * Built-in and user-saved Page Schema templates.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Templates;

use AIPageDesigner\Schema\SchemaService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in templates live in /templates/*.json. User templates are stored in a
 * private custom post type with the schema in post meta.
 */
final class TemplateRepository {

	const POST_TYPE = 'aipd_template';
	const META      = '_aipd_template_schema';
	const MAX_BYTES = 524288;

	/**
	 * Registers the private post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Page templates', 'ai-page-designer' ),
					'singular_name' => __( 'Page template', 'ai-page-designer' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title', 'author' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Built-in templates metadata.
	 *
	 * @return array<string,array{title:string,file:string,language:string,direction:string}>
	 */
	public static function builtin() {
		$templates = array(
			'landing-page-en' => array(
				'title'     => __( 'Landing page (English, LTR)', 'ai-page-designer' ),
				'file'      => AIPD_DIR . 'templates/landing-page-en.json',
				'language'  => 'en',
				'direction' => 'ltr',
			),
			'landing-page-fa' => array(
				'title'     => __( 'Landing page (Persian, RTL)', 'ai-page-designer' ),
				'file'      => AIPD_DIR . 'templates/landing-page-fa.json',
				'language'  => 'fa-IR',
				'direction' => 'rtl',
			),
		);

		/**
		 * Filters built-in templates. Files must be JSON Page Schemas inside a
		 * directory you control; they are validated before use.
		 *
		 * @since 1.0.0
		 *
		 * @param array $templates id => array( title, file, language, direction ).
		 */
		return (array) apply_filters( 'aipd_register_templates', $templates );
	}

	/**
	 * Lists templates (built-in first, then user templates).
	 *
	 * @return array[]
	 */
	public static function all() {
		$out = array();
		foreach ( self::builtin() as $id => $template ) {
			$out[] = array(
				'id'        => 'builtin:' . sanitize_key( $id ),
				'title'     => $template['title'],
				'language'  => isset( $template['language'] ) ? $template['language'] : '',
				'direction' => isset( $template['direction'] ) ? $template['direction'] : 'ltr',
				'builtin'   => true,
			);
		}
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post ) {
			$schema = self::decode_meta( $post->ID );
			$out[]  = array(
				'id'        => 'user:' . $post->ID,
				'title'     => get_the_title( $post ),
				'language'  => $schema ? $schema['meta']['language'] : '',
				'direction' => $schema ? $schema['meta']['direction'] : 'ltr',
				'builtin'   => false,
			);
		}
		return $out;
	}

	/**
	 * Loads and validates a template schema.
	 *
	 * @param string $id Template id (builtin:slug or user:ID).
	 * @return array|WP_Error Schema.
	 */
	public static function get( $id ) {
		$id = (string) $id;
		if ( 0 === strpos( $id, 'builtin:' ) ) {
			$slug     = sanitize_key( substr( $id, 8 ) );
			$builtins = self::builtin();
			if ( ! isset( $builtins[ $slug ] ) ) {
				return new WP_Error( 'aipd_template_not_found', __( 'Template not found.', 'ai-page-designer' ), array( 'status' => 404 ) );
			}
			$file = realpath( $builtins[ $slug ]['file'] );
			if ( false === $file || '.json' !== substr( $file, -5 ) || ! is_readable( $file ) || filesize( $file ) > self::MAX_BYTES ) {
				return new WP_Error( 'aipd_template_unreadable', __( 'The template file could not be read.', 'ai-page-designer' ), array( 'status' => 500 ) );
			}
			$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file.
			$data = json_decode( (string) $raw, true );
			return self::validate( $data );
		}
		if ( 0 === strpos( $id, 'user:' ) ) {
			$post_id = absint( substr( $id, 5 ) );
			$post    = get_post( $post_id );
			if ( ! $post || self::POST_TYPE !== $post->post_type ) {
				return new WP_Error( 'aipd_template_not_found', __( 'Template not found.', 'ai-page-designer' ), array( 'status' => 404 ) );
			}
			return self::validate( self::decode_meta( $post_id ) );
		}
		return new WP_Error( 'aipd_template_not_found', __( 'Template not found.', 'ai-page-designer' ), array( 'status' => 404 ) );
	}

	/**
	 * Saves a schema as a user template.
	 *
	 * @param string $title  Title.
	 * @param mixed  $schema Untrusted schema.
	 * @return int|WP_Error Template post id.
	 */
	public static function save( $title, $schema ) {
		$valid = self::validate( $schema );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$title   = sanitize_text_field( (string) $title );
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => '' !== $title ? $title : $valid['meta']['title'],
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'aipd_template_save_failed', __( 'The template could not be saved.', 'ai-page-designer' ), array( 'status' => 500 ) );
		}
		update_post_meta( $post_id, self::META, wp_slash( (string) wp_json_encode( $valid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		return (int) $post_id;
	}

	/**
	 * Deletes a user template.
	 *
	 * @param string $id Template id.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		if ( 0 !== strpos( (string) $id, 'user:' ) ) {
			return new WP_Error( 'aipd_template_readonly', __( 'Built-in templates cannot be deleted.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}
		$post_id = absint( substr( $id, 5 ) );
		$post    = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'aipd_template_not_found', __( 'Template not found.', 'ai-page-designer' ), array( 'status' => 404 ) );
		}
		wp_delete_post( $post_id, true );
		return true;
	}

	/**
	 * Export payload for a template.
	 *
	 * @param string $id Template id.
	 * @return array|WP_Error
	 */
	public static function export( $id ) {
		$schema = self::get( $id );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		return array(
			'format'  => 'ai-page-designer-template',
			'version' => 1,
			'title'   => $schema['meta']['title'],
			'schema'  => $schema,
		);
	}

	/**
	 * Imports an uploaded JSON file after validating size, extension, MIME type and content.
	 *
	 * @param array $file Entry from $_FILES (already verified by the caller's nonce/capability checks).
	 * @return int|WP_Error Template post id.
	 */
	public static function import_upload( array $file ) {
		if ( ! isset( $file['tmp_name'], $file['name'], $file['size'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'aipd_upload_failed', __( 'The file upload failed.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'aipd_upload_failed', __( 'The file upload failed.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES || filesize( $file['tmp_name'] ) > self::MAX_BYTES ) {
			return new WP_Error( 'aipd_upload_too_large', __( 'The template file is too large (maximum 512 KB).', 'ai-page-designer' ), array( 'status' => 413 ) );
		}
		$name = sanitize_file_name( wp_basename( (string) $file['name'] ) );
		if ( '.json' !== strtolower( substr( $name, -5 ) ) ) {
			return new WP_Error( 'aipd_upload_type', __( 'Only .json template files can be imported.', 'ai-page-designer' ), array( 'status' => 415 ) );
		}
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = $finfo ? finfo_file( $finfo, $file['tmp_name'] ) : '';
			if ( $finfo ) {
				finfo_close( $finfo );
			}
			if ( $mime && ! in_array( $mime, array( 'application/json', 'text/plain', 'text/json' ), true ) ) {
				return new WP_Error( 'aipd_upload_type', __( 'The file does not look like a JSON document.', 'ai-page-designer' ), array( 'status' => 415 ) );
			}
		}
		$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading an uploaded temp file.
		return self::import_json( (string) $raw );
	}

	/**
	 * Imports JSON text.
	 *
	 * @param string $raw JSON.
	 * @return int|WP_Error
	 */
	public static function import_json( $raw ) {
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new WP_Error( 'aipd_upload_too_large', __( 'The template file is too large (maximum 512 KB).', 'ai-page-designer' ), array( 'status' => 413 ) );
		}
		$data = json_decode( $raw, true, 64 );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'aipd_invalid_json', __( 'The file is not valid JSON.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}
		$schema = ( isset( $data['format'] ) && 'ai-page-designer-template' === $data['format'] && isset( $data['schema'] ) ) ? $data['schema'] : $data;
		$title  = isset( $data['title'] ) && is_string( $data['title'] ) ? $data['title'] : '';
		return self::save( $title, $schema );
	}

	/**
	 * Runs the full schema pipeline.
	 *
	 * @param mixed $data Data.
	 * @return array|WP_Error
	 */
	private static function validate( $data ) {
		$result = SchemaService::from_client( $data );
		return is_wp_error( $result ) ? $result : $result['schema'];
	}

	/**
	 * Decodes stored template meta.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	private static function decode_meta( $post_id ) {
		$raw  = get_post_meta( $post_id, self::META, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : null;
	}
}
