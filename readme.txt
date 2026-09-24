=== AI Page Designer ===
Contributors: tabriznia
Tags: ai, page builder, landing page, block editor, rtl
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn a written brief into an accessible, responsive draft page for the block editor, Elementor or classic editor, using the AI service you choose.

== Description ==

AI Page Designer helps you go from an idea to a well-structured draft page in minutes, without giving up control.

Describe your business, audience, goal and call to action. The plugin asks the AI service **you** configure to propose a section-by-section structure, lets you edit it, then writes the content as a strictly validated **Page Schema** (never raw HTML or code). The schema is sanitized, checked for accessibility, and converted into native content for your page builder. Pages are always saved as **drafts**. Nothing is published automatically.

= Highlights =

* **Guided wizard**: page type, business and audience, language and direction, colors and fonts, page builder, editable structure, live responsive preview, draft creation.
* **Block editor first**: output uses only core blocks (group, columns, heading, paragraph, buttons, list, image, quote, details and more). Pages open without "invalid block" warnings and stay editable if you deactivate the plugin.
* **Elementor**: native containers and standard widgets through Elementor's public Documents API. Elementor Pro widgets (forms, price tables) are used only when Elementor Pro is active. No Elementor code is bundled.
* **Classic editor / HTML**: clean semantic HTML for any theme or builder.
* **Multilingual and RTL**: choose the content language per page, independently of your admin language. Persian, Arabic, Hebrew and other right-to-left languages get a truly mirrored layout. Optional integration with WPML and Polylang, compatible with TranslatePress.
* **Accessible by default**: one H1, no skipped heading levels, WCAG AA color contrast repair, alt text checks, visible focus styles, keyboard-operable admin screens.
* **Design tokens and Brand Kit**: colors, fonts, radius, shadows, spacing and container width, with seven style presets (Corporate, Minimal, Creative, Luxury, SaaS, E-commerce, Editorial). No font files are downloaded.
* **Regenerate a single section** and apply it to an existing draft. Previous versions stay available in WordPress revisions.
* **Templates**: start from built-in English (LTR) and Persian (RTL) landing page templates without any AI request, save your own, and import/export them as validated JSON.
* **Your choice of AI service**: OpenCode Zen or OpenCode Go (GPT, Claude, Gemini, Qwen, DeepSeek, GLM, Kimi and more with one key), any OpenAI-compatible Chat Completions API, or the AI services connected in WordPress 7.0+ under Settings > Connectors. Developers can add providers without changing the plugin.

= Security and privacy =

* Every AI request is sent from your server. API keys are encrypted at rest, never sent to the browser, never logged and never returned by the REST API. Keys can also be defined in `wp-config.php`.
* No request is sent until an administrator accepts the data sharing notice **and** a provider is configured.
* For paid providers, users confirm every request before it is sent. Per-user hourly limits protect your budget.
* Model output is treated as untrusted: it can only produce the Page Schema. HTML is filtered with strict allowlists, links are validated, shortcodes are neutralized, and no generated code is ever executed.
* SSRF protection: API URLs must be public HTTPS addresses. Private and reserved networks are blocked unless the site owner explicitly allows a local model server.
* History and logs can be disabled, have a configurable retention period, never contain API keys or prompts, and support the WordPress personal data export and erasure tools.
* No tracking, no telemetry, no advertising, no links added to your site.

== External services ==

This plugin connects to an external AI service **only after an administrator configures one and accepts the data sharing notice**. It provides no AI service itself and has no default service enabled.

= OpenAI-compatible API (configured by the site administrator) =

* **What it is used for:** generating the page structure, page content and regenerated sections.
* **What is sent and when:** only when a user with permission clicks a generation button (and confirms the request if the provider is marked as paid). The request contains the brief the user typed (page type, topic, goal, business description, brand name, audience, tone, content language, colors, call to action, number of sections and notes), the approved outline and design tokens, and, when regenerating a section, that section's content and the user's instruction. The API key is sent in the Authorization header. No site content, user data or visitor data is sent.
* **Where it is sent:** the API base URL entered by the administrator (for example `https://api.openai.com/v1`), to the `/chat/completions` endpoint. "Test connection" calls `/models`.
* **Terms and privacy:** these depend on the service you choose. If you use OpenAI: [Terms of use](https://openai.com/policies/terms-of-use/), [Privacy policy](https://openai.com/policies/privacy-policy/). For other providers, see their own terms and privacy policy before connecting.

= OpenCode Zen / OpenCode Go (configured by the site administrator) =

* **What it is used for:** generating the page structure, page content and regenerated sections with the model the administrator selects.
* **What is sent and when:** the same brief data described above, only when a permitted user clicks a generation button (and confirms the request on the pay-as-you-go Zen plan). The API key is sent in the `Authorization`, `x-api-key` or `x-goog-api-key` header depending on the model family.
* **Where it is sent:** `https://opencode.ai/zen/v1` (Zen) or `https://opencode.ai/zen/go/v1` (Go), to `/chat/completions`, `/responses`, `/messages` or `/models/{model}:generateContent`. "Test connection" calls `/models`. OpenCode forwards requests to the provider of the selected model.
* **Data use:** free models that OpenCode documents as possibly using data for training are blocked unless the administrator explicitly allows them.
* **Terms and privacy:** [OpenCode Terms of Service](https://opencode.ai/legal/terms-of-service), [OpenCode Privacy Policy](https://opencode.ai/legal/privacy-policy).

= WordPress AI Client (WordPress 7.0 or newer) =

If you select "WordPress AI Client (Connectors)", requests are sent through WordPress core to the AI service connected under Settings > Connectors. The same brief data described above is sent, under the terms and privacy policy of that connected service. API keys for those services are managed by WordPress core and are never seen by this plugin.

== Installation ==

1. Install the plugin from the Plugins screen, or upload the `ai-page-designer` folder to `/wp-content/plugins/`, and activate it.
2. Go to **AI Page Designer > Privacy**, review what is sent to the AI service, and accept the notice.
3. Go to **AI Page Designer > AI Providers**. Configure OpenCode (Zen or Go: API key and model), an OpenAI-compatible service (API base URL, API key and model), or choose the WordPress AI Client on WordPress 7.0+. Click **Test connection**.
4. Optional: set your colors, fonts and tone in **Brand Kit**.
5. Open **AI Page Designer > New Page** and follow the steps.

To keep the API key out of the database, add it to `wp-config.php` instead:

`define( 'AIPD_OPENAI_COMPATIBLE_API_KEY', 'your-key' );`

== Frequently Asked Questions ==

= Does the plugin work without an AI service? =

Yes, partially. You can create pages from the built-in or imported templates, preview them and create drafts without any AI request. AI generation requires a provider you configure.

= Will it publish pages automatically? =

No. Every page is created as a draft. You review, edit and publish it yourself with the normal WordPress capabilities.

= Which page builders are supported? =

The block editor (recommended), Elementor (free and Pro), and a Classic editor / HTML output that works with any theme. Adapters for other builders can be added by developers through the `aipd_register_page_builders` action. Divi, Bricks, Beaver Builder and WPBakery are not supported natively yet; use the HTML output.

= Can I use a local model server? =

Yes, if it exposes an OpenAI-compatible API. Local and private network addresses are blocked by default for security. A site owner can allow them by adding `define( 'AIPD_ALLOW_LOCAL_ENDPOINTS', true );` to `wp-config.php`.

= Does it support right-to-left languages? =

Yes. Choose Persian, Arabic, Hebrew, Urdu or another RTL language as the content language, and the preview and the generated page use a mirrored right-to-left layout. The admin screens support RTL admin languages too.

= Who can use it? =

Administrators get two capabilities on activation: `aipd_manage_settings` (providers, keys, privacy, logs, import/export) and `aipd_generate_pages` (the wizard). You can grant `aipd_generate_pages` to other roles with a role manager plugin or the `aipd_generator_roles` filter. Creating a draft also requires the normal permission to create pages. On multisite, a network can restrict settings to super admins with `define( 'AIPD_NETWORK_MANAGED_SETTINGS', true );`.

= What happens when I delete the plugin? =

Capabilities and scheduled events are always removed. Settings, keys, history, logs and saved templates are deleted only if you enable "Delete all plugin data" on the Privacy screen. Pages you created are your content and are never deleted.

= Where are the source files of the JavaScript? =

The readable source is included in the `src/` folder. The compiled files in `build/` are generated with `@wordpress/scripts` (`npm install && npm run build`).

== Screenshots ==

1. The page wizard with an editable structure proposal.
2. Responsive preview of a Persian (RTL) landing page.
3. A generated landing page in the block editor.
4. AI Providers settings with connection test.

== Changelog ==

= 1.1.0 =
* New: OpenCode Zen and OpenCode Go provider. One API key for GPT, Claude, Gemini, Qwen, DeepSeek, GLM, Kimi and other models; each model is called through its native API format (Chat Completions, Responses, Anthropic Messages or Gemini) automatically.
* New: free models that may use data for training are blocked unless explicitly allowed.
* Security: provider error messages are scrubbed of the configured API key whatever its format.

= 1.0.0 =
* Initial release: page wizard, Page Schema validation, OpenAI-compatible and WordPress AI Client providers, block editor, Elementor and classic adapters, Brand Kit, templates with import/export, generation history, logs, privacy tools, English and Persian translations, RTL support.

== Upgrade Notice ==

= 1.1.0 =
Adds OpenCode Zen / Go as an AI provider.

= 1.0.0 =
Initial release.
