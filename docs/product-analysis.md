# Product analysis (Phase 1)

## Sources and verification

* WordPress 7.1.2 (current stable at development time) and its bundled APIs (AI Client, Connectors, Abilities) were inspected directly from the official `WordPress/WordPress` and `wordpress-develop` repositories.
* The official **Plugin Check** tool (v2.1.0, `WordPress/plugin-check`) was run against the release package.
* Elementor 4.4 (`elementor/elementor`, GPL) was used only as a test dependency to verify public APIs. No Elementor code is copied into the plugin.
* `developer.wordpress.org` and `wordpress.org` were **not reachable** from the build environment. The detailed plugin guidelines were applied from the published 18 guidelines, and must be re-checked against the live pages before submission (see the [submission checklist](wordpress-org-checklist.md)).

## Conflicts with WordPress.org rules and the compatible solution

| Request | Conflict | Solution adopted |
| --- | --- | --- |
| Name "AI Page Designer for WordPress" | Guideline 17 and trademark policy: plugins may not use "WordPress" in their name or slug. | Display name **AI Page Designer**, slug and text domain `ai-page-designer`. Check slug availability at submission; if taken, choose a new slug and rename the text domain in one pass (`wp i18n` tools make this mechanical). |
| "Use OpenAI" by default | Guideline 6 allows serviceware but the service must be disclosed, and users must not be forced into one provider. Plugin Check 2.x also recommends the WordPress 7.0 AI Client. | No service is enabled by default. On WordPress 7.0+ the default provider is the **core WordPress AI Client** (Connectors). Any OpenAI-compatible endpoint can be configured. The readme has a complete **External services** section. |
| Send data to external AI services | Guideline 7: no data collection without consent. | Nothing is sent until an administrator accepts the data sharing notice and configures a provider. Paid requests need per-request confirmation. No tracking or telemetry. |
| Bundled Persian translation | WordPress.org serves language packs from translate.wordpress.org. | The fa_IR files are bundled and loaded with `load_plugin_textdomain()`; language packs installed in `wp-content/languages` take precedence. After approval, import the PO file into translate.wordpress.org. |
| Placeholder images / icons | Guideline 1 and copyright: no non-GPL assets. | No images are bundled. Image placeholders are empty core image blocks (the editor shows its upload UI). Icons are Unicode glyphs; Elementor output uses the Font Awesome icons that ship with Elementor itself. |
| Fonts chosen by the user | External font loading without consent and GDPR concerns. | No fonts are ever downloaded: system stacks or theme.json font families only. |
| Remote code | Guideline 8: no executing remote code. | The model can only produce a JSON Page Schema. Nothing it returns is executed; shortcodes are neutralized; HTML is filtered through strict allowlists. |
| Divi, Bricks, Beaver Builder, WPBakery support | No stable public import API for all of them; claiming support would be misleading. | Not claimed. The HTML adapter works everywhere, and the adapter interface lets add-ons implement them. |
| Minified JavaScript | Guideline 4: code must be human readable. | Readable sources are shipped in `src/`, with build instructions in the readme. |

## MVP scope (implemented in 1.0.0)

* Connection to any OpenAI-compatible API, plus the WordPress 7.0+ AI Client.
* Secure settings screens (encrypted keys, wp-config constants, SSRF-safe URLs), connection test with model list.
* Page Schema generation with validation, sanitization and accessibility normalization.
* Wizard: brief → editable structure → content → responsive preview → draft → editor.
* Gutenberg output (core blocks only, validated with Gutenberg's own parser), Elementor adapter, Classic/HTML adapter.
* Landing page templates in English (LTR) and Persian (RTL), template save/import/export.
* Brand Kit, design token presets.
* English and Persian UI, RTL and LTR content and admin.
* Limited history without secrets, logs, retention, privacy exporter/eraser.
* Error handling, retries, timeouts, rate limiting, idempotent background jobs.
* Installation and developer documentation, security and schema tests.

## Later versions (roadmap)

| Version | Candidate features |
| --- | --- |
| 1.1 | Image suggestions with explicit consent and `media_sideload_image()` (validation helpers already exist in `UrlValidator::validate_remote_image()`), editable alt/caption before import; Abilities API registration so agents can call "generate page structure" |
| 1.2 | More templates (services, about, contact, product, article), "save page as template" from the editor sidebar, template gallery previews |
| 1.3 | Block editor sidebar plugin: regenerate a selected section in place |
| 2.0 | Optional add-on adapters (Bricks, Beaver Builder, Divi) once stable import APIs are confirmed; team review workflow; per-role quotas |
| Out of scope | Credit/billing system, template marketplace (would raise Guideline 5/6 concerns and need a separate service with its own disclosures) |

## Technical and legal risks

| Risk | Impact | Mitigation |
| --- | --- | --- |
| Model output invalid, truncated or malicious (prompt injection) | Broken or unsafe pages | Output treated as untrusted; data boundary in prompts; strict schema; sanitizer; kses; shortcode neutralization; tests with injected payloads |
| Invalid blocks after core updates | "This block contains unexpected content" | Serializer mirrors core `save()` output; validated with `@wordpress/blocks` in CI and in the real editor (E2E); runtime detection of block supports (`typography.textAlign`, `ariaLabel`) for older WordPress versions |
| Elementor data format changes | Broken Elementor drafts | Uses the public Documents API (`documents->get()->save()`), falls back to documented meta; integration test against a real Elementor checkout |
| SSRF via configurable API URL | Internal network access | HTTPS-only public hosts, DNS resolution check, `wp_safe_remote_request()`, no redirects; local endpoints only with an explicit wp-config constant |
| API key leakage | Financial and security loss | libsodium encryption, never rendered or returned, redaction in logs and errors, optional wp-config constants |
| Costs from repeated requests | Unexpected bills | Per-request confirmation, idempotent jobs, hourly per-user limit |
| Personal data in briefs | GDPR exposure | Explicit consent notice, warning in the brief form, history can be disabled, retention, exporter and eraser, privacy policy text |
| Long-running requests | PHP or proxy timeouts | Job model with cron fallback and polling; configurable timeout; stale job recovery |
| Trademark use (OpenAI, Elementor) | Plugin rejection | Names used only descriptively ("OpenAI-compatible", "for Elementor"), not in the plugin name, slug or icon |
| Theme conflicts | Inconsistent styling | Core block attributes carry colors and spacing; small scoped CSS only; tested with Twenty Twenty-Five; theme "no title" template used to keep a single H1 |

## Acceptance criteria and status

| Criterion | Status | Evidence |
| --- | --- | --- |
| No external request without an API key | ✅ | `ProviderTest::test_no_request_is_sent_without_api_key`, `RestApiTest::test_no_external_request_without_api_key` |
| API key never in browser, logs or REST responses | ✅ | `ProviderTest::test_api_key_is_encrypted_at_rest_and_not_public`, `RestApiTest::test_full_flow_outline_page_draft` (responses and logs), E2E `provider setup never exposes the API key` |
| Unauthorized users cannot view or change settings | ✅ | `RestApiTest::test_unauthenticated_and_unprivileged_users_are_denied`, `…generator_without_manage…`, E2E subscriber test |
| Valid Persian RTL landing page | ✅ | E2E `Persian RTL landing page`, `SchemaValidationTest::test_persian_template_is_rtl_and_english_is_ltr` |
| Valid English LTR landing page | ✅ | E2E `English LTR landing page` |
| Gutenberg output opens without invalid blocks | ✅ | `tests/js/validate-blocks.js` (83 blocks), E2E editor check in WordPress 7.1.2 (0 invalid blocks), kses round-trip test |
| Elementor output editable when Elementor is active | ✅ | `ElementorIntegrationTest` against Elementor 4.4 (Documents API, `_elementor_edit_mode = builder`, render, section update) |
| No fatal error without Elementor | ✅ | `ElementorMapperTest::test_adapter_is_unavailable_without_elementor_and_does_not_fatal`, full suite runs without Elementor |
| Pages only created as drafts | ✅ | `DraftTest::test_drafts_are_never_published_even_if_requested` |
| All UI strings translatable | ✅ | POT with 462 strings; 100% fa_IR translation; PHPCS I18n sniffs |
| WordPress Coding Standards and Plugin Check | ✅ | PHPCS (WPCS 3.4) clean; Plugin Check 2.1.0: 0 errors, 1 advisory warning (documented) |
| Uninstall does not harm other data | ✅ | `UninstallTest` |
| No model-generated code is executed | ✅ | `RestApiTest::test_prompt_injection_output_is_neutralized`, `SecurityTest` |
