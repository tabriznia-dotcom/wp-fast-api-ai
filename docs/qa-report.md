# QA report: AI Page Designer 1.1.0

Date: 2026-09-24. Environment: PHP 8.4 CLI, SQLite database integration, Chromium (Playwright 1.56), WordPress 7.1.2 and 6.6.2, Elementor 4.4.

## Results

| Check | Command | Result |
| --- | --- | --- |
| WordPress Coding Standards (WPCS 3.4) | `composer lint` | ✅ 0 errors, 0 warnings |
| PHP 7.4+ compatibility | `composer compat` | ✅ clean |
| PHPUnit, WordPress 7.1.2, single site | `composer test` | ✅ 123 tests, 599 assertions |
| PHPUnit, WordPress 7.1.2, multisite | `composer test:multisite` | ✅ 123 tests, 600 assertions |
| PHPUnit, WordPress 6.6.2 (minimum), single site | idem with a 6.6.2 environment | ✅ 123 tests, 599 assertions |
| PHPUnit, WordPress 6.6.2, multisite | idem | ✅ 123 tests, 600 assertions |
| Elementor 4.4 integration | `composer test:elementor` | ✅ 2 tests, 13 assertions |
| ESLint (WordPress config) | `npm run lint:js` | ✅ clean |
| Production build | `npm run build` | ✅ |
| Block validation with `@wordpress/blocks` (current) | `npm run test:blocks` | ✅ all blocks valid (4 fixture files, 308 blocks) |
| Block validity in the real WordPress 7.1.2 editor | `block-validity.spec.js` + wizard flows | ✅ 0 invalid blocks |
| Block validity in the real WordPress 6.6.2 editor | same check on a 6.6.2 site with 6.6-generated markup | ✅ 0 invalid blocks (308 blocks) |
| Playwright E2E, accessibility, RTL/LTR, responsive | `npm run test:e2e` | ✅ 14 passed (includes OpenCode provider flow) |
| Plugin Check 2.1.0 on the release package | `wp plugin check ai-page-designer` | ✅ 0 errors, 1 advisory warning (see below) |
| Release package | `bash bin/build-zip.sh` | ✅ `dist/ai-page-designer.zip` (≈232 KB) |

### Plugin Check warning

`PluginCheck.CodeAnalysis.AIProvider.DirectIntegration` in `includes/AI/Providers/OpenAICompatibleProvider.php`: "Direct integration with a third-party AI provider (api.openai.com) detected. Consider the WordPress AI Client".

This is advisory and intentional. The plugin already implements the WordPress AI Client provider and uses it by default on WordPress 7.0+. The OpenAI-compatible provider is optional and has no default endpoint. The OpenAI host appears only as a help-text example and to show OpenAI's terms and privacy links when that host is configured.

## OpenCode provider (1.1.0)

`tests/Unit/OpenCodeProviderTest.php` (13 tests) covers format detection, the endpoint and auth header for each format (Chat Completions and Responses: `Authorization: Bearer`; Messages: `x-api-key` + `anthropic-version`; Gemini: `x-goog-api-key`), response parsing, truncation and refusal handling per format, Zen vs Go base URLs and cost confirmation, the training-data opt-in, rejection of non-text models, OpenCode error mapping, and key scrubbing. `tests/E2E/opencode.spec.js` configures the provider through the settings screen and generates a structure through the Messages format.

The OpenCode documentation site (opencode.ai) was not reachable from the build environment; the integration follows OpenCode's published documentation and gateway source code in the `sst/opencode` repository. Verify against a live key before release.

## Required scenarios

| Scenario | Covered by |
| --- | --- |
| Invalid API key | `ProviderTest::test_invalid_api_key_maps_to_safe_error` |
| Service timeout | `ProviderTest::test_timeout_is_retried_then_reported` |
| Incomplete model response | `ProviderTest::test_incomplete_and_truncated_responses` |
| Invalid JSON | `SchemaValidationTest::test_invalid_json_text_is_rejected`, `RestApiTest::test_invalid_model_json_fails_the_job_gracefully` |
| SSRF attempt | `SecurityTest::test_ssrf_endpoints_are_blocked` (16 URLs), `ProviderTest::test_saving_private_endpoint_is_rejected` |
| User without permission | `RestApiTest::test_unauthenticated_and_unprivileged_users_are_denied`, E2E subscriber test |
| Elementor deactivated / missing | `ElementorMapperTest::test_adapter_is_unavailable_without_elementor_and_does_not_fatal` (full suite runs without Elementor) |
| Theme change | `DraftTest::test_frontend_css_only_on_generated_pages_and_survives_theme_switch` |
| RTL active | E2E Persian flow (front end and editor canvas `direction: rtl`), RTL admin test |
| Interrupted generation | `RestApiTest::test_interrupted_job_is_marked_failed` |
| Publishing without capability | `DraftTest::test_drafts_are_never_published_even_if_requested`, `DraftTest::test_user_without_page_capability_cannot_create_draft` |
| Plugin deactivated or deleted | `UninstallTest` (deactivation keeps data; uninstall removes only plugin data and only when opted in; pages kept) |
| Prompt injection / model-generated code | `RestApiTest::test_prompt_injection_output_is_neutralized`, `PromptBuilderTest` |
| API key secrecy | `ProviderTest`, `RestApiTest::test_full_flow_outline_page_draft` (REST + logs), E2E page source check |
| kses for users without `unfiltered_html` | `DraftTest::test_content_survives_kses_for_users_without_unfiltered_html` (byte-identical) |
| Accessibility | axe (WCAG 2.0/2.1/2.2 A+AA) on 10 admin screens, the wizard and generated EN/FA pages; keyboard and focus tests |
| Responsive | Generated pages at 390, 820 and 1280 px without horizontal overflow; wizard at 390 px in RTL |

## Defects found and fixed during QA

| Defect | Fix |
| --- | --- |
| `<script>` contents survived as inert text after kses | Strip `script`/`style`/`template`/`noscript` elements with their contents before kses |
| kses normalized `/>` and numeric entities, changing stored block markup for editors without `unfiltered_html` | Serializer emits kses-normalized forms (` />`, `&#091;`); round-trip test added |
| Invalid page title rejected the entire generated page | Title falls back to a translated default |
| RTL direction rule generated as a descendant selector | `.aipd-page.aipd-dir-rtl` compound rule; E2E asserts computed direction |
| Card grids had no place for a section title, leading to inconsistent heading levels | Added `intro` components to sections (schema, all renderers, prompt) |
| Theme page title produced a second H1 | Use the theme's "no title" page template when available (filterable) |
| Emoji script replaced icon glyphs with remote images | Force text presentation (U+FE0E) |
| RTL quote border stayed on the left | Mirrored in scoped CSS |
| RTL content shown LTR inside the block editor on LTR sites | Page CSS also loaded in the editor canvas (`enqueue_block_assets`) |
| Fixture test silently not executed (class preloaded by another test) | Shared fixtures moved to `tests/Fixtures.php` |
| WordPress 6.6 needs legacy text-align attributes | Runtime detection of `typography.textAlign` and `ariaLabel` block supports; verified in the 6.6.2 editor |

## Known limitations

* Forms: core has no form block, so forms render as a labeled placeholder with a `mailto:` button (block editor/HTML) or the Elementor Pro Form widget when available.
* Images: placeholders only in 1.0; users add images from the Media Library. AI image import is planned with explicit consent.
* Elementor PHPUnit tests must run in a separate invocation (`--group elementor`) because the WordPress test framework deletes Elementor's Kit between test classes.
* The E2E RTL admin test forces RTL with a test-only mu-plugin because no core language pack is installed in the test site.
