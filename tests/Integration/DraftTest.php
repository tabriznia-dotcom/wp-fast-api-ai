<?php
/**
 * Draft creation and section replacement across adapters.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests\Integration;

use AIPageDesigner\Core\Capabilities;
use AIPageDesigner\Core\Frontend;
use AIPageDesigner\PageBuilders\AbstractAdapter;
use AIPageDesigner\PageBuilders\Classic\ClassicAdapter;
use AIPageDesigner\PageBuilders\Gutenberg\GutenbergAdapter;
use AIPageDesigner\Schema\SchemaService;
use AIPageDesigner\Tests\TestCase;

/**
 * @covers \AIPageDesigner\PageBuilders\AbstractAdapter
 */
class DraftTest extends TestCase {

	private function schema( $name = 'landing-page-en' ) {
		return SchemaService::process( $this->template( $name ) )['schema'];
	}

	public function test_gutenberg_draft_is_draft_with_valid_blocks_and_meta() {
		$this->login_admin();
		$post_id = ( new GutenbergAdapter() )->create_draft( $this->schema() );
		$this->assertIsInt( $post_id );
		$post = get_post( $post_id );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'page', $post->post_type );
		$this->assertTrue( has_blocks( $post->post_content ) );
		$blocks = parse_blocks( $post->post_content );
		$this->assertSame( 'core/group', $blocks[0]['blockName'] );
		$this->assertSame( 'gutenberg', get_post_meta( $post_id, AbstractAdapter::META_BUILDER, true ) );
		$this->assertIsArray( AbstractAdapter::stored_schema( $post_id ) );
		$this->assertStringContainsString( '.aipd-page', get_post_meta( $post_id, AbstractAdapter::META_CSS, true ) );
	}

	public function test_content_survives_kses_for_users_without_unfiltered_html() {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $user )->add_cap( Capabilities::GENERATE );
		wp_set_current_user( $user );
		$deny = static function ( $caps, $cap ) {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		kses_init_filters();

		$adapter = new GutenbergAdapter();
		foreach ( array( 'landing-page-en', 'landing-page-fa' ) as $name ) {
			$schema   = $this->schema( $name );
			$expected = $adapter->render_page( $schema );
			$post_id  = $adapter->create_draft( $schema );
			$this->assertIsInt( $post_id );
			$this->assertSame( $expected, get_post( $post_id )->post_content, $name . ' markup must not be altered by kses' );
		}
		kses_remove_filters();
		remove_filter( 'map_meta_cap', $deny, 10 );
	}

	public function test_drafts_are_never_published_even_if_requested() {
		$this->login_admin();
		add_filter(
			'aipd_before_create_draft',
			static function ( $args ) {
				$args['post_status'] = 'publish';
				return $args;
			}
		);
		$post_id = ( new GutenbergAdapter() )->create_draft( $this->schema() );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	public function test_user_without_page_capability_cannot_create_draft() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$result = ( new GutenbergAdapter() )->create_draft( $this->schema() );
		$this->assertWPError( $result );
		$this->assertSame( 'aipd_forbidden', $result->get_error_code() );
	}

	public function test_unsupported_post_type_is_rejected() {
		$this->login_admin();
		$result = ( new GutenbergAdapter() )->create_draft( $this->schema(), array( 'post_type' => 'attachment' ) );
		$this->assertWPError( $result );
	}

	public function test_after_create_draft_hook_fires() {
		$this->login_admin();
		$fired = 0;
		add_action(
			'aipd_after_create_draft',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		( new GutenbergAdapter() )->create_draft( $this->schema() );
		$this->assertSame( 1, $fired );
	}

	public function test_gutenberg_section_update_creates_revision_and_keeps_other_sections() {
		$this->login_admin();
		$adapter = new GutenbergAdapter();
		$schema  = $this->schema();
		$post_id = $adapter->create_draft( $schema );

		// Simulate a user edit in another section.
		$content = str_replace( 'Frequently asked questions</h2>', 'Questions edited by the user</h2>', get_post( $post_id )->post_content );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			)
		);
		$revisions_before = count( wp_get_post_revisions( $post_id ) );

		$schema['sections'][0]['columns'][0]['components'][0]['text'] = 'A brand new hero title';
		$this->assertTrue( $adapter->update_section( $post_id, $schema, 'hero' ) );

		$updated = get_post( $post_id )->post_content;
		$this->assertStringContainsString( 'A brand new hero title', $updated );
		$this->assertStringContainsString( 'Questions edited by the user', $updated, 'Other sections keep user edits' );
		$this->assertGreaterThan( $revisions_before, count( wp_get_post_revisions( $post_id ) ) );
		$this->assertWPError( $adapter->update_section( $post_id, $schema, 'missing' ) );
	}

	public function test_classic_adapter_html_and_section_update() {
		$this->login_admin();
		$adapter = new ClassicAdapter();
		$schema  = $this->schema( 'landing-page-fa' );
		$post_id = $adapter->create_draft( $schema );
		$content = get_post( $post_id )->post_content;
		$this->assertStringContainsString( 'dir="rtl"', $content );
		$this->assertStringContainsString( '<!-- aipd:section hero -->', $content );
		$this->assertStringNotContainsString( '<script', $content );

		$schema['sections'][0]['columns'][0]['components'][0]['text'] = 'عنوان تازه';
		$this->assertTrue( $adapter->update_section( $post_id, $schema, 'hero' ) );
		$this->assertStringContainsString( 'عنوان تازه', get_post( $post_id )->post_content );
	}

	public function test_builder_mismatch_is_rejected() {
		$this->login_admin();
		$post_id = ( new ClassicAdapter() )->create_draft( $this->schema() );
		$this->assertWPError( ( new GutenbergAdapter() )->update_section( $post_id, $this->schema(), 'hero' ) );
	}

	public function test_frontend_css_only_on_generated_pages_and_survives_theme_switch() {
		$this->login_admin();
		$post_id = ( new GutenbergAdapter() )->create_draft( $this->schema() );
		$other   = self::factory()->post->create();

		$this->go_to( get_permalink( $other ) );
		Frontend::enqueue();
		$this->assertFalse( wp_style_is( 'aipd-page', 'enqueued' ) );

		switch_theme( 'default' );
		$this->go_to( add_query_arg( array( 'page_id' => $post_id, 'preview' => 'true' ), home_url( '/' ) ) );
		Frontend::enqueue();
		$this->assertTrue( wp_style_is( 'aipd-page', 'enqueued' ) );
		$this->assertTrue( has_blocks( get_post( $post_id )->post_content ), 'Content is theme independent' );
	}

	public function test_rendered_blocks_output_on_frontend() {
		$this->login_admin();
		$post_id = ( new GutenbergAdapter() )->create_draft( $this->schema( 'landing-page-fa' ) );
		$html    = do_blocks( get_post( $post_id )->post_content );
		$this->assertStringContainsString( '<h1', $html );
		$this->assertStringContainsString( 'id="hero"', $html );
		$this->assertStringContainsString( 'aipd-dir-rtl', $html );
		$this->assertSame( 1, substr_count( $html, '<h1' ) );
	}
}
