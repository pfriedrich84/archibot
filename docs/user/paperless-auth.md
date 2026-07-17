# Paperless authentication and admin detection

Laravel authenticates users against Paperless-NGX with username/password and stores the returned API token on the local ArchiBot user.

## Current-user lookup

Use `App\Services\Paperless\PaperlessClient::currentUser($token, $fallbackUsername)` whenever Laravel needs the Paperless profile for a token.

Paperless-NGX's documented/frontend-backed profile source is `GET /api/ui_settings/`. Its response contains a top-level `user` object like:

```json
{
  "user": {
    "id": 7,
    "username": "admin",
    "is_staff": true,
    "is_superuser": true,
    "groups": []
  },
  "settings": {},
  "permissions": ["view_document", "change_document"]
}
```

`currentUser()` checks `/api/ui_settings/` first. An explicit boolean `is_superuser` value there is authoritative, including `false`. ArchiBot consults the compatibility endpoint `/api/users/me/` only after a successful `ui_settings` response that omits the field or an explicit `404 Not Found`. Authentication/authorization failures, rate limits, oversized or malformed responses, connection/timeout failures, and server errors fail closed without profile fallback. An explicit `is_superuser` value from `/api/users/me/` is likewise authoritative. `/api/users/?username=...` is used only when `/api/users/me/` returns `404` or successfully omits the field. If every available profile successfully omits `is_superuser`, ArchiBot fails closed with non-admin status.

## Superuser detection

ArchiBot derives its local `is_admin` value exclusively from Paperless's documented boolean `is_superuser` field. `is_staff`, `is_admin`, `admin`, group membership, broad UI-settings permissions, and similarly named adapter fields do not grant ArchiBot administration and cannot claim a new instance. The parsing is centralized in `App\Services\Paperless\PaperlessUser::fromPayload()`.

Setup requires a live Paperless profile with `is_superuser: true` from the deployment-pinned Paperless origin. Normal login does not require superuser status; the documented field only controls ArchiBot admin UI access.

## Review suggestion visibility and action permission checks

Webhook and poll ingestion are system-triggered, not user-triggered. They may process any Paperless document visible to the configured ArchiBot integration identity, but that does not grant every ArchiBot user access to the resulting review suggestion.

Non-admin users may only see review suggestions when ArchiBot can verify live Paperless document access for the specific document using that user's stored Paperless token. Detail and preview routes use the same fail-closed visibility boundary.

Non-admin users may edit, accept, reject, or bulk-review suggestions only when ArchiBot can verify live Paperless change permission for the specific document using that user's stored Paperless token.

The mutation check is fail-closed: missing Paperless URL, missing user token, network errors, `401`/`403`/`404`, or an ambiguous Paperless `OPTIONS /api/documents/{id}/` response without explicit `PATCH` or `PUT` capability all deny the ArchiBot review mutation. Admin users may perform ArchiBot job-control actions through local `is_admin()` authorization.
