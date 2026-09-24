# Privacy

## What the plugin stores

| Data | Stored when | Retention | Personal data? |
| --- | --- | --- | --- |
| Settings, brand kit | Always | Until uninstall with data deletion | No |
| API keys | When entered | Until removed; encrypted | Secret, not personal |
| Consent record | When accepted | Until revoked | Admin user id and date |
| Generation history (brief, result, user id, timestamps, token usage) | History enabled (default) | Retention days (default 30), or immediately after delivery when history is disabled | Possibly, if a user types personal data into a brief |
| Logs (level, event code, redacted message and context, user id, timestamp) | Logging enabled (default) | Retention days | User id only |
| Generated pages | When a draft is created | Normal WordPress content | Your content |

Nothing is stored about site **visitors**. The plugin adds no cookies, tracking, analytics or telemetry, and loads no third-party resources on the front end.

## What is sent to the AI service

Only when a permitted user explicitly requests a generation (and confirms paid requests):

* the brief fields they typed (page type, title, topic, goal, business description, brand name, audience, tone, content language and direction, colors, call to action text and link, number of sections, notes);
* the approved outline and design tokens;
* for section regeneration: the page meta (title, language, style), the section content and the user's instruction.

Site content, users, orders, comments or other data are never sent automatically.

## WordPress privacy tools

* **Privacy policy guide**: suggested text is registered with `wp_add_privacy_policy_content()` (Settings > Privacy > Policy Guide).
* **Export Personal Data**: the "AI Page Designer history" exporter returns a user's requests (type, status, title, brief, date).
* **Erase Personal Data**: the eraser deletes the user's history and anonymizes their log entries.

## Administrator controls

* Accept or revoke the data sharing notice (revoking immediately blocks new requests).
* Disable generation history and/or logging.
* Retention period in days.
* Delete all plugin data on uninstall.
