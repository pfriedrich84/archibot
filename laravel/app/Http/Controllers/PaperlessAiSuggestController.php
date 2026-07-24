<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\Ollama\OllamaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaperlessAiSuggestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->authorized($request), 401);
        abort_if(AppSetting::getValue('paperless.ai_suggest_enabled', '1') === '0', 409, 'Paperless AI Suggest is disabled.');

        $validated = $request->validate([
            'model' => ['nullable', 'string', 'max:255'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:system,user'],
            'messages.*.content' => ['required', 'string'],
            'stream' => ['nullable', 'boolean'],
        ]);

        $requestedModel = trim((string) ($validated['model'] ?? ''));
        abort_if($requestedModel !== '', 422, 'Model override is not allowed on this endpoint.');

        $classificationEngine = (string) AppSetting::getValue('classification.model', '');
        abort_if($classificationEngine === '', 503, 'No classification engine is configured.');

        $provider = (string) AppSetting::getValue('llm.provider', 'ollama');
        $baseUrl = $provider === 'openai_compatible'
            ? (string) AppSetting::getValue('llm.openai_base_url', 'https://api.openai.com/v1')
            : (string) AppSetting::getValue('ollama.url', 'http://ollama:11434');
        $apiKey = $provider === 'openai_compatible'
            ? AppSetting::getValue('llm.openai_api_key')
            : null;

        $response = app(OllamaClient::class, [
             'baseUrl' => $baseUrl,
             'provider' => $provider,
             'apiKey' => $apiKey ?: null,
-        ])->chatCompletion($model, $validated['messages']);
+        ])->chatCompletion($classificationEngine, $validated['messages']);
            'baseUrl' => $baseUrl,
            'provider' => $provider,
            'apiKey' => $apiKey ?: null,
        ])->chatCompletion($model, $validated['messages']);

        return response()->json($response);
    }

    private function authorized(Request $request): bool
    {
        $configured = trim((string) AppSetting::getValue('paperless.ai_bearer_key', ''));
        if ($configured === '') {
            return false;
        }

        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return false;
        }

        return hash_equals($configured, substr($header, 7));
    }
}
