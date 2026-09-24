# User guide

## 1. Installation

Requirements: WordPress 6.6 or newer (tested up to 7.1), PHP 7.4 or newer. No Composer or Node.js is needed on the server.

1. Go to **Plugins > Add New > Upload Plugin** and upload `ai-page-designer.zip` (or install it from the WordPress.org directory once published).
2. Click **Activate**.
3. A new **AI Page Designer** menu appears for administrators.

On multisite you can activate it per site or network-wide. Tables are created for each site on its first admin visit.

## 2. Accept the data sharing notice

Open **AI Page Designer > Privacy**:

1. Read "What is sent to the AI service".
2. Tick **I understand that briefs are sent to the configured AI service…**
3. Optionally disable **Generation history**, set the **Retention** period, or enable **Delete all plugin data** for uninstall.
4. Save. Until this is accepted, no AI request can be sent (templates still work).

Also copy the **Suggested privacy policy text** into your site's privacy policy (**Settings > Privacy**).

## 3. Configure an AI provider

Open **AI Page Designer > AI Providers**.

### Option A: an OpenAI-compatible API

| Field | Example | Notes |
| --- | --- | --- |
| API base URL | `https://api.openai.com/v1` | Any provider exposing `/chat/completions` (OpenAI, Azure OpenAI-compatible gateways, OpenRouter, Mistral, Groq, local servers…). HTTPS only. |
| API key | `sk-…` | Stored encrypted and never shown again. |
| Model | a chat model your provider offers | Click **Test connection** to fill the list of available models. |
| Timeout | 90 s | Increase for large pages or slow models. |
| Temperature | 0.7 | Lower is more predictable. |
| Maximum output tokens | 6000 | Increase if responses are cut off. |
| Retries | 2 | Only for timeouts, 429 and 5xx errors. |
| JSON output mode | on | Turn off if your provider rejects `response_format`. |
| Requests may cost money | on | Users confirm each request when enabled. |

Tick **Make this the active provider**, click **Save provider**, then **Test connection**.

To keep the key out of the database, add it to `wp-config.php` instead:

```php
define( 'AIPD_OPENAI_COMPATIBLE_API_KEY', 'your-key' );
```

Local model servers (for example on `http://127.0.0.1:11434/v1`) are blocked by default for security. To allow them on a machine you control:

```php
define( 'AIPD_ALLOW_LOCAL_ENDPOINTS', true );
```

### Option B: WordPress AI Client (WordPress 7.0+)

Connect an AI service in **Settings > Connectors**, then select **WordPress AI Client (Connectors)** in AI Page Designer and save. Keys stay in WordPress core settings.

### API Settings

**AI Page Designer > API Settings** controls cost confirmation, the hourly request limit per user, the default page builder and logging.

## 4. Brand Kit (optional)

Set your brand name, colors, heading and body fonts, design style, default tone and default content language. These pre-fill every new page. Fonts are never downloaded: choose a system font or a font your theme already provides.

## 5. Create your first page

Open **AI Page Designer > New Page**.

1. **Page type**: choose Landing page, Services, About us, Contact, Product or Article, or start from a template (no AI request).
2. **Business and audience**: topic (required), goal, business description, brand name, audience, tone, main call to action and link, number of sections, notes. Do not enter confidential or personal data.
3. **Language and direction**: the content language of the page. Right-to-left languages (Persian, Arabic, Hebrew, Urdu…) automatically use a mirrored layout.
4. **Colors, fonts and style**: design preset, five brand colors and fonts. Colors are adjusted automatically if contrast would be too low.
5. **Page builder**: Block editor, Elementor (if active) or Classic editor / HTML.
6. **Structure**: click **Propose structure** and confirm the request. Rename, retype, reorder, remove or add sections, then **Approve and write content**.
7. **Preview**: switch between Mobile, Tablet and Desktop. Review the automatic accessibility fixes. Use **Regenerate** on any section with an instruction such as "make it shorter". **Save as template** keeps the page for reuse.
8. **Create draft**: the page is saved as a draft.
9. **Open in editor**: review, replace image placeholders, connect the form placeholder to your form plugin, then publish when ready.

Tips:

* Replace placeholders like `[Price]` or "Customer name" with real information. The plugin tells the AI not to invent facts, prices or testimonials.
* Every regeneration and draft can be undone through **Revisions** in the editor.

## 6. Templates, history and logs

* **Templates**: use, export (JSON) or delete saved templates. Import templates in **Import/Export**.
* **Generation History**: every request with its status, token usage and linked draft.
* **Logs**: error codes and timings for troubleshooting. Logs never contain keys, prompts or generated content.

## 7. Troubleshooting

| Message | What to do |
| --- | --- |
| "An administrator must review and accept the data sharing notice" | Accept the notice in **Privacy**. |
| "The AI provider is not configured yet" | Add a key and model in **AI Providers**. |
| "The AI service rejected the API key" | Check the key and its permissions with your provider. |
| "…did not respond in time" | Increase the timeout, reduce the number of sections, or try again. |
| "…cut off because it reached the maximum output tokens" | Increase **Maximum output tokens**. |
| "The API URL points to a private or reserved network address" | Use a public HTTPS URL, or allow local endpoints with the constant above. |
| "You reached the hourly limit" | Wait, or raise the limit in **API Settings**. |
| "The section was removed or renamed in the editor" | The section can no longer be matched; regenerate the page or edit it manually. |
