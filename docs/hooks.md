# Hooks and filters

All hooks use the `aipd_` prefix. No hook ever receives an API key, a decrypted secret or authorization headers.

## Actions

| Action | Arguments | When |
| --- | --- | --- |
| `aipd_loaded` | `Plugin $plugin` | After the plugin boots (`plugins_loaded`). Register integrations here. |
| `aipd_register_providers` | `ProviderRegistry $registry` | Once, when providers are first needed. Call `$registry->register( $provider )`. |
| `aipd_register_page_builders` | `AdapterRegistry $registry` | Once, when adapters are first needed. Call `$registry->register( $adapter )`. |
| `aipd_before_ai_request` | `array $summary, string $provider_id` | Before a request. `$summary` contains purpose, prompt sizes, temperature, max tokens and JSON mode only. |
| `aipd_after_ai_request` | `array $summary, array $result, string $provider_id` | After a request. `$result` has model, tokens and seconds, or an error code. |
| `aipd_after_create_draft` | `int $post_id, array $schema, string $builder_id` | After a draft is created (used by the multilingual integration). |

## Filters

### AI and prompts

| Filter | Value | Extra arguments | Notes |
| --- | --- | --- | --- |
| `aipd_ai_request` | `CompletionRequest $request` | `string $provider_id` | Change temperature, max tokens or JSON mode. Must return a `CompletionRequest`. |
| `aipd_system_prompt` | `string $system` | `string $purpose` (`outline`, `page`, `section`) | Extend instructions. The core safety rules are re-appended after filtering and cannot be removed. |
| `aipd_openai_compatible_body` | `array $body` | `CompletionRequest $request` | Add provider-specific parameters. Never add credentials. |
| `aipd_rate_limit_per_hour` | `int $limit` | | Per-user hourly request limit. |
| `aipd_allow_local_endpoints` | `bool $allowed` | | Allow private/loopback API URLs (default from `AIPD_ALLOW_LOCAL_ENDPOINTS`). |
| `aipd_resolve_host` | `string[]|null $ips` | `string $host` | Pre-resolve DNS for the SSRF check (used by tests). |

### Schema and design

| Filter | Value | Extra arguments | Notes |
| --- | --- | --- | --- |
| `aipd_page_schema_definition` | `array $definition` | | The JSON Schema. New components also need renderer support (see the `aipd_*_render_component` filters). |
| `aipd_validate_schema` | `string[] $errors` | `array $schema` | Add errors to reject a schema. |
| `aipd_page_schema` | `array $schema` | | Modify a validated schema. The result is sanitized and validated again; invalid changes are discarded. |
| `aipd_design_tokens` | `array $tokens` | `string $style` | Change style presets. |
| `aipd_fonts` | `array $fonts` | | Font choices: `id => array( 'label', 'stack' )`. Stacks must reference fonts that are already available. |
| `aipd_page_css` | `string $css` | `array $schema, string $scope` | Scoped CSS for a generated page. Markup breakouts are stripped. |
| `aipd_inline_allowed_html` | `array $tags` | | Inline HTML allowed in text fields. Script/style tags and `on*` attributes are always removed. |
| `aipd_page_allowed_html` | `array $tags` | | Allowlist for HTML output. Same protections apply. |

### Rendering

| Filter | Value | Extra arguments | Notes |
| --- | --- | --- | --- |
| `aipd_gutenberg_render_component` | `array|null $blocks` | `array $component, array $context` | Return parsed-block arrays to replace the default block output. |
| `aipd_elementor_render_component` | `array|null $elements` | `array $component, array $context` | Return Elementor element arrays. |
| `aipd_html_render_component` | `string|null $html` | `array $component, array $context` | Return HTML (filtered with `wp_kses`). |

### Drafts, templates and permissions

| Filter | Value | Extra arguments | Notes |
| --- | --- | --- | --- |
| `aipd_before_create_draft` | `array $args` | `array $schema, string $builder_id` | `post_type`, `title`, `parent`. The status is always forced to `draft`. |
| `aipd_post_types` | `string[] $types` | | Post types a draft can be created as (default `page`, `post`). |
| `aipd_page_template` | `string $template` | `array $templates, string $builder_id` | Page template for new drafts (default: the theme's "no title" template when present). |
| `aipd_register_templates` | `array $templates` | | Add built-in templates: `id => array( 'title', 'file', 'language', 'direction' )`. Files are validated before use. |
| `aipd_generator_roles` | `string[] $roles` | | Roles that receive `aipd_generate_pages` on activation. |
| `aipd_site_admins_can_manage_settings` | `bool $allowed` | | Multisite: whether site administrators manage AI settings. |

## Examples

Give editors access to the wizard on activation:

```php
add_filter( 'aipd_generator_roles', function ( $roles ) {
	$roles[] = 'editor';
	return $roles;
} );
```

Require at least one FAQ on every page:

```php
add_filter( 'aipd_validate_schema', function ( $errors, $schema ) {
	$types = wp_list_pluck( $schema['sections'], 'type' );
	if ( ! in_array( 'faq', $types, true ) ) {
		$errors[] = 'Every page needs an FAQ section.';
	}
	return $errors;
}, 10, 2 );
```

Add a house style to the system prompt:

```php
add_filter( 'aipd_system_prompt', function ( $system ) {
	return $system . "\nAlways write in British English and avoid exclamation marks.";
} );
```

Log token usage to your own analytics (sizes only, no content):

```php
add_action( 'aipd_after_ai_request', function ( $summary, $result, $provider ) {
	if ( isset( $result['tokens_out'] ) ) {
		my_cost_tracker( $provider, $result['tokens_in'], $result['tokens_out'] );
	}
}, 10, 3 );
```
