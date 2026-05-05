<?php

namespace App\Services\Paperless;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaperlessClient
{
    public function __construct(private readonly string $baseUrl) {}

    /**
     * Authenticate with Paperless username/password and return an API token.
     */
    public function createToken(string $username, string $password): string
    {
        $response = $this->request()->post('/api/token/', [
            'username' => $username,
            'password' => $password,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Paperless username/password authentication failed.');
        }

        $token = $response->json('token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Paperless token endpoint did not return a token.');
        }

        return $token;
    }

    /**
     * Fetching a single document is the live per-document permission check.
     * Paperless returns 403/404 when the token cannot access the document.
     *
     * @return array<string, mixed>
     */
    public function document(string $token, int $documentId): array
    {
        $response = $this->request($token)->get("/api/documents/{$documentId}/");

        if (! $response->successful()) {
            throw new RuntimeException('Could not access Paperless document.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Paperless document response was not JSON.');
        }

        return $payload;
    }

    public function documentPreview(string $token, int $documentId): Response
    {
        return $this->request($token)
            ->accept('*/*')
            ->timeout(120)
            ->get("/api/documents/{$documentId}/preview/");
    }

    public function currentUser(string $token, ?string $fallbackUsername = null): PaperlessUser
    {
        $response = $this->request($token)->get('/api/users/me/');

        if ($response->status() === 404) {
            $response = $this->request($token)->get('/api/users/', [
                'username' => $fallbackUsername,
            ]);

            if ($response->successful()) {
                $payload = $response->json('results.0') ?? $response->json('0') ?? [];

                if (is_array($payload) && $payload !== []) {
                    return PaperlessUser::fromPayload($payload, $fallbackUsername);
                }
            }
        }

        if (! $response->successful()) {
            throw new RuntimeException('Could not fetch Paperless user profile.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Paperless user profile response was not JSON.');
        }

        return PaperlessUser::fromPayload($payload, $fallbackUsername);
    }

    private function request(?string $token = null): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->withHeaders(['Accept' => 'application/json; version=5']);

        if ($token) {
            $request = $request->withToken($token, 'Token');
        }

        return $request;
    }
}
