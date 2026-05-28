<?php

namespace App\Services\Paperless;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class PaperlessDocumentPermissions
{
    public function canChangeDocument(User $user, int $paperlessDocumentId): bool
    {
        if ((bool) $user->is_admin) {
            return true;
        }

        $token = $user->paperless_token;
        $paperlessUrl = AppSetting::getValue('paperless.url');
        if (! is_string($token) || $token === '' || ! is_string($paperlessUrl) || $paperlessUrl === '') {
            return false;
        }

        try {
            return app(PaperlessClient::class, ['baseUrl' => $paperlessUrl])
                ->canChangeDocument($token, $paperlessDocumentId);
        } catch (ConnectionException|Throwable) {
            return false;
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
}
