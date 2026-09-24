# Translation and RTL guide

## Text domain and files

* Text domain: `ai-page-designer` (same as the plugin slug), declared in the plugin header with `Domain Path: /languages`.
* Every UI string uses the WordPress i18n functions: `__()`, `esc_html__()`, `esc_attr__()`, `_n()`/`sprintf()` in PHP, and `__()`/`sprintf()` from `@wordpress/i18n` in JavaScript. Placeholders have `translators:` comments.
* `languages/ai-page-designer.pot`: template (462 strings, PHP + JS).
* `languages/ai-page-designer-fa_IR.po` / `.mo`: Persian (100% translated).
* `languages/ai-page-designer-fa_IR-{md5}.json`: JavaScript translations for `build/wizard.js` and `build/admin.js`, loaded with `wp_set_script_translations()`.

## Updating translations

```bash
# 1. Regenerate the POT after changing strings.
npm run make-pot

# 2. Merge new strings into existing translations.
wp i18n update-po languages/ai-page-designer.pot languages/

# 3. Translate new or changed entries (Poedit, Loco Translate or a text editor).

# 4. Compile MO files and JavaScript JSON files.
wp i18n make-mo languages/
npm run make-json    # uses languages/js-map.json to map src/* strings to build/* scripts
```

## Adding a language

```bash
cp languages/ai-page-designer.pot languages/ai-page-designer-ar.po
# Set "Language: ar" and the Plural-Forms header, translate, then run make-mo and make-json.
```

After the plugin is published on WordPress.org, translations should be contributed on translate.wordpress.org. Language packs installed in `wp-content/languages/plugins/` take precedence over the bundled files.

## Admin language vs content language

* The **admin language** follows the user's profile language (standard WordPress behavior).
* The **content language** is chosen per page in the wizard (default: Brand Kit language, then the site language). The page's `meta.language` is sent to the AI service and used for `lang`/`dir`.
* With WPML or Polylang active, the draft is assigned to the matching language if it is configured there. TranslatePress works on the rendered output and needs no assignment.

## RTL behavior

### Generated pages

| Output | How RTL is applied |
| --- | --- |
| Direction | Derived from the content language (`ar`, `fa`, `he`, `ur`, `ps`, `sd`, `ug`, `yi`, `ckb`, `dv`, `ku`); the model cannot override it. |
| Block editor | Page wrapper group with `aipd-dir-rtl`; scoped CSS sets `direction: rtl`; logical `start`/`end` alignment mapped to physical `right`/`left`. When the site itself is RTL (for example a Persian site), the theme's RTL styles apply as usual. |
| Elementor | Direction class on every top-level container; widget alignment mirrored (`right` for start). |
| HTML / preview | `dir="rtl"` and `lang` on the wrapper and the preview document. |
| Typography | System font stacks include Vazirmatn and Tahoma fallbacks when installed locally; line height is increased in the Persian template. No web fonts are downloaded. |

This is a real mirrored layout (reading order, alignment, borders and card order), not just `text-align: right`.

### Admin screens

* Server-rendered screens use core admin markup plus `assets/css/admin.css`, written with logical properties (`margin-inline`, `padding-inline`, `border-inline-start`, `inset-inline-end`), so one stylesheet works in both directions.
* The wizard sets `dir` from `is_rtl()` and uses logical properties throughout `src/wizard.scss`.

### Testing RTL

* `SchemaValidationTest` checks direction detection.
* E2E: Persian landing page at mobile/tablet/desktop sizes (computed `direction: rtl`, no horizontal overflow, axe checks) and the wizard in an RTL admin (the E2E mu-plugin forces RTL with the `aipd_e2e_rtl` cookie, like the RTL Tester plugin).
* Screenshots are written to `artifacts/screenshots/` for visual review.
