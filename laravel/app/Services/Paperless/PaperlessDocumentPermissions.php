<?php

namespace App\Services\Paperless;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class PaperlessDocumentPermissions
{
    public function canViewDocument(User $user, int $paperlessDocumentId): bool
    {
        if ((bool) $user->is_admin) {
            return true;
        }

        $context = $this->paperlessContext($user);
        if ($context === null) {
            return false;
        }

        try {
            app(PaperlessClient::class, ['baseUrl' => $context['paperless_url']])
                ->document($context['token'], $paperlessDocumentId);

            return true;
        } catch (ConnectionException|Throwable) {
            return false;
        }
    }

    public function canChangeDocument(User $user, int $paperlessDocumentId): bool
    {
        if ((bool) $user->is_admin) {
            return true;
        }

        $context = $this->paperlessContext($user);
        if ($context === null) {
            return false;
        }

        try {
            return app(PaperlessClient::class, ['baseUrl' => $context['paperless_url']])
                ->canChangeDocument($context['token'], $paperlessDocumentId);
        } catch (ConnectionException|Throwable) {
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

    /**
     * @return array{paperless_url: string, token: string}|null
     */
    private function paperlessContext(User $user): ?array
    {
        $token = $user->paperless_token;
        $paperlessUrl = AppSetting::getValue('paperless.url');
        if (! is_string($token) || $token === '' || ! is_string($paperlessUrl) || $paperlessUrl === '') {
            return null;
        }

        return ['paperless_url' => $paperlessUrl, 'token' => $token];
    }
}
