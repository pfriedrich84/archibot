<?php

namespace App\Services\Paperless;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
    public function ping(string $token): bool
    {
        return $this->request($token)->get('/api/ui_settings/')->status() < 500;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function documents(string $token, int $inboxTagId, int $pageSize = 25): array
    {
        $response = $this->request($token)->get('/api/documents/', [
            'tags__id__all' => $inboxTagId,
            'page_size' => $pageSize,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Could not fetch Paperless documents.');
        }

        $payload = $response->json();
        $items = is_array($payload) ? ($payload['results'] ?? $payload) : [];

        if (! is_array($items)) {
            throw new RuntimeException('Paperless documents response was not JSON.');
        }

        return array_values(array_filter($items, fn ($item) => is_array($item)));
    }

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

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function tags(string $token): array
    {
        $response = $this->request($token)->get('/api/tags/', ['page_size' => 200]);

        if (! $response->successful()) {
            throw new RuntimeException('Could not fetch Paperless tags.');
        }

        $payload = $response->json();
        $items = is_array($payload) ? ($payload['results'] ?? $payload) : [];

        if (! is_array($items)) {
            throw new RuntimeException('Paperless tags response was not JSON.');
        }

        return collect($items)
            ->filter(fn ($item) => is_array($item) && isset($item['id'], $item['name']))
            ->map(fn (array $item): array => [
                'id' => (int) $item['id'],
                'name' => (string) $item['name'],
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function createTag(string $token, string $name): int
    {
        return $this->createEntity($token, '/api/tags/', $name, 'tag');
    }

    public function createCorrespondent(string $token, string $name): int
    {
        return $this->createEntity($token, '/api/correspondents/', $name, 'correspondent');
    }

    public function createDocumentType(string $token, string $name): int
    {
        return $this->createEntity($token, '/api/document_types/', $name, 'document type');
    }

    public function currentUser(string $token, ?string $fallbackUsername = null): PaperlessUser
    {
        $user = $this->currentUserFromUiSettingsEndpoint($token, $fallbackUsername);

        if ($user instanceof PaperlessUser) {
            return $user;
        }

        $response = $this->request($token)->get('/api/users/me/');

        if ($response->status() === 404) {
            $user = $this->currentUserFromUsersEndpoint($token, $fallbackUsername);

            if ($user instanceof PaperlessUser) {
                return $user;
            }
        }

        if (! $response->successful()) {
            throw new RuntimeException('Could not fetch Paperless user profile.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Paperless user profile response was not JSON.');
        }

        $user = PaperlessUser::fromPayload($payload, $fallbackUsername);

        if (! $user->isAdmin) {
            $enrichedUser = $this->currentUserFromUsersEndpoint($token, $user->username);

            if ($enrichedUser instanceof PaperlessUser) {
                return $enrichedUser;
            }
        }

        return $user;
    }

    private function currentUserFromUiSettingsEndpoint(string $token, ?string $fallbackUsername): ?PaperlessUser
    {
        try {
            $response = $this->request($token)->get('/api/ui_settings/');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        return PaperlessUser::fromUiSettingsPayload($payload, $fallbackUsername);
    }

    private function currentUserFromUsersEndpoint(string $token, ?string $fallbackUsername): ?PaperlessUser
    {
        if (! $fallbackUsername) {
            return null;
        }

        try {
            $response = $this->request($token)->get('/api/users/', [
                'username' => $fallbackUsername,
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json('results.0') ?? $response->json('0') ?? [];

        if (! is_array($payload) || $payload === []) {
            return null;
        }

        return PaperlessUser::fromPayload($payload, $fallbackUsername);
    }

    private function createEntity(string $token, string $endpoint, string $name, string $label): int
    {
        $response = $this->request($token)->post($endpoint, ['name' => $name]);

        if (! $response->successful()) {
            throw new RuntimeException("Could not create Paperless {$label}.");
        }

        $id = $response->json('id');

        if (! is_numeric($id)) {
            throw new RuntimeException("Paperless {$label} response did not include an id.");
        }

        return (int) $id;
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
