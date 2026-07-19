<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ValidatePaperlessWebhookRequest;
use App\Models\AppSetting;
use App\Models\SetupState;
use App\Services\Paperless\CanonicalPaperlessOrigin;
use App\Services\Paperless\PaperlessClient;
use App\Services\Settings\LegacySettingsImporter;
use App\Services\Setup\CompleteSetup;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SetupController extends Controller
{
    public function show(LegacySettingsImporter $legacySettingsImporter, CanonicalPaperlessOrigin $origin): Response
    {
        $state = SetupState::current();
        abort_if($state->is_complete, 404);

        $legacySettingsImporter->importMissing();

        return Inertia::render('Setup/Index', [
            'requiresResetToken' => $state->requiresResetToken(),
            'paperlessUrl' => $origin->url(),
            'deploymentWebhookSecretConfigured' => ValidatePaperlessWebhookRequest::secretIsUsable(
                config('archibot.paperless_webhook_secret', ''),
            ),
            'actions' => [
                'store' => route('setup.store'),
                'paperlessTags' => route('setup.paperless-tags'),
            ],
            'defaults' => [
                'inboxTagId' => AppSetting::getValue('paperless.inbox_tag_id', ''),
                'processedTagId' => AppSetting::getValue('paperless.processed_tag_id', ''),
                'ocrRequestedTagId' => AppSetting::getValue('ocr.requested_tag_id', ''),
            ],
        ]);
    }

    public function paperlessTags(Request $request, CanonicalPaperlessOrigin $origin): array
    {
        abort_if(SetupState::current()->is_complete, 404);

        $validated = $request->validate([
            'paperless_url' => ['nullable', 'string', 'max:2048'],
            'username' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        try {
            $origin->assertMatches($validated['paperless_url'] ?? null);
            $client = new PaperlessClient;
            $token = $client->createToken($validated['username'], $validated['password']);
            $user = $client->currentUser($token, $validated['username']);

            if (! $user->isSuperuser) {
                throw new RuntimeException('Setup must be completed by a Paperless superuser.');
            }

            return ['items' => $client->tags($token)];
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'paperless_url' => $exception->getMessage(),
            ]);
        }
    }

    public function store(Request $request, CompleteSetup $completeSetup, CanonicalPaperlessOrigin $origin): RedirectResponse
    {
        $state = SetupState::current();
        abort_if($state->is_complete, 404);

        $validated = $request->validate([
            'paperless_url' => ['nullable', 'string', 'max:2048'],
            'username' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:1024'],
            'webhook_secret' => [
                ValidatePaperlessWebhookRequest::secretIsUsable(config('archibot.paperless_webhook_secret', '')) ? 'nullable' : 'required',
                'string',
                'min:32',
                'max:1024',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! ValidatePaperlessWebhookRequest::secretIsUsable($value)) {
                        $fail('The webhook secret must be a generated secret, not a placeholder.');
                    }
                },
            ],
            'setup_token' => [$state->requiresResetToken() ? 'required' : 'nullable', 'string', 'max:255'],
            'paperless_inbox_tag_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'paperless_processed_tag_id' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'ocr_requested_tag_id' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
        ]);

        try {
            $origin->assertMatches($validated['paperless_url'] ?? null);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['paperless_url' => $exception->getMessage()]);
        }

        $state->refresh();
        $providedSetupToken = (string) $request->input('setup_token', '');

        if ($state->requiresResetToken()
            && (! $state->resetTokenIsValid() || $providedSetupToken === '' || ! Hash::check($providedSetupToken, $state->reset_token_hash))
        ) {
            throw ValidationException::withMessages([
                'setup_token' => 'The setup token is invalid or expired.',
            ]);
        }

        try {
            $completeSetup->handle($validated, $request);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'paperless_url' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('admin.settings.edit', ['section' => 'ai-provider'])
            ->with('status', 'Setup completed. Configure the installation-wide AI connection and validate its models.');
    }
}
