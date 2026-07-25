<?php

namespace App\Services\Paperless;

use App\Models\AppSetting;
use App\Models\PaperlessAiConfigState;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaperlessAiSettingsService
{
    public function desiredConfig(): array
    {
        $provider = AppSetting::getValue('llm.provider', 'ollama');
        $providerUrl = rtrim((string) AppSetting::getValue('ollama.url', 'http://ollama:11434'), '/');
        $embeddingModel = (string) AppSetting::getValue('embedding.model', 'qwen3-embedding:4b');
        $classificationModel = trim((string) AppSetting::getValue('paperless.ai_model_override', ''));
        if ($classificationModel === '') {
            $classificationModel = (string) AppSetting::getValue('classification.model', 'gemma4:e4b');
        }

        return [
            'manual_enabled' => AppSetting::getValue('paperless.ai_suggest_enabled', '1') !== '0',
            'similar_documents_enabled' => AppSetting::getValue('paperless.ai_similar_documents_enabled', '0') === '1',
            'suggest_endpoint' => rtrim((string) AppSetting::getValue('gui.base_url', ''), '/').'/paperless-ai/v1/completions-suggest',
            'suggest_model' => $classificationModel,
            'suggest_prompt_role' => 'paperless_suggest',
            'embedding_provider_type' => $provider,
            'embedding_provider_url' => $providerUrl,
            'embedding_model' => $embeddingModel,
            'embedding_context_max_docs' => (int) AppSetting::getValue('classification.context_max_docs', '5'),
            'embedding_context_max_distance' => (float) AppSetting::getValue('classification.context_max_distance', '0.5'),
            'bearer_key_configured' => AppSetting::getValue('paperless.ai_bearer_key') !== null
                && AppSetting::getValue('paperless.ai_bearer_key') !== '',
            'timeout_seconds' => (int) AppSetting::getValue('paperless.ai_endpoint_timeout_seconds', '120'),
        ];
    }

    public function fetchRemoteConfig(string $token): array
    {
        $response = Http::baseUrl(app(CanonicalPaperlessOrigin::class)->url())
            ->acceptJson()
            ->asJson()
            ->withToken($token, 'Token')
            ->withHeaders(['Accept' => 'application/json; version=10'])
            ->withoutRedirecting()
            ->timeout(20)
            ->get('/api/ui_settings/');

        if (! $response->successful()) {
            throw new RuntimeException('Could not fetch remote Paperless AI settings.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Paperless AI settings response was not JSON.');
        }

        $settings = Arr::get($payload, 'settings.ai_settings', Arr::get($payload, 'ai_settings', []));

        return is_array($settings) ? $settings : [];
    }

    public function detectDrift(array $desired, array $remote): array
    {
        $fields = [];

        foreach ([
            'manual_enabled',
            'similar_documents_enabled',
            'suggest_endpoint',
            'suggest_model',
            'embedding_provider_type',
            'embedding_provider_url',
            'embedding_model',
            'timeout_seconds',
        ] as $field) {
            if (($desired[$field] ?? null) !== ($remote[$field] ?? null)) {
                $fields[$field] = [
                    'desired' => $desired[$field] ?? null,
                    'remote' => $remote[$field] ?? null,
                ];
            }
        }

        return $fields;
    }

    public function refreshState(string $token): PaperlessAiConfigState
    {
        $desired = $this->desiredConfig();
        $remote = $this->fetchRemoteConfig($token);
        $drift = $this->detectDrift($desired, $remote);

        $state = PaperlessAiConfigState::query()->firstOrNew(['id' => 1]);
        $state->desired_config = $desired;
        $state->remote_config = $remote;
        $state->drift_fields = $drift;
        $state->sync_status = $drift === [] ? 'in_sync' : 'drift_detected';
        $state->last_remote_read_at = now();
        $state->save();

        return $state->fresh();
    }
}
