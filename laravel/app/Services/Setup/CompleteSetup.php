<?php

namespace App\Services\Setup;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\SetupState;
use App\Models\User;
use App\Services\Paperless\PaperlessClient;
use App\Services\Settings\LegacySettingsImporter;
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

        $paperlessUrl = rtrim($data['paperless_url'], '/');
        $client = new PaperlessClient($paperlessUrl);
        $token = $client->createToken($data['username'], $data['password']);
        $paperlessUser = $client->currentUser($token, $data['username']);

        if (! $paperlessUser->isAdmin) {
            throw new RuntimeException('Setup must be completed by a Paperless superuser/admin.');
        }

        return DB::transaction(function () use ($data, $paperlessUrl, $token, $paperlessUser, $state, $request): User {
            $importedKeys = app(LegacySettingsImporter::class)->importMissing();

            AppSetting::put('paperless.url', $paperlessUrl);
            AppSetting::put('paperless.inbox_tag_id', (string) $data['paperless_inbox_tag_id']);
            AppSetting::put('paperless.processed_tag_id', (string) ($data['paperless_processed_tag_id'] ?? ''));
            AppSetting::put('ocr.requested_tag_id', (string) ($data['ocr_requested_tag_id'] ?? ''));
            AppSetting::put('ocr.mode', (string) $data['ocr_mode']);
            AppSetting::put('ollama.url', rtrim((string) $data['ollama_url'], '/'));
            AppSetting::put('classification.model', (string) $data['classification_model']);
            AppSetting::put('embedding.model', (string) $data['embedding_model']);
            AppSetting::put('ocr.text_model', (string) ($data['ocr_text_model'] ?? ''));
            AppSetting::put('classification.judge_model', (string) ($data['classification_judge_model'] ?? ''));

            $email = $paperlessUser->email ?: $paperlessUser->username.'@paperless.local';

            $user = User::query()->updateOrCreate(
                ['paperless_username' => $paperlessUser->username],
                [
                    'name' => $paperlessUser->displayName,
                    'email' => $email,
                    'paperless_user_id' => $paperlessUser->id,
                    'is_admin' => $paperlessUser->isAdmin,
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
                    'ollama_url' => rtrim((string) $data['ollama_url'], '/'),
                    'classification_model' => (string) $data['classification_model'],
                    'embedding_model' => (string) $data['embedding_model'],
                ],
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);

            Auth::login($user);

            return $user;
        });
    }
}
