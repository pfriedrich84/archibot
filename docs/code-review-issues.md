# Code Review Issue Drafts

These are ready-to-paste GitHub issues based on the recent review.

---

## 1) Protect `/setup` endpoints after initial setup (auth bypass risk)

**Title**

`Security: lock down /setup endpoints after first-run to prevent unauthenticated reconfiguration`

**Type/Labels**

- `bug`
- `security`
- `high priority`

**Problem**

`BasicAuthMiddleware` explicitly allows `/setup` routes, and `/setup/complete` currently does not reject writes once setup is already complete. This can allow unauthenticated config changes (tokens/URLs/etc.) after first-run.

**Affected areas**

- `app/main.py` (BasicAuthMiddleware setup bypass)
- `app/routes/setup.py` (`POST /setup/complete`)

**Proposed solution**

1. Restrict setup routes to first-run only:
   - In setup router, add a guard for mutating endpoints (`/complete`, maybe test endpoints) that returns `403` or redirect when `not needs_setup()`.
2. Keep setup page visible only while onboarding is incomplete.
3. Optional hardening:
   - Require Basic Auth for setup when GUI auth is configured, even during first-run.
   - Add CSRF protection for setup form posts.

**Acceptance criteria**

- `POST /setup/complete` is rejected when `needs_setup() == False`.
- Unauthenticated user cannot modify config after initial setup.
- Existing first-run onboarding still works.

---

## 2) Escape untrusted values in HTML fragments (XSS/HTML injection)

**Title**

`Security: sanitize/escape dynamic values in HTMLResponse fragments and setup/tag UI`

**Type/Labels**

- `bug`
- `security`
- `high priority`

**Problem**

Some routes build HTML strings with direct interpolation of exception text and external values, e.g. `Error: {exc}` and dynamic option/tag values. This can lead to HTML injection/XSS in HTMX responses.

**Affected areas**

- `app/routes/setup.py` (error and options HTML)
- `app/routes/tags.py` (error HTML)
- `app/templates/tags.html` (tag names used in URL segments)

**Proposed solution**

1. Stop string-concatenating HTML where possible; render Jinja templates/partials instead.
2. If inline HTML is necessary, escape all untrusted values with `html.escape(...)`.
3. Avoid returning raw exception strings to UI; use generic messages and log details server-side.
4. URL-encode path params or switch to ID-based endpoints.

**Acceptance criteria**

- No direct injection of exception text or external strings into raw HTML.
- Security tests confirm scripts/HTML in tag names or errors are escaped.
- UI behavior unchanged for normal users.

---

## 3) Preserve Telegram chat functionality after runtime config reload

**Title**

`Bug: runtime config reload restarts Telegram handler without Ollama dependency`

**Type/Labels**

- `bug`
- `medium priority`

**Problem**

On settings save, `apply_runtime_changes()` restarts Telegram with:

- `start_telegram(new_tg, paperless)`

but startup uses:

- `start_telegram(telegram, paperless, ollama)`

This can leave `_ollama` unset and break Telegram RAG chat after config changes.

**Affected area**

- `app/config_writer.py`

**Proposed solution**

- In `apply_runtime_changes()`, pass current Ollama client when restarting Telegram:
  - `ollama = getattr(app.state, "ollama", None)`
  - `start_telegram(new_tg, paperless, ollama)`
- Add regression test covering runtime settings save + Telegram chat path.

**Acceptance criteria**

- Telegram chat continues working after settings updates that trigger client recreation.
- New test fails on old behavior and passes with fix.

---

## 4) Handle special characters in tag actions (route robustness)

**Title**

`Bug: tag approve/reject routes fail for tag names with reserved URL characters`

**Type/Labels**

- `bug`
- `medium priority`

**Problem**

Tag actions use tag names directly in path segments (`/tags/{name}/approve`). Names containing `/`, `?`, `#`, etc. can break routing or target wrong endpoints.

**Affected areas**

- `app/templates/tags.html`
- `app/routes/tags.py`

**Proposed solution**

Option A (preferred):
- Switch action routes to use database IDs or opaque identifiers in path/query.

Option B:
- Keep name-based routing but URL-encode/decode safely and validate strict canonical form.

**Acceptance criteria**

- Approve/reject/unblacklist works for tags with spaces and reserved characters.
- Route tests added for edge-case tag names.

---

## 5) Reduce sensitive data exposure in webhook request logging

**Title**

`Security/Privacy: avoid logging raw webhook request bodies by default`

**Type/Labels**

- `security`
- `enhancement`
- `medium priority`

**Problem**

Webhook parser logs a raw body preview. Even truncated previews may contain sensitive document metadata/content.

**Affected area**

- `app/routes/webhook.py`

**Proposed solution**

1. Default logging to metadata only:
   - content-type, event name, document id, payload size.
2. Gate raw-body preview behind `DEBUG` log level and/or explicit env flag (`WEBHOOK_LOG_RAW_BODY=false` default).
3. Redact known sensitive keys (`token`, `secret`, etc.) before logging.

**Acceptance criteria**

- Production/default logs contain no raw payload text.
- Debug mode can still aid troubleshooting with controlled previews.

---

## 6) Set secure cookie attributes for chat session in HTTPS deployments

**Title**

`Enhancement: add secure cookie option for chat_session cookie`

**Type/Labels**

- `enhancement`
- `security`
- `low priority`

**Problem**

`chat_session` cookie is `HttpOnly` and `SameSite=Lax`, but `Secure` is not set. In HTTPS setups, `Secure` should be enabled.

**Affected area**

- `app/routes/chat.py`

**Proposed solution**

- Add config flag (e.g., `gui_cookie_secure`) or derive from request scheme/reverse-proxy headers.
- Set `secure=True` when running behind HTTPS.
- Optionally set explicit `max_age`/`expires` to align with session TTL.

**Acceptance criteria**

- In HTTPS deployments, cookie includes `Secure`.
- Local HTTP dev flow remains functional.

---

## Optional follow-up task

Create a consolidated `security-hardening` milestone and group issues 1, 2, and 5 for a single release.
