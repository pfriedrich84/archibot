<?php

namespace App\Services\Paperless;

use Illuminate\Http\Client\ConnectionException;
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
        try {
            $response = $this->request()->post('/api/token/', [
                'username' => $username,
                'password' => $password,
            ]);
        } catch (ConnectionException $exception) {
            throw new PaperlessUnavailableException('Paperless server is not reachable.', previous: $exception);
        }

        if ($response->serverError()) {
            throw new PaperlessUnavailableException('Paperless server is not reachable.');
        }

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

    public function documentCount(string $token): int
    {
        $response = $this->request($token)->get('/api/documents/', [
            'page_size' => 1,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Could not fetch Paperless document count.');
        }

        $payload = $response->json();

        if (is_array($payload) && is_numeric($payload['count'] ?? null)) {
            return (int) $payload['count'];
        }

        if (is_array($payload)) {
            $items = $payload['results'] ?? $payload;

            return is_array($items) ? count($items) : 0;
        }

        throw new RuntimeException('Paperless documents response was not JSON.');
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

    public function documentOptions(string $token, int $documentId): Response
    {
        return $this->request($token)->send('OPTIONS', "/api/documents/{$documentId}/");
    }

    public function canChangeDocument(string $token, int $documentId): bool
    {
        $response = $this->documentOptions($token, $documentId);
        if (! $response->successful()) {
            return false;
        }

        if ($this->allowsWriteMethod($response)) {
            return true;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return false;
        }

        return $this->payloadAllowsDocumentChange($payload);
    }

    public function documentContent(string $token, int $documentId): string
    {
        $document = $this->document($token, $documentId);
        $content = $document['content'] ?? '';

        if (! is_string($content)) {
            throw new RuntimeException('Paperless document content was not a string.');
        }

        return $content;
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
        return $this->entities($token, '/api/tags/', 'tags');
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function correspondents(string $token): array
    {
        return $this->entities($token, '/api/correspondents/', 'correspondents');
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function documentTypes(string $token): array
    {
        return $this->entities($token, '/api/document_types/', 'document types');
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function storagePaths(string $token): array
    {
        return $this->entities($token, '/api/storage_paths/', 'storage paths');
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

        if ($response->serverError()) {
            throw new PaperlessUnavailableException('Paperless server is not reachable.');
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

    private function allowsWriteMethod(Response $response): bool
    {
        $allow = $response->header('Allow', '');
        if (! is_string($allow) || $allow === '') {
            return false;
        }

        $methods = collect(explode(',', $allow))
            ->map(fn (string $method) => strtoupper(trim($method)))
            ->filter()
            ->all();

        return in_array('PATCH', $methods, true) || in_array('PUT', $methods, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadAllowsDocumentChange(array $payload): bool
    {
        foreach (['actions.PATCH', 'actions.PUT'] as $key) {
            $action = data_get($payload, $key);
            if (is_array($action) && $action !== []) {
                return true;
            }
        }

        foreach (['allowed_methods', 'methods'] as $key) {
            $methods = data_get($payload, $key);
            if (is_array($methods)) {
                $normalized = array_map(fn ($method) => strtoupper((string) $method), $methods);
                if (in_array('PATCH', $normalized, true) || in_array('PUT', $normalized, true)) {
                    return true;
                }
            }
        }

        return false;
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

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function entities(string $token, string $endpoint, string $label): array
    {
        $items = [];
        $nextPath = $endpoint;
        $query = ['page_size' => 200];

        while ($nextPath !== null) {
            $response = $this->request($token)->get($nextPath, $query);

            if (! $response->successful()) {
                throw new RuntimeException("Could not fetch Paperless {$label}.");
            }

            $payload = $response->json();
            $pageItems = is_array($payload) ? ($payload['results'] ?? $payload) : [];

            if (! is_array($pageItems)) {
                throw new RuntimeException("Paperless {$label} response was not JSON.");
            }

            foreach ($pageItems as $item) {
                if (is_array($item) && isset($item['id'], $item['name'])) {
                    $items[] = [
                        'id' => (int) $item['id'],
                        'name' => (string) $item['name'],
                    ];
                }
            }

            $next = is_array($payload) ? ($payload['next'] ?? null) : null;
            $nextPath = is_string($next) && $next !== '' ? $next : null;
            $query = [];
        }

        return collect($items)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
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
