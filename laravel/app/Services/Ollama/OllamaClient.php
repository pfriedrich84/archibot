<?php

namespace App\Services\Ollama;

use App\Services\Http\ResponseSizeGuard;
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
        $baseUrl = $this->validatedBaseUrl();
        $maxBytes = 2097152;
        $request = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(10)
            ->withoutRedirecting()
            ->withOptions([
                'decode_content' => true,
                'sink' => new ResponseSizeGuard($maxBytes, 'AI provider'),
            ]);

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

    private function validatedBaseUrl(): string
    {
        $url = rtrim(trim($this->baseUrl), '/');
        $parts = parse_url($url);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('AI provider URL must be http(s) without credentials, query, or fragment.');
        }

        return $url;
    }
}
