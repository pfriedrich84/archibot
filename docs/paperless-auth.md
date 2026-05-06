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

`currentUser()` therefore checks `/api/ui_settings/` first and parses `user.is_staff` / `user.is_superuser` from that payload. Older experimental fallbacks remain in place: `/api/users/me/`, then `/api/users/?username=...` if `/users/me/` is unavailable or omits admin flags.

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
