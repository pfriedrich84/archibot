<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('webhook_deliveries')->orderBy('id')->cursor() as $delivery) {
            $normalizedPayload = $this->decodeJson($delivery->normalized_payload ?? null);
            if ($normalizedPayload !== null && array_key_exists('webhook_action', $normalizedPayload)) {
                continue;
            }

            $rawPayload = $this->decodeJson($delivery->raw_payload ?? null);
            $eventType = (string) ($delivery->event_type ?: $this->eventType($rawPayload));
            $normalizedPayload ??= [];
            $normalizedPayload['webhook_action'] = $this->webhookAction($eventType);

            $this->updateNormalizedPayload((int) $delivery->id, $normalizedPayload);
        }
    }

    public function down(): void
    {
        foreach (DB::table('webhook_deliveries')->orderBy('id')->cursor() as $delivery) {
            $normalizedPayload = $this->decodeJson($delivery->normalized_payload ?? null);
            if ($normalizedPayload === null || ! array_key_exists('webhook_action', $normalizedPayload)) {
                continue;
            }

            unset($normalizedPayload['webhook_action']);

            $this->updateNormalizedPayload((int) $delivery->id, $normalizedPayload);
        }
    }

    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function eventType(?array $payload): string
    {
        $event = Arr::get($payload ?? [], 'event')
            ?? Arr::get($payload ?? [], 'action')
            ?? Arr::get($payload ?? [], 'type');

        return Str::of((string) ($event ?: 'document.created'))->lower()->replace(' ', '_')->toString();
    }

    private function webhookAction(string $eventType): string
    {
        $normalized = Str::of($eventType)->lower()->replace(['.', '-', ' '], '_')->toString();

        if ($this->containsAny($normalized, ['delete', 'deleted', 'trash', 'trashed'])) {
            return 'delete_embedding';
        }

        if ($this->containsAny($normalized, [
            'create',
            'created',
            'added',
            'new',
            'consume',
            'consumed',
            'import',
            'imported',
        ])) {
            return 'process_document';
        }

        if ($this->containsAny($normalized, [
            'update',
            'updated',
            'change',
            'changed',
            'modify',
            'modified',
            'edit',
            'edited',
        ])) {
            return 'refresh_embedding';
        }

        return 'process_document';
    }

    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function updateNormalizedPayload(int $id, array $normalizedPayload): void
    {
        DB::table('webhook_deliveries')
            ->where('id', $id)
            ->update([
                'normalized_payload' => json_encode(
                    $normalizedPayload,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ]);
    }
};
