<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ValidatePaperlessWebhookRequest;
use App\Models\AppSetting;
use App\Models\SetupState;
use App\Services\Ollama\OllamaClient;
use App\Services\Paperless\PaperlessClient;
use App\Services\Settings\LegacySettingsImporter;
use App\Services\Setup\CompleteSetup;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SetupController extends Controller
{
    public function show(LegacySettingsImporter $legacySettingsImporter): Response
    {
        $state = SetupState::current();
        abort_if($state->is_complete, 404);

        $legacySettingsImporter->importMissing();

        return Inertia::render('Setup/Index', [
            'requiresResetToken' => $state->requiresResetToken(),
            'paperlessUrl' => AppSetting::getValue('paperless.url', ''),
            'llmProvider' => AppSetting::getValue('llm.provider', 'ollama'),
            'ollamaUrl' => AppSetting::getValue('ollama.url', 'http://ollama:11434'),
            'deploymentWebhookSecretConfigured' => ValidatePaperlessWebhookRequest::secretIsUsable(
                config('archibot.paperless_webhook_secret', ''),
            ),
            'defaults' => [
                'inboxTagId' => AppSetting::getValue('paperless.inbox_tag_id', ''),
                'processedTagId' => AppSetting::getValue('paperless.processed_tag_id', ''),
                'ocrRequestedTagId' => AppSetting::getValue('ocr.requested_tag_id', ''),
                'classificationModel' => AppSetting::getValue('classification.model', ''),
                'embeddingModel' => AppSetting::getValue('embedding.model', ''),
                'ocrTextModel' => AppSetting::getValue('ocr.text_model', ''),
                'judgeModel' => AppSetting::getValue('classification.judge_model', ''),
            ],
        ]);
    }

    public function paperlessTags(Request $request): array
    {
        abort_if(SetupState::current()->is_complete, 404);

        $validated = $request->validate([
            'paperless_url' => ['required', 'url'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        try {
            $client = new PaperlessClient(rtrim($validated['paperless_url'], '/'));
            $token = $client->createToken($validated['username'], $validated['password']);
            $user = $client->currentUser($token, $validated['username']);

            if (! $user->isAdmin) {
                throw new RuntimeException('Setup must be completed by a Paperless superuser/admin.');
            }

            return ['items' => $client->tags($token)];
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'paperless_url' => $exception->getMessage(),
            ]);
        }
    }

    public function ollamaModels(Request $request): array
    {
        abort_if(SetupState::current()->is_complete, 404);

        $validated = $request->validate([
            'llm_provider' => ['nullable', Rule::in(['ollama', 'openai_compatible'])],
            'ollama_url' => ['required', 'url'],
            'openai_api_key' => ['nullable', 'string'],
        ]);

        try {
            return ['items' => app(OllamaClient::class, [
                'baseUrl' => $validated['ollama_url'],
                'provider' => $validated['llm_provider'] ?? 'ollama',
                'apiKey' => $validated['openai_api_key'] ?? null,
            ])->models()];
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'ollama_url' => $exception->getMessage(),
            ]);
        }
    }

    public function store(Request $request, CompleteSetup $completeSetup): RedirectResponse
    {
        $state = SetupState::current();
        abort_if($state->is_complete, 404);

        $validated = $request->validate([
            'paperless_url' => ['required', 'url'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'webhook_secret' => [
                ValidatePaperlessWebhookRequest::secretIsUsable(config('archibot.paperless_webhook_secret', '')) ? 'nullable' : 'required',
                'string',
                'min:32',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! ValidatePaperlessWebhookRequest::secretIsUsable($value)) {
                        $fail('The webhook secret must be a generated secret, not a placeholder.');
                    }
                },
            ],
            'setup_token' => [$state->requiresResetToken() ? 'required' : 'nullable', 'string'],
            'paperless_inbox_tag_id' => ['required', 'integer', 'min:1'],
            'paperless_processed_tag_id' => ['nullable', 'integer', 'min:1'],
            'ocr_requested_tag_id' => ['nullable', 'integer', 'min:1'],
            'llm_provider' => ['required', Rule::in(['ollama', 'openai_compatible'])],
            'ollama_url' => ['required', 'url'],
            'openai_api_key' => ['nullable', 'string'],
            'classification_model' => ['required', 'string'],
            'embedding_model' => ['required', 'string'],
            'ocr_text_model' => ['nullable', 'string'],
            'classification_judge_model' => ['nullable', 'string'],
            'ocr_mode' => ['required', Rule::in(['off', 'text', 'vision_light', 'vision_full'])],
        ]);

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

        return redirect()->route('dashboard');
    }
}
