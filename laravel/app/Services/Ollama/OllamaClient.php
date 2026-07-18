<?php

namespace App\Services\Ollama;

use App\Services\Http\ResponseSizeGuard;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaClient
{
    private const VISION_CHALLENGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAIQAAAAsCAIAAAAraYdzAAAAo0lEQVR42u3YwRGAIAwAQfpvWlsIkmQQ9t6IyD5Qx6NtGrYAhmDAEAwYgnERxgg0ccuF4jPPriR3/TBgwIBxF0bPg+XOHBm5DlDIDAMGDBgwSgEiVyUcrTBgwIABI4iR+2lWx5m7nqydgQEDBoy7MPqP3FzOOpij3qZgwIABA8aGPzRhwIABA8Y/ML6Nr/vUhQEDBowTMNQfDBiCAUMwYAjGIb04LcpJRUnpvQAAAABJRU5ErkJggg==';

    private const VISION_CHALLENGE_SHA256 = '61dc1e5e1e79b8ab0fdd016f2333085e593c182b23051c162a36cb9b067c092f';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $provider = 'ollama',
        private readonly ?string $apiKey = null,
        private readonly ?string $visionChallengeBase64 = null,
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

    public function validateModel(string $model, string $role): void
    {
        $model = trim($model);
        if ($model === '' || strlen($model) > 255 || preg_match('/[\\x00-\\x1F\\x7F]/', $model)) {
            throw new RuntimeException('Model ID must be 1–255 characters without control characters.');
        }

        $provider = strtolower(trim($this->provider));
        $request = Http::baseUrl($this->validatedBaseUrl())
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->withoutRedirecting()
            ->withOptions([
                'decode_content' => true,
                'sink' => new ResponseSizeGuard(2097152, 'AI provider'),
            ]);

        if ($provider === 'openai_compatible' && filled($this->apiKey)) {
            $request = $request->withToken($this->apiKey);
        }

        if ($role === 'embedding') {
            $path = $provider === 'openai_compatible' ? '/embeddings' : '/api/embed';
            $payload = $provider === 'openai_compatible'
                ? ['model' => $model, 'input' => 'ArchiBot model validation', 'encoding_format' => 'float']
                : ['model' => $model, 'input' => 'ArchiBot model validation'];
        } elseif ($role === 'ocr_vision') {
            // This small PNG contains a four-character visual challenge. The
            // expected answer is deliberately absent from the prompt, so a
            // text-only model cannot pass by merely repeating prompt text.
            $image = $this->validatedVisionChallenge();
            $path = $provider === 'openai_compatible' ? '/chat/completions' : '/api/chat';
            $prompt = 'Read the four-character code shown in the attached image. Reply only as VISION_OK:<code>.';
            $payload = $provider === 'openai_compatible'
                ? [
                    'model' => $model,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.$image]],
                        ],
                    ]],
                    'stream' => false,
                    'max_tokens' => 12,
                ]
                : [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => $prompt, 'images' => [$image]]],
                    'stream' => false,
                ];
        } else {
            $path = $provider === 'openai_compatible' ? '/chat/completions' : '/api/chat';
            $payload = [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'Reply exactly ARCHIBOT_OK.']],
                'stream' => false,
            ];
            if ($provider === 'openai_compatible') {
                $payload['max_tokens'] = 8;
            }
        }

        $response = $request->post($path, $payload);
        if (! $response->successful()) {
            throw new RuntimeException("Model validation failed for '{$model}' ({$response->status()}). Check the model ID, role, credentials, and provider capabilities.");
        }

        if ($role === 'embedding') {
            $embedding = $provider === 'openai_compatible'
                ? $response->json('data.0.embedding')
                : ($response->json('embeddings.0') ?? $response->json('embedding'));
            if (! is_array($embedding) || $embedding === [] || collect($embedding)->contains(fn ($value) => ! is_numeric($value))) {
                throw new RuntimeException("Model validation failed for '{$model}': the provider did not return a numeric embedding.");
            }

            return;
        }

        $content = $provider === 'openai_compatible'
            ? $response->json('choices.0.message.content')
            : $response->json('message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException("Model validation failed for '{$model}': the provider returned no meaningful model response.");
        }
        if ($role === 'ocr_vision' && ! preg_match('/^\s*VISION_OK\s*:\s*A7K9\s*$/i', $content)) {
            throw new RuntimeException("Model validation failed for '{$model}': the model did not demonstrate vision support.");
        }
    }

    private function validatedVisionChallenge(): string
    {
        $encoded = $this->visionChallengeBase64 ?? self::VISION_CHALLENGE_BASE64;
        $bytes = base64_decode($encoded, true);
        $dimensions = is_string($bytes) ? @getimagesizefromstring($bytes) : false;

        if (! is_string($bytes)
            || hash('sha256', $bytes) !== self::VISION_CHALLENGE_SHA256
            || $dimensions === false
            || $dimensions[0] !== 132
            || $dimensions[1] !== 44
            || ($dimensions['mime'] ?? null) !== 'image/png') {
            throw new RuntimeException('OCR vision validation challenge image is corrupt.');
        }

        return $encoded;
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
