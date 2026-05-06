<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\SetupState;
use App\Services\Ollama\OllamaClient;
use App\Services\Paperless\PaperlessClient;
use App\Services\Settings\LegacySettingsImporter;
use App\Services\Setup\CompleteSetup;
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
            'ollamaUrl' => AppSetting::getValue('ollama.url', 'http://ollama:11434'),
            'defaults' => [
                'inboxTagId' => AppSetting::getValue('paperless.inbox_tag_id', ''),
                'processedTagId' => AppSetting::getValue('paperless.processed_tag_id', ''),
                'ocrRequestedTagId' => AppSetting::getValue('ocr.requested_tag_id', ''),
                'classificationModel' => AppSetting::getValue('classification.model', 'gemma4:e4b'),
                'embeddingModel' => AppSetting::getValue('embedding.model', 'qwen3-embedding:4b'),
                'ocrTextModel' => AppSetting::getValue('ocr.text_model', 'qwen3:4b'),
                'judgeModel' => AppSetting::getValue('classification.judge_model', 'qwen3:4b'),
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
            'ollama_url' => ['required', 'url'],
        ]);

        try {
            return ['items' => app(OllamaClient::class, ['baseUrl' => $validated['ollama_url']])->models()];
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
            'setup_token' => [$state->requiresResetToken() ? 'required' : 'nullable', 'string'],
            'paperless_inbox_tag_id' => ['required', 'integer', 'min:1'],
            'paperless_processed_tag_id' => ['nullable', 'integer', 'min:1'],
            'ocr_requested_tag_id' => ['nullable', 'integer', 'min:1'],
            'ollama_url' => ['required', 'url'],
            'classification_model' => ['required', 'string'],
            'embedding_model' => ['required', 'string'],
            'ocr_text_model' => ['nullable', 'string'],
            'classification_judge_model' => ['nullable', 'string'],
            'ocr_mode' => ['required', Rule::in(['off', 'text', 'vision_light', 'vision_full'])],
        ]);

        if ($state->requiresResetToken()
            && (! $state->resetTokenIsValid() || ! Hash::check($validated['setup_token'], $state->reset_token_hash))
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
