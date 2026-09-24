# Development, testing, build and release

## Requirements

* PHP 7.4+ (the runtime minimum; development tooling also runs on PHP 8.x)
* Composer 2
* Node.js 20+ and npm
* Git (the test environment script clones WordPress)
* For E2E: WP-CLI and Chromium (Playwright)

## Setup

```bash
composer install          # PHPUnit, WPCS, PHPCompatibility (dev only)
npm install               # @wordpress/scripts, Playwright, block validation packages
npm run build             # compile src/ into build/
```

If PHPCS does not list the WordPress standards (`vendor/bin/phpcs -i`), register them:

```bash
vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs,vendor/phpcsstandards/phpcsutils,vendor/phpcsstandards/phpcsextra,vendor/phpcompatibility/php-compatibility,vendor/phpcompatibility/phpcompatibility-paragonie,vendor/phpcompatibility/phpcompatibility-wp
```

## Test environment

`bin/install-wp-tests.sh` clones WordPress core and the PHPUnit test library for a given version. By default it uses SQLite (via the official SQLite Database Integration), so no MySQL server is needed:

```bash
bash bin/install-wp-tests.sh 7.1.2 /tmp/aipd-wp          # SQLite
DB_NAME=wp_tests DB_USER=root DB_PASSWORD=secret DB_HOST=127.0.0.1 \
  bash bin/install-wp-tests.sh 7.1.2 /tmp/aipd-wp mysql    # MySQL

export WP_TESTS_DIR=/tmp/aipd-wp/wordpress-develop/tests/phpunit
export WP_TESTS_CONFIG_FILE_PATH=/tmp/aipd-wp/wp-tests-config.php
```

## Running the checks

| Command | What it does |
| --- | --- |
| `composer lint` | PHPCS with WordPress Coding Standards (includes PHPCompatibilityWP for PHP 7.4+) |
| `composer compat` | PHP compatibility scan only |
| `composer test` | PHPUnit unit + integration suites (single site) |
| `composer test:multisite` | Same suite in multisite mode |
| `AIPD_TEST_ELEMENTOR_DIR=/path/to/elementor composer test:elementor` | Elementor integration tests against a real Elementor checkout |
| `npm run lint:js` | ESLint (WordPress configuration) |
| `npm run test:blocks` | Parses the serializer fixtures with `@wordpress/blocks` and reports invalid blocks (run PHPUnit first to generate `tests/fixtures/*.html`) |
| `bash bin/e2e-setup.sh` then `npm run test:e2e` | Playwright E2E, accessibility (axe), RTL/LTR and responsive tests |

Useful PHPUnit groups and suites: `--testsuite unit`, `--testsuite integration`, `--group elementor`.

### Test coverage map

| Area | Tests |
| --- | --- |
| Schema validation, sanitization, normalization | `tests/Unit/SchemaValidationTest.php` |
| SSRF, links, secrets, redaction, kses, shortcodes | `tests/Unit/SecurityTest.php` |
| Provider (no key, invalid key, timeout, retries, 429/5xx, truncated/incomplete/invalid JSON, key secrecy) | `tests/Unit/ProviderTest.php` |
| Prompt data boundary and injection resistance | `tests/Unit/PromptBuilderTest.php`, `RestApiTest::test_prompt_injection_output_is_neutralized` |
| Elementor mapping, RTL mirroring, Pro fallbacks, no fatal without Elementor | `tests/Unit/ElementorMapperTest.php` |
| REST permissions, consent, cost confirmation, rate limit, idempotency, ownership, interrupted jobs, history off, preview | `tests/Integration/RestApiTest.php` |
| Drafts only, kses round-trip, capabilities, revisions, section replacement, classic HTML, theme switch, frontend CSS | `tests/Integration/DraftTest.php` |
| Templates import/export, path traversal, privacy exporter/eraser, retention, multisite capability mapping | `tests/Integration/TemplatesPrivacyTest.php` |
| Uninstall (opt-in, only own data), deactivation | `tests/Integration/UninstallTest.php` |
| Real Elementor Documents API | `tests/Integration/ElementorIntegrationTest.php` |
| Block validity in the real editor, wizard flows EN/FA, secrets in the browser, admin a11y, keyboard, unsaved changes, RTL admin, responsive | `tests/E2E/*.spec.js` |

## Build and release

```bash
# 1. Update the version in ai-page-designer.php (header + AIPD_VERSION), readme.txt (Stable tag, changelog) and package.json.
# 2. Refresh translations.
npm run make-pot && wp i18n update-po languages/ai-page-designer.pot languages/ && wp i18n make-mo languages/ && npm run make-json
# 3. Run all checks (see above) and Plugin Check on the package:
bash bin/build-zip.sh                      # creates dist/ai-page-designer/ and dist/ai-page-designer.zip
wp plugin check ai-page-designer           # with dist/ai-page-designer installed as the plugin folder
# 4. Tag the release: git tag v1.0.0 && git push --tags
```

`bin/build-zip.sh` runs `npm run build` (set `SKIP_JS_BUILD=1` to skip) and copies everything except the paths listed in `.distignore` (tests, dev configs, node_modules, vendor, docs). The package contains no Composer dependencies: `includes/Autoloader.php` loads the plugin classes.

### WordPress.org SVN

```
trunk/          ← contents of dist/ai-page-designer/
tags/1.0.0/     ← same contents
assets/         ← .wordpress-org/* (banner, icon, screenshots)
```
