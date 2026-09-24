# Security architecture

## Threat model

| Threat | Entry point | Controls |
| --- | --- | --- |
| Unauthorized use of AI budget or settings | REST routes, admin screens, admin-post actions | Custom capabilities (`aipd_generate_pages`, `aipd_manage_settings`), `current_user_can()` on every route/handler, `permission_callback` on every REST route, nonce verification (`check_admin_referer()`, REST `wp_rest` nonce) |
| CSRF | Forms and REST | Nonces on all forms; cookie-authenticated REST requires `X-WP-Nonce` |
| XSS through model output | Page Schema text, links, attributes | Schema allowlist; `wp_kses` inline allowlist (no `script`, `style`, `iframe`, `form`, `svg`, event handlers, even through filters); script/style bodies stripped; link protocol allowlist (`http`, `https`, `mailto`, `tel`, `#anchor`, site-relative); late escaping (`esc_html`, `esc_attr`, `esc_url`) in every renderer and screen |
| Shortcode injection | Model text rendered through `the_content` or Elementor | Square brackets converted to entities at output (`Kses::text()`, `Kses::html()`, `Kses::page()`) |
| Code execution | Model output | The model can only fill a JSON schema. No `eval`, no dynamic includes, no PHP/JS/SQL generation. The autoloader only maps `[A-Za-z0-9_\\]` class names to files inside `includes/` |
| Prompt injection | Brief fields | User data is JSON-encoded inside `<<<BRIEF_DATA … BRIEF_DATA>>>` markers (markers stripped from data); system rules state that the data is not instructions; the `aipd_system_prompt` filter cannot remove the core rules; output is validated regardless |
| SSRF | API base URL, remote images | HTTPS only; no credentials, query or fragment; path traversal blocked; DNS resolved and every address must be public (no private, loopback, link-local/metadata or reserved ranges); `wp_safe_remote_request()` with `redirection = 0`; validated again at request time; local endpoints only with `AIPD_ALLOW_LOCAL_ENDPOINTS` |
| Secret disclosure | Settings, REST, logs, hooks, errors | Keys encrypted with libsodium `secretbox` (key derived from WordPress salts or `AIPD_ENCRYPTION_KEY`); password fields are never pre-filled; `get_public_settings()` reports only `is_set`; `Redactor` masks keys, bearer tokens and long secrets in logs and provider errors; hooks receive summaries only; exports never include keys; optional wp-config constants |
| SQL injection | Jobs, logs | `$wpdb->prepare()` with `%i` identifiers and typed placeholders, or `$wpdb->insert()/update()/delete()` with formats |
| Path traversal | Templates | Built-in templates resolved from a fixed map with `realpath()` and extension check; user templates addressed by numeric post id |
| Malicious uploads | Template/settings import | `is_uploaded_file()`, size limits (512 KB / 64 KB), `.json` extension, MIME sniffing (`finfo`), JSON depth limit, full schema validation; the file is read from the temp location and never stored in uploads |
| Denial of wallet | Repeated requests | Per-request cost confirmation, idempotent jobs (UUID), per-user hourly limit, bounded retries and time budget |
| Data leakage to the AI service | Brief | Only brief fields are sent; no site content; UI warning against personal data; consent required |

## Output model

```
untrusted text ─▶ decode (size limit) ─▶ prefill ─▶ sanitize (allowlists) ─▶ validate (JSON Schema)
                                                                                   │
                                   normalize (a11y, RTL, contrast) ◀────────────────┘
                                                   │
                                  adapter renders with late escaping ─▶ draft post
```

The same pipeline runs for AI output, browser-submitted schemas, templates and imports.

## Capabilities and roles

See [architecture.md](architecture.md#8-capabilities). Publishing is never performed by the plugin; drafts are forced to `draft` even if a filter requests another status.

## Reporting vulnerabilities

Please report security issues privately through the repository's security advisory feature rather than public issues.
