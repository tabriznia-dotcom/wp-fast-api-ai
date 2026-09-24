# REST API (`aipd/v1`)

All routes require an authenticated user. Cookie-authenticated requests must send the `X-WP-Nonce` header (`wp_rest` nonce), which `wp.apiFetch` does automatically in wp-admin. Every route has a `permission_callback`; responses never include API keys.

Errors use the standard WordPress format:

```json
{ "code": "aipd_cost_not_confirmed", "message": "This request may incur costs…", "data": { "status": 428 } }
```

Schema validation errors include `data.details` (up to 20 JSON paths with reasons).

## GET `/status`

Permission: `aipd_generate_pages` + `edit_pages`.

Returns what the wizard needs: consent state, active provider (`id`, `name`, `configured`, `paid`, `privacy`), whether cost confirmation is required, remaining hourly requests, builders with availability, fonts, style labels, brand kit, `can_manage`, site language.

## POST `/jobs`

Creates a generation job after all checks. Idempotent per `id`.

| Param | Type | Notes |
| --- | --- | --- |
| `id` | string (UUID v4) | Required. Client-generated idempotency key. |
| `type` | `outline` \| `page` \| `section` | Required. |
| `confirm_cost` | bool | Required `true` when the provider is paid and confirmation is enabled. |
| `brief` | object | Page brief (sanitized server-side). |
| `outline` | object | For `page`: `{ sections: [ { id, type, label, purpose } ] }`. |
| `tokens` | object | For `page`: design tokens (sanitized against the preset). |
| `schema` | object | For `section`: current Page Schema. |
| `section_id` | string | For `section`. |
| `instruction` | string | For `section`, max 1000 characters. |

Responses: `202` new job, `200` existing job (same id), `400` invalid input or provider not configured, `403` consent missing, `409` id used by another user, `428` cost not confirmed, `429` hourly limit reached.

## POST `/jobs/{id}/run`

Runs a queued job in the current request and returns it. Calling it again (or after the cron fallback ran it) returns the current state without sending another AI request.

## GET `/jobs/{id}`

Returns a job: `id`, `type`, `status` (`queued`, `running`, `completed`, `failed`), `provider`, `model`, `builder`, `title`, `error` (`code`, `message`), `tokens_in`, `tokens_out`, `post_id`, timestamps, and `result` when completed:

* `outline` jobs: `{ outline: { sections, notes } }`
* `page` jobs: `{ schema, warnings }`
* `section` jobs: `{ section, schema, warnings }`

When history is disabled, the stored brief and result are deleted right after this response.

## GET `/jobs`

Paginated history (`page`, `per_page` ≤ 100). Managers see all users, others only their own. Headers: `X-WP-Total`, `X-WP-TotalPages`.

## DELETE `/jobs/{id}`

Deletes a history entry (owner or manager).

## POST `/schema/validate`

Body: `{ schema }`. Returns `{ schema, warnings }` after the full sanitize/validate/normalize pipeline. No AI request.

## POST `/preview`

Body: `{ schema }`. Returns `{ html, schema, warnings }`. `html` is a complete document meant for an `<iframe sandbox srcdoc>`; it contains no scripts.

## POST `/tokens`

Body: `{ brief }`. Returns `{ tokens }`: style preset + brand kit + brief colors and fonts, sanitized. No AI request.

## POST `/drafts`

| Param | Type | Notes |
| --- | --- | --- |
| `schema` | object | Required; validated again. |
| `builder` | string | `gutenberg`, `elementor`, `classic` or a registered adapter id. |
| `post_type` | string | Default `page`; must be allowed by `aipd_post_types`. |
| `job_id` | string | Optional; links the draft to a history entry. |

Returns `201` with `post_id`, `status` (`draft`), `edit_url`, `preview_url`, `builder`.

## GET `/drafts/{post_id}/schema`

Returns the stored schema and builder of a generated page (requires `edit_post`).

## PUT `/drafts/{post_id}/sections/{section_id}`

Body: `{ schema }` containing the updated section. Replaces only that section in the post (creating a revision for block and classic content). Returns `409` if the user removed or renamed the section in the editor.

## Templates

* `GET /templates`: list (`id` is `builtin:{slug}` or `user:{post_id}`).
* `GET /templates/{id}`: `{ schema }`.
* `POST /templates`: `{ title, schema }` → `201 { id }`.
* `DELETE /templates/{id}`: user templates only; requires `aipd_manage_settings`.

## POST `/providers/{provider}/test`

Requires `aipd_manage_settings`. Returns `{ ok: true, models: [] }` or an error. Uses the provider's model list endpoint, which does not generate content.
