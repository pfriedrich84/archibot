<?php

namespace App\Services\Paperless;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

class PaperlessDocumentPermissions
{
    public function canViewDocument(User $user, int $paperlessDocumentId): bool
    {
        $context = $this->paperlessContext($user);
        if ($context === null) {
            return false;
        }

        try {
            app(PaperlessClient::class)
                ->document($context['token'], $paperlessDocumentId);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function canChangeDocument(User $user, int $paperlessDocumentId): bool
    {
        $context = $this->paperlessContext($user);
        if ($context === null) {
            return false;
        }

        try {
            return app(PaperlessClient::class)
                ->canChangeDocument($context['token'], $paperlessDocumentId);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function assertCanViewDocument(User $user, int $paperlessDocumentId): void
    {
        if (! $this->canViewDocument($user, $paperlessDocumentId)) {
            throw new AuthorizationException('Paperless document view permission could not be verified.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function assertCanChangeDocument(User $user, int $paperlessDocumentId): void
    {
        if (! $this->canChangeDocument($user, $paperlessDocumentId)) {
            throw new AuthorizationException('Paperless document change permission could not be verified.');
        }
    }

    /** @return array{token: string}|null */
    private function paperlessContext(User $user): ?array
    {
        $token = $user->paperless_token;
        if (! is_string($token) || $token === '') {
            return null;
        }

        return ['token' => $token];
    }
}
