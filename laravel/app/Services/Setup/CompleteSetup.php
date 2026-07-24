<?php

namespace App\Services\Setup;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\SetupState;
use App\Models\User;
use App\Services\Paperless\CanonicalPaperlessOrigin;
use App\Services\Paperless\PaperlessClient;
use App\Services\Settings\LegacySettingsImporter;
use App\Services\Settings\PythonRuntimeConfigExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class CompleteSetup
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Request $request = null): User
    {
        $state = SetupState::current();

        if ($state->is_complete) {
            throw new RuntimeException('Setup is already complete.');
        }

        $origin = app(CanonicalPaperlessOrigin::class);
        $origin->assertMatches(isset($data['paperless_url']) ? (string) $data['paperless_url'] : null);
        $paperlessUrl = $origin->url();
        $client = new PaperlessClient;
        $token = $client->createToken($data['username'], $data['password']);
        $paperlessUser = $client->currentUser($token, $data['username']);

        if (! $paperlessUser->isSuperuser) {
            throw new RuntimeException('Setup must be completed by a Paperless superuser.');
        }

        return DB::transaction(function () use ($data, $paperlessUrl, $token, $paperlessUser, $state, $request): User {
            $importedKeys = app(LegacySettingsImporter::class)->importMissing();

            AppSetting::put('paperless.url', $paperlessUrl);
            if (trim((string) ($data['webhook_secret'] ?? '')) !== '') {
                AppSetting::put('webhook.secret', (string) $data['webhook_secret'], true);
            }
            AppSetting::put('paperless.inbox_tag_id', (string) $data['paperless_inbox_tag_id']);
            AppSetting::put('paperless.processed_tag_id', (string) ($data['paperless_processed_tag_id'] ?? ''));
            AppSetting::put('ocr.requested_tag_id', (string) ($data['ocr_requested_tag_id'] ?? ''));
            AppSetting::put('ocr.mode', 'off');
            AppSetting::put('paperless.ai_suggest_enabled', ! empty($data['paperless_ai_suggest_enabled']) ? '1' : '0');
            AppSetting::put('paperless.ai_similar_documents_enabled', ! empty($data['paperless_ai_similar_documents_enabled']) ? '1' : '0');
            AppSetting::put('paperless.ai_auto_manage_workflows', ! empty($data['paperless_ai_auto_manage_workflows']) ? '1' : '0');

            $email = $paperlessUser->email ?: $paperlessUser->username.'@paperless.local';

            $user = User::query()->updateOrCreate(
                ['paperless_username' => $paperlessUser->username],
                [
                    'name' => $paperlessUser->displayName,
                    'email' => $email,
                    'paperless_user_id' => $paperlessUser->id,
                    'is_admin' => $paperlessUser->isSuperuser,
                    'paperless_token' => $token,
                    'paperless_profile_refreshed_at' => now(),
                    'password' => Hash::make(Str::random(64)),
                    'email_verified_at' => now(),
                ],
            );

            $state->forceFill([
                'is_complete' => true,
                'reset_token_hash' => null,
                'reset_token_expires_at' => null,
                'completed_at' => now(),
            ])->save();

            AuditLog::query()->create([
                'actor_user_id' => $user->id,
                'event' => 'setup.completed',
                'target_type' => 'paperless_connection',
                'target_id' => 'global',
                'metadata' => [
                    'paperless_url' => $paperlessUrl,
                    'paperless_username' => $paperlessUser->username,
                    'paperless_user_id' => $paperlessUser->id,
                    'imported_setting_keys' => $importedKeys,
                    'paperless_inbox_tag_id' => (int) $data['paperless_inbox_tag_id'],
                    'ai_provider_configuration' => 'deferred_until_authenticated_admin_session',
                    'paperless_ai_suggest_enabled' => ! empty($data['paperless_ai_suggest_enabled']),
                    'paperless_ai_similar_documents_enabled' => ! empty($data['paperless_ai_similar_documents_enabled']),
                    'paperless_ai_auto_manage_workflows' => ! empty($data['paperless_ai_auto_manage_workflows']),
                ],
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);

            app(PythonRuntimeConfigExporter::class)->export([
                'PAPERLESS_URL' => $paperlessUrl,
                'PAPERLESS_TOKEN' => $token,
                'PAPERLESS_INBOX_TAG_ID' => (string) $data['paperless_inbox_tag_id'],
                'PAPERLESS_PROCESSED_TAG_ID' => (string) ($data['paperless_processed_tag_id'] ?? ''),
                'OCR_REQUESTED_TAG_ID' => (string) ($data['ocr_requested_tag_id'] ?? ''),
                'OCR_MODE' => 'off',
                'PAPERLESS_AI_SUGGEST_ENABLED' => ! empty($data['paperless_ai_suggest_enabled']) ? '1' : '0',
                'PAPERLESS_AI_SIMILAR_DOCUMENTS_ENABLED' => ! empty($data['paperless_ai_similar_documents_enabled']) ? '1' : '0',
                'PAPERLESS_AI_AUTO_MANAGE_WORKFLOWS' => ! empty($data['paperless_ai_auto_manage_workflows']) ? '1' : '0',
            ]);

            Auth::login($user);

            return $user;
        });
    }
}
