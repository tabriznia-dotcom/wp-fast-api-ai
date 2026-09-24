# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-09-24

### Added

* Page wizard: page type, business and audience, language and direction, colors/fonts/style, page builder, editable structure proposal, responsive preview (mobile/tablet/desktop), draft creation and editor hand-off.
* Page Schema v1.0 (JSON Schema) with sanitizer, strict validator and accessibility normalizer (single H1, heading order, WCAG AA contrast repair, alt text checks, direction from content language).
* AI providers: OpenAI-compatible Chat Completions API and the WordPress 7.0+ AI Client (Connectors). Provider registry with `aipd_register_providers`.
* Page builder adapters: block editor (core blocks only), Elementor (containers or sections, Pro widgets only when available, public Documents API), Classic/HTML. Adapter registry with `aipd_register_page_builders`.
* Section regeneration and in-place section replacement with revisions.
* Design token system with seven style presets and a Brand Kit.
* Built-in English (LTR) and Persian (RTL) landing page templates; save, import and export templates as validated JSON.
* Idempotent background jobs with cron fallback, polling and stale-job recovery.
* Admin screens: Dashboard, New Page, Templates, Brand Kit, Generation History, AI Providers, API Settings, Logs, Privacy, Import/Export, Help.
* Security: encrypted API keys (libsodium) with optional wp-config constants, SSRF protection, capability checks, nonces, strict kses allowlists, shortcode neutralization, per-request cost confirmation, hourly rate limit, redacted logs.
* Privacy: consent notice, suggested privacy policy text, personal data exporter and eraser, optional history and logs, retention policy, opt-in data removal on uninstall.
* Multilingual: content language independent of admin language, RTL/LTR output, WPML and Polylang language assignment, English and Persian (fa_IR) translations.
* Tests: PHPUnit unit/integration (single site and multisite), Elementor integration, block validation, Playwright E2E with axe accessibility checks.
