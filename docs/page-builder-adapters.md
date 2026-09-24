# Adding a page builder adapter

Adapters convert a **trusted** Page Schema (already sanitized and validated) into a builder's native format. They implement `AIPageDesigner\PageBuilders\Contracts\PageBuilderAdapterInterface`. Extending `AIPageDesigner\PageBuilders\AbstractAdapter` gives you capability checks, draft creation (always `draft` status), schema/CSS meta, page template selection and the `aipd_before_create_draft` / `aipd_after_create_draft` hooks.

## Rules

* `is_available()` must never trigger a fatal error. Check `class_exists()`/`did_action()` before touching the builder's classes, and reference them only inside methods.
* Use the builder's **public, documented** APIs. Never copy the builder's code or assets.
* Only use premium features after confirming the premium plugin is active, and provide a fallback.
* Escape all output. Plain text fields should go through `Kses::text()` (escapes and neutralizes shortcodes) and inline HTML fields through `Kses::html()`.
* Use `Palette::section()` and `Palette::button()` for colors so every adapter meets the same contrast guarantees.
* Map `start`/`end` alignment to physical values according to `$schema['meta']['direction']`.
* Implement `update_section()` so users can replace one section without losing edits elsewhere; identify sections by `$section['id']`.

## Skeleton

```php
use AIPageDesigner\PageBuilders\AbstractAdapter;
use AIPageDesigner\Schema\PageSchema;

class My_Builder_Adapter extends AbstractAdapter {

	public function get_id() {
		return 'my_builder';
	}

	public function get_name() {
		return __( 'My Builder', 'my-builder-aipd' );
	}

	public function is_available() {
		return defined( 'MY_BUILDER_VERSION' );
	}

	public function get_supported_components() {
		return PageSchema::COMPONENT_TYPES;
	}

	public function render_page( array $schema ) {
		$modules = array();
		foreach ( $schema['sections'] as $section ) {
			$modules[] = $this->map_section( $section, $schema ); // Your mapping.
		}
		return $modules;
	}

	protected function post_content( array $schema ) {
		// Fallback HTML shown if the builder is deactivated later.
		return \AIPageDesigner\Security\Kses::page( ( new \AIPageDesigner\PageBuilders\Classic\HtmlRenderer( $schema ) )->page( false ) );
	}

	protected function after_insert( $post_id, array $schema ) {
		// Store builder data with the builder's public API.
		my_builder_save_layout( $post_id, $this->render_page( $schema ) );
		return true;
	}

	public function update_section( $post_id, array $schema, $section_id ) {
		$allowed = $this->can_update( $post_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		// Load layout, replace the module whose id matches $section_id, save.
		return true;
	}
}

add_action( 'aipd_register_page_builders', function ( $registry ) {
	$registry->register( new My_Builder_Adapter() );
} );
```

## Reference implementations

* `Gutenberg/BlockSerializer.php`: produces markup identical to each core block's `save()` output. Verified with Gutenberg's own parser (`tests/js/validate-blocks.js`) and in the real editor (E2E).
* `Elementor/ElementorMapper.php`: pure array mapping (no Elementor classes), containers or legacy section/column, Pro widgets only when registered.
* `Classic/HtmlRenderer.php`: semantic HTML, also used for the sandboxed preview.

## Status of other builders

Divi, Bricks, Beaver Builder and WPBakery are **not** supported natively. Their storage formats are either shortcode-based or undocumented for programmatic import, so claiming support would be misleading. Users can choose the Classic/HTML output. Dedicated adapters should ship as separate add-ons once a stable, documented import API is confirmed for each builder.
