<?php
/**
 * Admin screen renderers.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Admin;

use AIPageDesigner\Core\Capabilities;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Core\Plugin;
use AIPageDesigner\Integrations\Multilingual;
use AIPageDesigner\Jobs\JobRepository;
use AIPageDesigner\Logging\Logger;
use AIPageDesigner\Privacy\Privacy;
use AIPageDesigner\Schema\DesignTokens;
use AIPageDesigner\Security\UrlValidator;
use AIPageDesigner\Templates\TemplateRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered screens following core admin patterns. Every value is escaped late.
 */
final class Pages {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Opens a screen.
	 *
	 * @param string $title Title.
	 * @param string $cap   Capability.
	 * @return bool False when the user lacks access.
	 */
	private function open( $title, $cap ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ai-page-designer' ), 403 );
		}
		echo '<div class="wrap aipd-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		echo '<hr class="wp-header-end">';
		$this->notices();
		return true;
	}

	/**
	 * Closes a screen.
	 *
	 * @return void
	 */
	private function close() {
		echo '</div>';
	}

	/**
	 * Result notices after form submissions (message codes only; never user content).
	 *
	 * @return void
	 */
	private function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$code = isset( $_GET['aipd_notice'] ) ? sanitize_key( wp_unslash( $_GET['aipd_notice'] ) ) : '';
		$type = isset( $_GET['aipd_type'] ) && 'error' === $_GET['aipd_type'] ? 'error' : 'success';
		// phpcs:enable
		if ( '' === $code ) {
			return;
		}
		$messages = array(
			'saved'             => __( 'Settings saved.', 'ai-page-designer' ),
			'provider_saved'    => __( 'Provider settings saved.', 'ai-page-designer' ),
			'invalid_url'       => __( 'The API URL is not valid or points to a blocked address. Settings were not saved.', 'ai-page-designer' ),
			'invalid_input'     => __( 'Some values were not valid. Settings were not saved.', 'ai-page-designer' ),
			'imported'          => __( 'Template imported.', 'ai-page-designer' ),
			'import_failed'     => __( 'The template could not be imported. Check that it is a valid AI Page Designer JSON file under 512 KB.', 'ai-page-designer' ),
			'deleted'           => __( 'Deleted.', 'ai-page-designer' ),
			'logs_cleared'      => __( 'Logs cleared.', 'ai-page-designer' ),
			'settings_imported' => __( 'Settings imported. API keys are never included in exports and were not changed.', 'ai-page-designer' ),
			'privacy_saved'     => __( 'Privacy settings saved.', 'ai-page-designer' ),
			'forbidden'         => __( 'You are not allowed to do that.', 'ai-page-designer' ),
		);
		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $messages[ $code ] ) );
		}
	}

	/**
	 * Hidden fields for an admin-post form.
	 *
	 * @param string $action Action.
	 * @return void
	 */
	private static function form_fields( $action ) {
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
	}

	/**
	 * Dashboard.
	 *
	 * @return void
	 */
	public function dashboard() {
		$this->open( __( 'AI Page Designer', 'ai-page-designer' ), Capabilities::GENERATE );
		$state    = Admin::setup_state( $this->plugin );
		$provider = $this->plugin->providers->active();
		$recent   = JobRepository::query( Capabilities::can_manage() ? 0 : get_current_user_id(), 1, 5 );

		echo '<p class="aipd-lead">' . esc_html__( 'Describe the page you need, review a proposed structure, preview it and create a draft for the block editor, Elementor or the classic editor. Nothing is published automatically.', 'ai-page-designer' ) . '</p>';

		echo '<div class="aipd-grid">';

		echo '<section class="aipd-panel" aria-labelledby="aipd-setup-title"><h2 id="aipd-setup-title">' . esc_html__( 'Setup', 'ai-page-designer' ) . '</h2><ul class="aipd-checklist">';
		$this->check_item( $state['privacy'], __( 'Data sharing notice reviewed', 'ai-page-designer' ), Admin::url( 'privacy' ) );
		$this->check_item( $state['provider'], $provider ? sprintf( /* translators: %s: provider name. */ __( 'AI provider configured: %s', 'ai-page-designer' ), $provider->get_name() ) : __( 'AI provider configured', 'ai-page-designer' ), Admin::url( 'providers' ) );
		echo '</ul>';
		if ( $state['privacy'] && $state['provider'] ) {
			echo '<p><a class="button button-primary" href="' . esc_url( Admin::url( 'wizard' ) ) . '">' . esc_html__( 'Create a new page', 'ai-page-designer' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'You can already start from a template without an AI provider.', 'ai-page-designer' ) . ' <a href="' . esc_url( Admin::url( 'templates' ) ) . '">' . esc_html__( 'Browse templates', 'ai-page-designer' ) . '</a></p>';
		}
		echo '</section>';

		echo '<section class="aipd-panel" aria-labelledby="aipd-builders-title"><h2 id="aipd-builders-title">' . esc_html__( 'Page builders', 'ai-page-designer' ) . '</h2><ul class="aipd-checklist">';
		foreach ( $this->plugin->builders->all() as $adapter ) {
			$this->check_item( $adapter->is_available(), $adapter->get_name() . ( $adapter->is_available() ? '' : ' — ' . __( 'not active', 'ai-page-designer' ) ), '' );
		}
		$ml = Multilingual::active_plugin();
		if ( $ml ) {
			/* translators: %s: multilingual plugin name. */
			echo '<li>' . esc_html( sprintf( __( 'Multilingual integration: %s', 'ai-page-designer' ), $ml ) ) . '</li>';
		}
		echo '</ul></section>';

		echo '<section class="aipd-panel aipd-panel--wide" aria-labelledby="aipd-recent-title"><h2 id="aipd-recent-title">' . esc_html__( 'Recent requests', 'ai-page-designer' ) . '</h2>';
		if ( empty( $recent['items'] ) ) {
			echo '<p class="aipd-empty">' . esc_html__( 'No requests yet. Your generation history will appear here.', 'ai-page-designer' ) . '</p>';
		} else {
			$this->jobs_table( $recent['items'], false );
			echo '<p><a href="' . esc_url( Admin::url( 'history' ) ) . '">' . esc_html__( 'View all history', 'ai-page-designer' ) . '</a></p>';
		}
		echo '</section>';

		echo '</div>';
		$this->close();
	}

	/**
	 * Checklist item.
	 *
	 * @param bool   $done  Done.
	 * @param string $label Label.
	 * @param string $url   Fix URL.
	 * @return void
	 */
	private function check_item( $done, $label, $url ) {
		echo '<li class="' . ( $done ? 'is-done' : 'is-todo' ) . '"><span class="dashicons ' . ( $done ? 'dashicons-yes-alt' : 'dashicons-marker' ) . '" aria-hidden="true"></span> ';
		echo '<span class="screen-reader-text">' . esc_html( $done ? __( 'Done:', 'ai-page-designer' ) : __( 'To do:', 'ai-page-designer' ) ) . '</span> ' . esc_html( $label );
		if ( ! $done && $url ) {
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Fix', 'ai-page-designer' ) . '</a>';
		}
		echo '</li>';
	}

	/**
	 * Wizard mount point (React app).
	 *
	 * @return void
	 */
	public function wizard() {
		$this->open( __( 'New Page', 'ai-page-designer' ), Capabilities::GENERATE );
		echo '<div id="aipd-wizard-root" class="aipd-wizard-root"><p class="aipd-loading">' . esc_html__( 'Loading the page wizard…', 'ai-page-designer' ) . '</p></div>';
		echo '<noscript><div class="notice notice-error"><p>' . esc_html__( 'The page wizard requires JavaScript.', 'ai-page-designer' ) . '</p></div></noscript>';
		$this->close();
	}

	/**
	 * Templates list.
	 *
	 * @return void
	 */
	public function templates() {
		$this->open( __( 'Templates', 'ai-page-designer' ), Capabilities::GENERATE );
		echo '<p>' . esc_html__( 'Templates are validated page structures. Start a page from a template without sending anything to an AI service, or export them to share between sites.', 'ai-page-designer' ) . '</p>';
		$templates = TemplateRepository::all();
		echo '<table class="widefat striped aipd-table"><thead><tr><th scope="col">' . esc_html__( 'Template', 'ai-page-designer' ) . '</th><th scope="col">' . esc_html__( 'Language', 'ai-page-designer' ) . '</th><th scope="col">' . esc_html__( 'Direction', 'ai-page-designer' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'ai-page-designer' ) . '</th></tr></thead><tbody>';
		foreach ( $templates as $template ) {
			echo '<tr><td><strong>' . esc_html( $template['title'] ) . '</strong>' . ( $template['builtin'] ? ' <span class="aipd-badge">' . esc_html__( 'Built-in', 'ai-page-designer' ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( $template['language'] ) . '</td><td>' . esc_html( strtoupper( $template['direction'] ) ) . '</td><td class="aipd-actions">';
			echo '<a class="button button-primary" href="' . esc_url( Admin::url( 'wizard', array( 'template' => $template['id'] ) ) ) . '">' . esc_html__( 'Use template', 'ai-page-designer' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipd_export_template&template=' . rawurlencode( $template['id'] ) ), 'aipd_export_template' ) ) . '">' . esc_html__( 'Export JSON', 'ai-page-designer' ) . '</a> ';
			if ( ! $template['builtin'] && Capabilities::can_manage() ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aipd-inline-form">';
				self::form_fields( 'aipd_delete_template' );
				echo '<input type="hidden" name="template" value="' . esc_attr( $template['id'] ) . '">';
				/* translators: %s: template title. */
				echo '<button type="submit" class="button button-link-delete" aria-label="' . esc_attr( sprintf( __( 'Delete template %s', 'ai-page-designer' ), $template['title'] ) ) . '">' . esc_html__( 'Delete', 'ai-page-designer' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( Capabilities::can_manage() ) {
			echo '<p><a href="' . esc_url( Admin::url( 'tools' ) ) . '">' . esc_html__( 'Import a template', 'ai-page-designer' ) . '</a></p>';
		}
		$this->close();
	}

	/**
	 * Brand kit form.
	 *
	 * @return void
	 */
	public function brand_kit() {
		$this->open( __( 'Brand Kit', 'ai-page-designer' ), Capabilities::MANAGE );
		$kit   = Options::brand_kit();
		$fonts = DesignTokens::fonts();

		echo '<p>' . esc_html__( 'Defaults applied to every new page. Colors are checked for WCAG AA contrast when a page is generated. Fonts are never downloaded: choose a system font stack or a font your theme already provides.', 'ai-page-designer' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aipd-form" data-aipd-dirty-check>';
		self::form_fields( 'aipd_save_brand_kit' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="aipd-brand-name">' . esc_html__( 'Brand name', 'ai-page-designer' ) . '</label></th><td><input type="text" class="regular-text" id="aipd-brand-name" name="brand_name" value="' . esc_attr( $kit['brand_name'] ) . '" maxlength="100"></td></tr>';

		echo '<tr><th scope="row"><label for="aipd-logo">' . esc_html__( 'Logo attachment ID', 'ai-page-designer' ) . '</label></th><td><input type="number" min="0" class="small-text" id="aipd-logo" name="logo_id" value="' . esc_attr( (string) $kit['logo_id'] ) . '">';
		if ( $kit['logo_id'] && wp_attachment_is_image( $kit['logo_id'] ) ) {
			echo '<div class="aipd-logo-preview">' . wp_get_attachment_image( $kit['logo_id'], 'thumbnail' ) . '</div>';
		}
		echo '<p class="description">' . esc_html__( 'Optional. Use an image from your Media Library. It is never sent to the AI service.', 'ai-page-designer' ) . '</p></td></tr>';

		$labels = array(
			'primary'    => __( 'Primary color', 'ai-page-designer' ),
			'secondary'  => __( 'Secondary color', 'ai-page-designer' ),
			'accent'     => __( 'Accent color', 'ai-page-designer' ),
			'text'       => __( 'Text color', 'ai-page-designer' ),
			'background' => __( 'Background color', 'ai-page-designer' ),
		);
		foreach ( $labels as $key => $label ) {
			echo '<tr><th scope="row"><label for="aipd-color-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="color" id="aipd-color-' . esc_attr( $key ) . '" name="colors[' . esc_attr( $key ) . ']" value="' . esc_attr( $kit['colors'][ $key ] ) . '"> <code>' . esc_html( $kit['colors'][ $key ] ) . '</code></td></tr>';
		}

		foreach ( array(
			'font_heading' => __( 'Heading font', 'ai-page-designer' ),
			'font_body'    => __( 'Body font', 'ai-page-designer' ),
		) as $key => $label ) {
			echo '<tr><th scope="row"><label for="aipd-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="aipd-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
			foreach ( $fonts as $id => $font ) {
				echo '<option value="' . esc_attr( $id ) . '"' . selected( $kit[ $key ], $id, false ) . '>' . esc_html( $font['label'] ) . '</option>';
			}
			echo '</select></td></tr>';
		}

		echo '<tr><th scope="row"><label for="aipd-style">' . esc_html__( 'Design style', 'ai-page-designer' ) . '</label></th><td><select id="aipd-style" name="style">';
		foreach ( DesignTokens::style_labels() as $id => $label ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $kit['style'], $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="aipd-tone">' . esc_html__( 'Default tone', 'ai-page-designer' ) . '</label></th><td><select id="aipd-tone" name="tone">';
		foreach ( self::tones() as $id => $label ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $kit['tone'], $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="aipd-language">' . esc_html__( 'Default content language', 'ai-page-designer' ) . '</label></th><td><input type="text" class="small-text" id="aipd-language" name="language" value="' . esc_attr( $kit['language'] ) . '" placeholder="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '" pattern="[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*"><p class="description">' . esc_html__( 'Language code such as en, fa or ar-EG. The content language can differ from your admin language.', 'ai-page-designer' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save brand kit', 'ai-page-designer' ) );
		echo '</form>';
		$this->close();
	}

	/**
	 * Tone labels.
	 *
	 * @return array<string,string>
	 */
	public static function tones() {
		return array(
			'professional' => __( 'Professional', 'ai-page-designer' ),
			'friendly'     => __( 'Friendly', 'ai-page-designer' ),
			'bold'         => __( 'Bold', 'ai-page-designer' ),
			'luxurious'    => __( 'Luxurious', 'ai-page-designer' ),
			'playful'      => __( 'Playful', 'ai-page-designer' ),
			'technical'    => __( 'Technical', 'ai-page-designer' ),
			'warm'         => __( 'Warm', 'ai-page-designer' ),
			'formal'       => __( 'Formal', 'ai-page-designer' ),
		);
	}

	/**
	 * History with pagination.
	 *
	 * @return void
	 */
	public function history() {
		$this->open( __( 'Generation History', 'ai-page-designer' ), Capabilities::GENERATE );
		if ( ! Options::get( 'history_enabled' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'History is disabled. Briefs and results are deleted as soon as each request finishes.', 'ai-page-designer' ) . '</p></div>';
		}
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination.
		$result = JobRepository::query( Capabilities::can_manage() ? 0 : get_current_user_id(), $page, 20 );
		if ( empty( $result['items'] ) ) {
			echo '<p class="aipd-empty">' . esc_html__( 'No requests yet.', 'ai-page-designer' ) . '</p>';
		} else {
			$this->jobs_table( $result['items'], true );
			$this->pagination( $result['total'], $page, 20 );
		}
		$this->close();
	}

	/**
	 * Jobs table.
	 *
	 * @param array $items       Rows.
	 * @param bool  $with_delete Show delete buttons.
	 * @return void
	 */
	private function jobs_table( array $items, $with_delete ) {
		$types    = array(
			'outline' => __( 'Structure', 'ai-page-designer' ),
			'page'    => __( 'Page content', 'ai-page-designer' ),
			'section' => __( 'Section', 'ai-page-designer' ),
		);
		$statuses = array(
			'queued'    => __( 'Queued', 'ai-page-designer' ),
			'running'   => __( 'Running', 'ai-page-designer' ),
			'completed' => __( 'Completed', 'ai-page-designer' ),
			'failed'    => __( 'Failed', 'ai-page-designer' ),
		);
		echo '<table class="widefat striped aipd-table"><thead><tr>';
		foreach ( array( __( 'Date', 'ai-page-designer' ), __( 'Title', 'ai-page-designer' ), __( 'Type', 'ai-page-designer' ), __( 'Status', 'ai-page-designer' ), __( 'Tokens', 'ai-page-designer' ), __( 'Draft', 'ai-page-designer' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		if ( $with_delete ) {
			echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'ai-page-designer' ) . '</span></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $items as $row ) {
			$date = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( $row['created_at'] ) );
			echo '<tr><td>' . esc_html( $date ) . '</td><td>' . esc_html( '' !== $row['title'] ? $row['title'] : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $types[ $row['type'] ] ) ? $types[ $row['type'] ] : $row['type'] ) . '</td>';
			echo '<td><span class="aipd-status aipd-status--' . esc_attr( $row['status'] ) . '">' . esc_html( isset( $statuses[ $row['status'] ] ) ? $statuses[ $row['status'] ] : $row['status'] ) . '</span>';
			if ( 'failed' === $row['status'] && '' !== (string) $row['error_message'] ) {
				echo '<br><small>' . esc_html( $row['error_message'] ) . '</small>';
			}
			echo '</td><td>' . esc_html( number_format_i18n( (int) $row['tokens_in'] + (int) $row['tokens_out'] ) ) . '</td><td>';
			if ( $row['post_id'] && get_post( (int) $row['post_id'] ) && current_user_can( 'edit_post', (int) $row['post_id'] ) ) {
				echo '<a href="' . esc_url( (string) get_edit_post_link( (int) $row['post_id'] ) ) . '">' . esc_html( get_the_title( (int) $row['post_id'] ) ) . '</a>';
			} else {
				echo '—';
			}
			echo '</td>';
			if ( $with_delete ) {
				echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aipd-inline-form">';
				self::form_fields( 'aipd_delete_job' );
				echo '<input type="hidden" name="job" value="' . esc_attr( $row['uuid'] ) . '"><button type="submit" class="button-link button-link-delete">' . esc_html__( 'Delete', 'ai-page-designer' ) . '</button></form></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Simple pagination links.
	 *
	 * @param int $total    Total rows.
	 * @param int $page     Current page.
	 * @param int $per_page Per page.
	 * @return void
	 */
	private function pagination( $total, $page, $per_page ) {
		$pages = (int) ceil( $total / $per_page );
		if ( $pages <= 1 ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => __( '&laquo; Previous', 'ai-page-designer' ),
				'next_text' => __( 'Next &raquo;', 'ai-page-designer' ),
			)
		);
		echo '<nav class="tablenav" aria-label="' . esc_attr__( 'Pagination', 'ai-page-designer' ) . '"><div class="tablenav-pages">' . wp_kses_post( (string) $links ) . '</div></nav>';
	}

	/**
	 * Providers screen.
	 *
	 * @return void
	 */
	public function providers() {
		$this->open( __( 'AI Providers', 'ai-page-designer' ), Capabilities::MANAGE );
		$active = (string) Options::get( 'active_provider' );

		echo '<p>' . esc_html__( 'Choose which AI service generates your pages. Requests are sent from your server only, and API keys are stored encrypted and never shown in the browser.', 'ai-page-designer' ) . '</p>';
		if ( UrlValidator::local_endpoints_allowed() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Local and private network endpoints are allowed by AIPD_ALLOW_LOCAL_ENDPOINTS. Only use this for trusted local model servers.', 'ai-page-designer' ) . '</p></div>';
		}

		foreach ( $this->plugin->providers->all() as $id => $provider ) {
			$available  = $provider->is_available();
			$configured = $available && $provider->is_configured();
			$public     = $provider->get_public_settings();
			echo '<section class="aipd-panel aipd-provider' . ( $active === $id ? ' is-active' : '' ) . '" aria-labelledby="aipd-provider-' . esc_attr( $id ) . '">';
			echo '<h2 id="aipd-provider-' . esc_attr( $id ) . '">' . esc_html( $provider->get_name() );
			if ( $active === $id ) {
				echo ' <span class="aipd-badge aipd-badge--primary">' . esc_html__( 'Active', 'ai-page-designer' ) . '</span>';
			}
			echo '</h2><p>' . esc_html( $provider->get_description() ) . '</p>';

			if ( ! $available ) {
				echo '<p class="aipd-muted">' . esc_html__( 'Not available on this site.', 'ai-page-designer' ) . '</p></section>';
				continue;
			}

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aipd-form" data-aipd-dirty-check autocomplete="off">';
			self::form_fields( 'aipd_save_provider' );
			echo '<input type="hidden" name="provider" value="' . esc_attr( $id ) . '">';
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $provider->get_settings_fields() as $field ) {
				$this->provider_field( $provider, $field, isset( $public[ $field['id'] ] ) ? $public[ $field['id'] ] : null );
			}
			echo '<tr><th scope="row">' . esc_html__( 'Use for generation', 'ai-page-designer' ) . '</th><td><label><input type="checkbox" name="make_active" value="1"' . checked( $active, $id, false ) . '> ' . esc_html__( 'Make this the active provider', 'ai-page-designer' ) . '</label></td></tr>';
			echo '</tbody></table>';
			echo '<p class="submit">';
			submit_button( __( 'Save provider', 'ai-page-designer' ), 'primary', 'submit', false );
			echo ' <button type="button" class="button aipd-test-connection" data-provider="' . esc_attr( $id ) . '">' . esc_html__( 'Test connection', 'ai-page-designer' ) . '</button>';
			echo ' <span class="aipd-test-result" role="status" aria-live="polite"></span></p>';
			echo '<p class="description">' . esc_html( $configured ? __( 'Status: configured.', 'ai-page-designer' ) : __( 'Status: not configured. No request will be sent until it is.', 'ai-page-designer' ) ) . '</p>';
			echo '</form></section>';
		}
		$this->close();
	}

	/**
	 * Renders a provider settings field.
	 *
	 * @param object $provider Provider.
	 * @param array  $field    Field definition.
	 * @param mixed  $value    Public value.
	 * @return void
	 */
	private function provider_field( $provider, array $field, $value ) {
		$id   = 'aipd-' . $provider->get_id() . '-' . $field['id'];
		$name = 'settings[' . $field['id'] . ']';
		$type = isset( $field['type'] ) ? $field['type'] : 'text';
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

		if ( ! empty( $field['secret'] ) ) {
			$from_constant = is_array( $value ) && ! empty( $value['from_constant'] );
			$is_set        = is_array( $value ) && ! empty( $value['is_set'] );
			if ( $from_constant ) {
				echo '<p><strong>' . esc_html__( 'Defined in wp-config.php.', 'ai-page-designer' ) . '</strong></p>';
			} else {
				echo '<input type="password" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr( $is_set ? __( 'A key is saved. Enter a new key to replace it.', 'ai-page-designer' ) : __( 'Enter API key', 'ai-page-designer' ) ) . '">';
				if ( $is_set ) {
					echo '<br><label><input type="checkbox" name="settings[' . esc_attr( $field['id'] ) . '_remove]" value="1"> ' . esc_html__( 'Remove the saved key', 'ai-page-designer' ) . '</label>';
				}
			}
			if ( method_exists( $provider, 'secret_constant' ) ) {
				/* translators: %s: PHP constant name. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Alternatively define %s in wp-config.php.', 'ai-page-designer' ), $provider->secret_constant( $field['id'] ) ) ) . '</p>';
			}
		} elseif ( 'checkbox' === $type ) {
			echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . '> ' . esc_html( $field['label'] ) . '</label>';
		} elseif ( 'number' === $type ) {
			printf(
				'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" step="%6$s">',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				esc_attr( (string) $field['min'] ),
				esc_attr( (string) $field['max'] ),
				esc_attr( (string) $field['step'] )
			);
		} else {
			$list = 'model' === $field['id'] ? ' list="' . esc_attr( $id ) . '-list"' : '';
			echo '<input type="' . ( 'url' === $type ? 'url' : 'text' ) . '" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . $list . ' spellcheck="false">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $list is escaped above.
			if ( $list ) {
				echo '<datalist id="' . esc_attr( $id ) . '-list"></datalist>';
			}
		}
		if ( ! empty( $field['description'] ) ) {
			echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * General API settings.
	 *
	 * @return void
	 */
	public function settings() {
		$this->open( __( 'API Settings', 'ai-page-designer' ), Capabilities::MANAGE );
		$s = Options::settings();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aipd-form" data-aipd-dirty-check>';
		self::form_fields( 'aipd_save_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Cost confirmation', 'ai-page-designer' ) . '</th><td><label><input type="checkbox" name="confirm_paid_requests" value="1"' . checked( $s['confirm_paid_requests'], true, false ) . '> ' . esc_html__( 'Ask for confirmation before every request to a paid provider', 'ai-page-designer' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="aipd-rate">' . esc_html__( 'Requests per user per hour', 'ai-page-designer' ) . '</label></th><td><input type="number" min="1" max="1000" class="small-text" id="aipd-rate" name="rate_limit_per_hour" value="' . esc_attr( (string) $s['rate_limit_per_hour'] ) . '"><p class="description">' . esc_html__( 'Protects your AI budget from accidental repeated requests.', 'ai-page-designer' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="aipd-builder">' . esc_html__( 'Default page builder', 'ai-page-designer' ) . '</label></th><td><select id="aipd-builder" name="default_builder">';
		foreach ( $this->plugin->builders->all() as $id => $adapter ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $s['default_builder'], $id, false ) . '>' . esc_html( $adapter->get_name() . ( $adapter->is_available() ? '' : ' (' . __( 'not active', 'ai-page-designer' ) . ')' ) ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Logging', 'ai-page-designer' ) . '</th><td><label><input type="checkbox" name="logs_enabled" value="1"' . checked( $s['logs_enabled'], true, false ) . '> ' . esc_html__( 'Keep operational logs (never contains keys, prompts or generated text)', 'ai-page-designer' ) . '</label><br><label for="aipd-level">' . esc_html__( 'Minimum level', 'ai-page-designer' ) . '</label> <select id="aipd-level" name="log_level">';
		foreach ( array(
			'debug'   => __( 'Debug', 'ai-page-designer' ),
			'info'    => __( 'Info', 'ai-page-designer' ),
			'warning' => __( 'Warning', 'ai-page-designer' ),
			'error'   => __( 'Error', 'ai-page-designer' ),
		) as $level => $label ) {
			echo '<option value="' . esc_attr( $level ) . '"' . selected( $s['log_level'], $level, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
		$this->close();
	}

	/**
	 * Logs with pagination.
	 *
	 * @return void
	 */
	public function logs() {
		$this->open( __( 'Logs', 'ai-page-designer' ), Capabilities::MANAGE );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filtering and pagination.
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		// phpcs:enable
		if ( ! Options::get( 'logs_enabled' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Logging is disabled in API Settings.', 'ai-page-designer' ) . '</p></div>';
		}
		echo '<form method="get" class="aipd-filter"><input type="hidden" name="page" value="aipd-logs"><label for="aipd-log-level">' . esc_html__( 'Level', 'ai-page-designer' ) . '</label> <select id="aipd-log-level" name="level"><option value="">' . esc_html__( 'All', 'ai-page-designer' ) . '</option>';
		foreach ( array_keys( Logger::LEVELS ) as $l ) {
			echo '<option value="' . esc_attr( $l ) . '"' . selected( $level, $l, false ) . '>' . esc_html( ucfirst( $l ) ) . '</option>';
		}
		echo '</select> <button class="button">' . esc_html__( 'Filter', 'ai-page-designer' ) . '</button></form>';

		$result = Logger::query( $page, 30, $level );
		if ( empty( $result['items'] ) ) {
			echo '<p class="aipd-empty">' . esc_html__( 'No log entries.', 'ai-page-designer' ) . '</p>';
		} else {
			echo '<table class="widefat striped aipd-table"><thead><tr><th scope="col">' . esc_html__( 'Date', 'ai-page-designer' ) . '</th><th scope="col">' . esc_html__( 'Level', 'ai-page-designer' ) . '</th><th scope="col">' . esc_html__( 'Event', 'ai-page-designer' ) . '</th><th scope="col">' . esc_html__( 'Message', 'ai-page-designer' ) . '</th><th scope="col">' . esc_html__( 'Details', 'ai-page-designer' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $row ) {
				echo '<tr><td>' . esc_html( get_date_from_gmt( $row['created_at'] ) ) . '</td><td><span class="aipd-status aipd-status--' . esc_attr( $row['level'] ) . '">' . esc_html( $row['level'] ) . '</span></td><td><code>' . esc_html( $row['event'] ) . '</code></td><td>' . esc_html( $row['message'] ) . '</td><td><code class="aipd-context">' . esc_html( (string) $row['context'] ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
			$this->pagination( $result['total'], $page, 30 );
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'aipd_clear_logs' );
		submit_button( __( 'Clear all logs', 'ai-page-designer' ), 'delete', 'submit', false );
		echo '</form>';
		$this->close();
	}

	/**
	 * Privacy screen.
	 *
	 * @return void
	 */
	public function privacy() {
		$this->open( __( 'Privacy', 'ai-page-designer' ), Capabilities::MANAGE );
		$s        = Options::settings();
		$provider = $this->plugin->providers->active();
		$info     = $provider ? $provider->get_privacy_info() : null;

		echo '<section class="aipd-panel"><h2>' . esc_html__( 'What is sent to the AI service', 'ai-page-designer' ) . '</h2>';
		if ( $info ) {
			/* translators: %s: service name or host. */
			echo '<p>' . esc_html( sprintf( __( 'Requests go to %s.', 'ai-page-designer' ), $info['service'] ) ) . ' ' . esc_html( $info['data_sent'] ) . '</p>';
			if ( $info['terms_url'] || $info['privacy_url'] ) {
				echo '<p>';
				if ( $info['terms_url'] ) {
					echo '<a href="' . esc_url( $info['terms_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of use', 'ai-page-designer' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'ai-page-designer' ) . '</span></a> ';
				}
				if ( $info['privacy_url'] ) {
					echo '<a href="' . esc_url( $info['privacy_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy policy', 'ai-page-designer' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'ai-page-designer' ) . '</span></a>';
				}
				echo '</p>';
			}
		}
		echo '<ul class="ul-disc"><li>' . esc_html__( 'Your site content, users, orders or other data are never sent unless you type them into the brief.', 'ai-page-designer' ) . '</li><li>' . esc_html__( 'API keys are sent only to the configured API URL in an Authorization header.', 'ai-page-designer' ) . '</li><li>' . esc_html__( 'The plugin does not track visitors and sends no telemetry.', 'ai-page-designer' ) . '</li></ul>';
		echo '<details><summary>' . esc_html__( 'Suggested privacy policy text', 'ai-page-designer' ) . '</summary>' . wp_kses_post( wpautop( Privacy::policy_text() ) ) . '</details></section>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aipd-form" data-aipd-dirty-check>';
		self::form_fields( 'aipd_save_privacy' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Consent', 'ai-page-designer' ) . '</th><td><label><input type="checkbox" name="privacy_acknowledged" value="1"' . checked( $s['privacy_acknowledged'], true, false ) . '> ' . esc_html__( 'I understand that briefs are sent to the configured AI service and I am allowed to share this data with it.', 'ai-page-designer' ) . '</label>';
		if ( $s['privacy_acknowledged'] && $s['privacy_acknowledged_at'] ) {
			/* translators: %s: date. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Accepted on %s.', 'ai-page-designer' ), $s['privacy_acknowledged_at'] ) ) . '</p>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Generation history', 'ai-page-designer' ) . '</th><td><label><input type="checkbox" name="history_enabled" value="1"' . checked( $s['history_enabled'], true, false ) . '> ' . esc_html__( 'Store briefs and results so users can revisit them', 'ai-page-designer' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="aipd-retention">' . esc_html__( 'Retention (days)', 'ai-page-designer' ) . '</label></th><td><input type="number" min="1" max="365" class="small-text" id="aipd-retention" name="retention_days" value="' . esc_attr( (string) $s['retention_days'] ) . '"><p class="description">' . esc_html__( 'History and logs older than this are deleted daily.', 'ai-page-designer' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Uninstall', 'ai-page-designer' ) . '</th><td><label><input type="checkbox" name="delete_data_on_uninstall" value="1"' . checked( $s['delete_data_on_uninstall'], true, false ) . '> ' . esc_html__( 'Delete all plugin data (settings, keys, history, logs, saved templates) when the plugin is deleted', 'ai-page-designer' ) . '</label><p class="description">' . esc_html__( 'Pages you created are your content and are never deleted.', 'ai-page-designer' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
		$this->close();
	}

	/**
	 * Import/Export screen.
	 *
	 * @return void
	 */
	public function tools() {
		$this->open( __( 'Import/Export', 'ai-page-designer' ), Capabilities::MANAGE );

		echo '<section class="aipd-panel"><h2>' . esc_html__( 'Import a template', 'ai-page-designer' ) . '</h2><p>' . esc_html__( 'Upload a .json template exported from AI Page Designer (maximum 512 KB). Every value is validated before it is saved.', 'ai-page-designer' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'aipd_import_template' );
		echo '<label for="aipd-template-file" class="screen-reader-text">' . esc_html__( 'Template file', 'ai-page-designer' ) . '</label><input type="file" id="aipd-template-file" name="template_file" accept=".json,application/json" required> ';
		submit_button( __( 'Import template', 'ai-page-designer' ), 'secondary', 'submit', false );
		echo '</form></section>';

		echo '<section class="aipd-panel"><h2>' . esc_html__( 'Settings', 'ai-page-designer' ) . '</h2><p>' . esc_html__( 'Export general settings, brand kit and provider options to reuse on another site. API keys are never exported.', 'ai-page-designer' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipd_export_settings' ), 'aipd_export_settings' ) ) . '">' . esc_html__( 'Export settings', 'ai-page-designer' ) . '</a></p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'aipd_import_settings' );
		echo '<label for="aipd-settings-file" class="screen-reader-text">' . esc_html__( 'Settings file', 'ai-page-designer' ) . '</label><input type="file" id="aipd-settings-file" name="settings_file" accept=".json,application/json" required> ';
		submit_button( __( 'Import settings', 'ai-page-designer' ), 'secondary', 'submit', false );
		echo '</form></section>';
		$this->close();
	}

	/**
	 * Help screen.
	 *
	 * @return void
	 */
	public function help() {
		$this->open( __( 'Help', 'ai-page-designer' ), Capabilities::GENERATE );
		$items = array(
			__( 'Getting started', 'ai-page-designer' )   => array(
				__( 'Review the data sharing notice under Privacy.', 'ai-page-designer' ),
				__( 'Configure a provider under AI Providers and use "Test connection".', 'ai-page-designer' ),
				__( 'Open New Page, describe your page and follow the steps. You can edit the proposed structure before any content is generated.', 'ai-page-designer' ),
				__( 'The result is always saved as a draft. Review it in the editor and publish when you are ready.', 'ai-page-designer' ),
			),
			__( 'Languages and RTL', 'ai-page-designer' ) => array(
				__( 'The content language is chosen per page and can differ from your admin language. Right-to-left languages such as Persian, Arabic and Hebrew automatically use RTL layout.', 'ai-page-designer' ),
				__( 'If WPML or Polylang is active, the draft is assigned to the matching language when it exists.', 'ai-page-designer' ),
			),
			__( 'Page builders', 'ai-page-designer' )     => array(
				__( 'Block editor output uses only core blocks, so it stays editable if this plugin is deactivated.', 'ai-page-designer' ),
				__( 'Elementor output uses standard widgets. Elementor Pro widgets (forms, price tables) are used only when Elementor Pro is active.', 'ai-page-designer' ),
				__( 'Other builders can use the Classic/HTML output. Dedicated adapters can be added by developers.', 'ai-page-designer' ),
			),
			__( 'Troubleshooting', 'ai-page-designer' )   => array(
				__( 'Timeouts: increase the timeout in AI Providers or reduce the number of sections.', 'ai-page-designer' ),
				__( 'Cut-off responses: increase the maximum output tokens.', 'ai-page-designer' ),
				__( 'Blocked API URL: the URL must be public HTTPS. Local model servers require AIPD_ALLOW_LOCAL_ENDPOINTS in wp-config.php.', 'ai-page-designer' ),
				__( 'See Logs for error codes. Logs never include keys or content.', 'ai-page-designer' ),
			),
		);
		foreach ( $items as $title => $list ) {
			echo '<section class="aipd-panel"><h2>' . esc_html( $title ) . '</h2><ul class="ul-disc">';
			foreach ( $list as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul></section>';
		}
		$this->close();
	}
}
