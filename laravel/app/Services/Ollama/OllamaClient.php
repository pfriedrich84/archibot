<?php

namespace App\Services\Ollama;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $provider = 'ollama',
        private readonly ?string $apiKey = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function models(): array
    {
        $provider = strtolower(trim($this->provider));
        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->timeout(10);

        if ($provider === 'openai_compatible' && filled($this->apiKey)) {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->get($provider === 'openai_compatible' ? '/models' : '/api/tags');

        if (! $response->successful()) {
            throw new RuntimeException($provider === 'openai_compatible'
                ? 'Could not fetch OpenAI-compatible models.'
                : 'Could not fetch Ollama models.');
        }

        $models = $provider === 'openai_compatible' ? $response->json('data') : $response->json('models');

        if (! is_array($models)) {
            throw new RuntimeException($provider === 'openai_compatible'
                ? 'OpenAI-compatible models response was not JSON.'
                : 'Ollama models response was not JSON.');
        }

        return collect($models)
            ->map(fn ($model) => is_array($model) ? ($provider === 'openai_compatible' ? ($model['id'] ?? null) : ($model['name'] ?? null)) : null)
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->map(fn (string $name) => trim($name))
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
