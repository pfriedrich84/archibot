# Paperless authentication and admin detection

Laravel authenticates users against Paperless-NGX with username/password and stores the returned API token on the local ArchiBot user.

## Current-user lookup

Use `App\Services\Paperless\PaperlessClient::currentUser($token, $fallbackUsername)` whenever Laravel needs the Paperless profile for a token. It first calls `/api/users/me/`. If that endpoint is unavailable, or if the payload omits admin flags, it falls back to `/api/users/?username=...` and returns the enriched profile when available.

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
