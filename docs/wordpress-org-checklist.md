# WordPress.org submission checklist

Items marked ✅ were verified in this repository. Items marked ☐ need a human decision or access that the build environment did not have.

## Before submitting

* ☐ Re-read the current [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) and [Plugin readme guidelines](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/). They could not be fetched from the build environment.
* ☐ Check that the slug `ai-page-designer` is available (plugins directory search). If it is taken, pick a unique slug and rename: plugin folder, main file, `Text Domain` header, every `'ai-page-designer'` text domain string, language file names, and `readme.txt` (the WordPress i18n tools and a search-and-replace make this mechanical).
* ☐ Set `Contributors:` in `readme.txt` to your WordPress.org username(s). It currently contains a placeholder (`tabriznia`).
* ☐ Update `Tested up to:` to the latest WordPress major at submission time (currently `7.1`, verified with 7.1.2).
* ☐ Update `Plugin URI` / `Author` in `ai-page-designer.php` if you have a public site. Do not use "WordPress" in the plugin name or URL domain.
* ☐ Enable two-factor authentication on the WordPress.org account.

## Guidelines (1–18)

| # | Guideline | Status |
| --- | --- | --- |
| 1 | GPL-compatible | ✅ `GPL-2.0-or-later` header, `LICENSE` (GPLv2 text). No third-party code or assets are bundled; icons are Unicode glyphs; artwork in `.wordpress-org/` is original. |
| 2 | Developer responsible for contents | ✅ No external libraries bundled in the package. |
| 3 | Stable version in the directory | ✅ `Stable tag: 1.0.0`, version constants match. |
| 4 | Human-readable code | ✅ Readable `src/` shipped with `build/`; build steps in readme FAQ and `docs/development.md`. No obfuscation. |
| 5 | No trialware | ✅ All features included. No locked features, license keys or upsells. |
| 6 | Serviceware allowed | ✅ The plugin connects to AI services configured by the admin. Fully disclosed in readme **External services**. |
| 7 | No tracking without consent | ✅ No telemetry. AI requests require explicit admin consent and are user-initiated. |
| 8 | No remote executable code | ✅ Nothing downloaded is executed. Model output is data validated against a schema. No external JS/CSS/fonts on the front end. |
| 9 | Nothing illegal or dishonest | ✅ The prompt instructs the model not to invent facts, prices or testimonials; templates use obvious placeholders. |
| 10 | No external links without permission | ✅ No "powered by" links or credits added to sites. |
| 11 | No admin hijacking | ✅ No global notices; notices only on the plugin's own screens and only as results of user actions. No ads. |
| 12 | No readme spam | ✅ Five tags, no keyword stuffing, only relevant links (service terms/privacy). |
| 13 | Use WordPress default libraries | ✅ React, components, api-fetch and i18n come from WordPress (`@wordpress/*` externals). |
| 14 | Avoid frequent commits | Process guideline for SVN usage. |
| 15 | Version numbers increase | ✅ 1.0.0 initial. |
| 16 | Complete plugin at submission | ✅ |
| 17 | Respect trademarks | ✅ Name does not start with or contain third-party trademarks; OpenAI and Elementor mentioned only descriptively. |
| 18 | Directory maintainers' discretion | — |

## Automated checks

| Check | Result |
| --- | --- |
| Plugin Check 2.1.0 (`wp plugin check ai-page-designer`) on `dist/ai-page-designer` | ✅ 0 errors; 1 advisory warning `PluginCheck.CodeAnalysis.AIProvider.DirectIntegration` (the OpenAI-compatible provider). This is expected: the plugin also offers the WordPress AI Client provider (the default on WordPress 7.0+), and the OpenAI host is referenced only to show the service's terms and privacy links. Mention this to the reviewer. |
| PHPCS, WordPress Coding Standards 3.4 | ✅ clean |
| PHPCompatibilityWP, PHP 7.4+ | ✅ clean |
| PHPUnit (WordPress 7.1.2 and 6.6.2, single site and multisite) | ✅ |
| Elementor 4.4 integration | ✅ |
| Block editor validity (WordPress 7.1.2 and 6.6.2 editors) | ✅ |
| Playwright E2E + axe (WCAG 2.2 AA rules) | ✅ |

## Package contents

`bash bin/build-zip.sh` produces `dist/ai-page-designer.zip` with: `ai-page-designer.php`, `uninstall.php`, `readme.txt`, `LICENSE`, `includes/`, `assets/`, `build/`, `src/`, `templates/`, `languages/`. Excluded: tests, docs, dev configs, `node_modules/`, `vendor/`.

## SVN assets (`.wordpress-org/`)

* `icon-128x128.png`, `icon-256x256.png`
* `banner-772x250.png`, `banner-1544x500.png`
* `screenshot-1.png` … `screenshot-4.png` (captions in `readme.txt` → Screenshots)

## After approval

* Import `languages/ai-page-designer-fa_IR.po` into translate.wordpress.org (Persian) so it becomes a language pack.
* Add the plugin's support forum link to the Help screen if desired.
