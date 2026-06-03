<?php

namespace App\Services\Webhooks;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PaperlessWebhookNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{event_type: string, webhook_action: string, paperless_document_id: int|null, paperless_modified: string|null}
     */
    public function normalize(array $payload): array
    {
        $eventType = $this->eventType($payload);

        return [
            'event_type' => $eventType,
            'webhook_action' => $this->webhookAction($eventType),
            'paperless_document_id' => $this->documentId($payload),
            'paperless_modified' => $this->paperlessModified($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function documentId(array $payload): ?int
    {
        foreach (['document_id', 'document.id', 'object.id', 'id'] as $key) {
            $value = Arr::get($payload, $key);
            if ($value !== null && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventType(array $payload): string
    {
        $event = Arr::get($payload, 'event') ?? Arr::get($payload, 'action') ?? Arr::get($payload, 'type');

        return Str::of((string) ($event ?: 'document.created'))->lower()->replace(' ', '_')->toString();
    }

    public function webhookAction(string $eventType): string
    {
        $normalized = $this->normalizedEventType($eventType);

        if ($this->eventTypeContainsAny($normalized, ['delete', 'deleted', 'trash', 'trashed'])) {
            return 'delete_embedding';
        }

        if ($this->eventTypeContainsAny($normalized, ['create', 'created', 'added', 'new', 'consume', 'consumed', 'import', 'imported'])) {
            return 'process_document';
        }

        if ($this->eventTypeContainsAny($normalized, ['update', 'updated', 'change', 'changed', 'modify', 'modified', 'edit', 'edited'])) {
            return 'refresh_embedding';
        }

        return 'process_document';
    }

    public function normalizedEventType(string $eventType): string
    {
        return Str::of($eventType)->lower()->replace(['.', '-', ' '], '_')->toString();
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function eventTypeContainsAny(string $normalizedEventType, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($normalizedEventType, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function paperlessModified(array $payload): ?string
    {
        $value = Arr::get($payload, 'document.modified')
            ?? Arr::get($payload, 'object.modified')
            ?? Arr::get($payload, 'modified');

        return $value === null ? null : (string) $value;
    }
}
