<?php

namespace App\Services\Paperless;

readonly class PaperlessUser
{
    public function __construct(
        public ?int $id,
        public string $username,
        public string $displayName,
        public ?string $email,
        public bool $isSuperuser,
        public bool $hasSuperuserField,
    ) {}

    /** @param array<string, mixed> $payload */
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
            ($payload['is_superuser'] ?? null) === true,
            array_key_exists('is_superuser', $payload),
        );
    }

    /** @param array<string, mixed> $payload */
    public static function fromUiSettingsPayload(array $payload, ?string $fallbackUsername = null): ?self
    {
        $userPayload = $payload['user'] ?? null;

        return is_array($userPayload) ? self::fromPayload($userPayload, $fallbackUsername) : null;
    }
}
