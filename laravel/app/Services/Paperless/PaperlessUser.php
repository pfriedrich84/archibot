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
        $displayName = (string) ($payload['display_name'] ?? $payload['name'] ?? $username);

        return new self(
            isset($payload['id']) ? (int) $payload['id'] : null,
            $username,
            $displayName,
            isset($payload['email']) ? (string) $payload['email'] : null,
            (bool) ($payload['is_superuser'] ?? $payload['is_staff'] ?? $payload['is_admin'] ?? false),
        );
    }
}
