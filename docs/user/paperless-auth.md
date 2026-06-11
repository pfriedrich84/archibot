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

`currentUser()` therefore checks `/api/ui_settings/` first and parses admin flags from that payload. Upstream Paperless-NGX currently emits Django-style `user.is_staff` and `user.is_superuser` there; ArchiBot also accepts `user.is_admin` for deployments or adapters that expose that name. Older experimental fallbacks remain in place: `/api/users/me/`, then `/api/users/?username=...` if `/users/me/` is unavailable or omits admin flags.

## Admin detection helper

Admin/superuser detection is centralized in:

```php
App\Services\Paperless\PaperlessUser::isAdminPayload(array $payload): bool
```

Reuse this helper for future Paperless profile payloads instead of duplicating field checks. It currently accepts these common Paperless/Django-style flags when truthy:

- `is_superuser`
- `is_staff`
- `is_admin`
- `admin`
- `superuser`

As a fallback, permission arrays named `permissions` or `user_permissions` are treated as administrative when they contain Paperless/Django permission strings such as `auth.*` or `paperless.*`.

Setup still requires an admin/superuser profile. Normal login does not require admin; the detected value only controls ArchiBot admin UI access.

## Review suggestion visibility and action permission checks

Webhook and poll ingestion are system-triggered, not user-triggered. They may process any Paperless document visible to the configured ArchiBot integration identity, but that does not grant every ArchiBot user access to the resulting review suggestion.

Non-admin users may only see review suggestions when ArchiBot can verify live Paperless document access for the specific document using that user's stored Paperless token. Detail and preview routes use the same fail-closed visibility boundary.

Non-admin users may edit, accept, reject, or bulk-review suggestions only when ArchiBot can verify live Paperless change permission for the specific document using that user's stored Paperless token.

The mutation check is fail-closed: missing Paperless URL, missing user token, network errors, `401`/`403`/`404`, or an ambiguous Paperless `OPTIONS /api/documents/{id}/` response without explicit `PATCH` or `PUT` capability all deny the ArchiBot review mutation. Admin users may perform ArchiBot job-control actions through local `is_admin()` authorization.
