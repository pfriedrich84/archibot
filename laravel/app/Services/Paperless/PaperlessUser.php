<?php

namespace App\Services\Paperless;

readonly class PaperlessUser
{
    public function __construct(
        public ?int $id,
        public string $username,
        public string $displayName,
        public ?string $email,
        public bool $isAdmin,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, ?string $fallbackUsername = null): self
    {
        $username = (string) ($payload['username'] ?? $fallbackUsername ?? $payload['name'] ?? 'paperless-user');
        $fullName = trim((string) ($payload['first_name'] ?? '').' '.(string) ($payload['last_name'] ?? ''));
        $displayName = (string) ($payload['display_name'] ?? $payload['name'] ?? ($fullName ?: $username));

        return new self(
            isset($payload['id']) ? (int) $payload['id'] : null,
            $username,
            $displayName,
            isset($payload['email']) ? (string) $payload['email'] : null,
            self::isAdminPayload($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromUiSettingsPayload(array $payload, ?string $fallbackUsername = null): ?self
    {
        $userPayload = $payload['user'] ?? null;

        if (! is_array($userPayload)) {
            return null;
        }

        if (isset($payload['permissions']) && is_array($payload['permissions'])) {
            $userPayload['permissions'] = $payload['permissions'];
        }

        return self::fromPayload($userPayload, $fallbackUsername);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function isAdminPayload(array $payload): bool
    {
        foreach (['is_superuser', 'is_staff', 'is_admin', 'admin', 'superuser'] as $key) {
            if (array_key_exists($key, $payload) && filter_var($payload[$key], FILTER_VALIDATE_BOOL)) {
                return true;
            }
        }

        $permissions = $payload['permissions'] ?? $payload['user_permissions'] ?? [];

        if (! is_array($permissions)) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (! is_string($permission)) {
                continue;
            }

            if (str_contains($permission, 'auth.') || str_contains($permission, 'paperless.')) {
                return true;
            }
        }

        return false;
    }
}
