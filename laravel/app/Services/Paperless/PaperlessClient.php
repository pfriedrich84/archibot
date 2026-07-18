<?php

namespace App\Services\Paperless;

use App\Services\Http\ResponseSizeGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PaperlessClient
{
    private readonly string $baseUrl;

    private readonly CanonicalPaperlessOrigin $canonicalOrigin;

    public function __construct()
    {
        $this->canonicalOrigin = app(CanonicalPaperlessOrigin::class);
        $this->baseUrl = $this->canonicalOrigin->url();
    }

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

    /** @param array<string, mixed> $fields */
    public function patchDocument(string $token, int $documentId, array $fields): void
    {
        $response = $this->request($token)->patch("/api/documents/{$documentId}/", $fields);
        if (! $response->successful()) {
            throw new RuntimeException('Could not update Paperless document.');
        }
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
        $maxBytes = max(1024, (int) config('archibot.paperless_http_max_preview_bytes', 52428800));

        return $this->request($token, $maxBytes)
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

    public function findTagByName(string $token, string $name): ?int
    {
        return $this->findEntityByName($token, '/api/tags/', $name, 'tag');
    }

    public function findCorrespondentByName(string $token, string $name): ?int
    {
        return $this->findEntityByName($token, '/api/correspondents/', $name, 'correspondent');
    }

    public function findDocumentTypeByName(string $token, string $name): ?int
    {
        return $this->findEntityByName($token, '/api/document_types/', $name, 'document type');
    }

    public function currentUser(string $token, ?string $fallbackUsername = null): PaperlessUser
    {
        // Compatibility fallback is intentionally narrow: only a successful
        // ui_settings response that omits is_superuser, or an explicit 404,
        // may consult older profile endpoints. All other failures stay closed.
        $uiResponse = $this->request($token)->get('/api/ui_settings/');

        if ($uiResponse->status() !== 404 && ! $uiResponse->successful()) {
            if ($uiResponse->serverError()) {
                throw new PaperlessUnavailableException('Paperless server is not reachable.');
            }

            throw new RuntimeException('Could not fetch Paperless UI settings.');
        }

        $uiUser = null;
        if ($uiResponse->successful()) {
            $uiPayload = $uiResponse->json();
            if (! is_array($uiPayload)) {
                throw new RuntimeException('Paperless UI settings response was not JSON.');
            }

            $uiUser = PaperlessUser::fromUiSettingsPayload($uiPayload, $fallbackUsername);
            if ($uiUser instanceof PaperlessUser && $uiUser->hasSuperuserField) {
                return $uiUser;
            }
        }

        $profileUsername = $uiUser?->username ?? $fallbackUsername;
        $response = $this->request($token)->get('/api/users/me/');

        if ($response->status() === 404) {
            $user = $this->currentUserFromUsersEndpoint($token, $profileUsername);

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

        $user = PaperlessUser::fromPayload($payload, $profileUsername);

        if ($user->hasSuperuserField) {
            return $user;
        }

        return $this->currentUserFromUsersEndpoint($token, $user->username) ?? $user;
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

        $user = PaperlessUser::fromPayload($payload, $fallbackUsername);
        if (! hash_equals(mb_strtolower($fallbackUsername), mb_strtolower($user->username))) {
            return null;
        }

        return $user;
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
            $nextPath = is_string($next) && $next !== '' ? $this->safePaginationPath($next) : null;
            $query = [];
        }

        return collect($items)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function safePaginationPath(string $next): string
    {
        if (str_starts_with($next, '/')) {
            if (str_starts_with($next, '//')) {
                throw new RuntimeException('Paperless pagination attempted to leave the configured origin.');
            }

            return $next;
        }

        if (! $this->canonicalOrigin->isSameOriginUrl($next)) {
            throw new RuntimeException('Paperless pagination attempted to leave the configured origin.');
        }

        $parts = parse_url($next);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
        $query = is_array($parts) && isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    private function findEntityByName(string $token, string $endpoint, string $name, string $label): ?int
    {
        $response = $this->request($token)->get($endpoint, ['name__iexact' => $name, 'page_size' => 2]);
        if (! $response->successful()) {
            throw new RuntimeException("Could not look up Paperless {$label}.");
        }
        $payload = $response->json();
        $items = is_array($payload) ? ($payload['results'] ?? $payload) : [];
        if (! is_array($items)) {
            throw new RuntimeException("Paperless {$label} lookup response was not JSON.");
        }
        foreach ($items as $item) {
            if (is_array($item)
                && is_numeric($item['id'] ?? null)
                && is_string($item['name'] ?? null)
                && mb_strtolower(trim($item['name'])) === mb_strtolower(trim($name))) {
                return (int) $item['id'];
            }
        }
        return null;
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

    private function request(?string $token = null, ?int $maxBytes = null): PendingRequest
    {
        $maxBytes ??= max(1024, (int) config('archibot.paperless_http_max_response_bytes', 2097152));
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(min(5, max(1, (int) config('archibot.paperless_http_timeout_seconds', 10))))
            ->timeout(max(1, (int) config('archibot.paperless_http_timeout_seconds', 10)))
            ->withoutRedirecting()
            ->withOptions([
                // Handlers write the decoded entity body to the bounded sink. Keep
                // decoding explicit so compressed transfer bytes cannot bypass it.
                'decode_content' => true,
                'sink' => new ResponseSizeGuard($maxBytes, 'Paperless'),
            ])
            ->withHeaders(['Accept' => 'application/json; version=5']);

        if ($token) {
            $request = $request->withToken($token, 'Token');
        }

        return $request;
    }
}
