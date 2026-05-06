<?php

namespace App\Services\Ollama;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaClient
{
    public function __construct(private readonly string $baseUrl) {}

    /**
     * @return array<int, string>
     */
    public function models(): array
    {
        $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->timeout(10)
            ->get('/api/tags');

        if (! $response->successful()) {
            throw new RuntimeException('Could not fetch Ollama models.');
        }

        $models = $response->json('models');

        if (! is_array($models)) {
            throw new RuntimeException('Ollama models response was not JSON.');
        }

        return collect($models)
            ->map(fn ($model) => is_array($model) ? ($model['name'] ?? null) : null)
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->map(fn (string $name) => trim($name))
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
