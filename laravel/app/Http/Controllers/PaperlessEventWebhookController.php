<?php

namespace App\Http\Controllers;

use App\Models\PipelineEvent;
use App\Models\WebhookDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class PaperlessEventWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $authError = $this->verifySecret($request);
        if ($authError !== null) {
            return $authError;
        }

        $payload = $this->payload($request);
        $documentId = $this->documentId($payload);
        if ($documentId === null) {
            return response()->json(['detail' => 'Could not extract document_id from payload'], 422);
        }

        $eventType = $this->eventType($payload);
        $paperlessModified = $this->paperlessModified($payload);
        $normalizedPayload = [
            'event_type' => $eventType,
            'paperless_document_id' => $documentId,
            'paperless_modified' => $paperlessModified,
        ];
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $dedupeKey = implode(':', [
            'paperless',
            $eventType,
            (string) $documentId,
            $paperlessModified ?? 'unknown_modified',
            $payloadHash,
        ]);

        [$delivery, $duplicate] = $this->persistDelivery(
            $request,
            $eventType,
            $documentId,
            $dedupeKey,
            $payloadHash,
            $payload,
            $normalizedPayload,
        );

        PipelineEvent::query()->create([
            'webhook_delivery_id' => $delivery->id,
            'event_type' => $duplicate ? 'webhook.duplicate' : 'webhook.received',
            'paperless_document_id' => $documentId,
            'level' => 'info',
            'message' => $duplicate ? 'Duplicate Paperless webhook delivery ignored.' : 'Paperless webhook delivery persisted.',
            'payload' => [
                'dedupe_key' => $dedupeKey,
                'event_type' => $eventType,
                'status' => $delivery->status,
            ],
        ]);

        if (! $duplicate) {
            $this->attemptDirectEnqueue($delivery);
        }

        Log::info('paperless webhook persisted', [
            'webhook_delivery_id' => $delivery->id,
            'paperless_document_id' => $documentId,
            'event_type' => $eventType,
            'duplicate' => $duplicate,
        ]);

        return response()->json([
            'status' => $duplicate ? 'duplicate' : 'queued',
            'duplicate' => $duplicate,
            'document_id' => $documentId,
            'webhook_delivery_id' => $delivery->id,
        ]);
    }

    private function verifySecret(Request $request): ?JsonResponse
    {
        $configuredSecret = (string) config('archibot.paperless_webhook_secret', '');
        if ($configuredSecret === '') {
            return null;
        }

        $providedSecret = (string) $request->headers->get('X-Webhook-Secret', '');
        if ($providedSecret === '' || ! hash_equals($configuredSecret, $providedSecret)) {
            return response()->json(['detail' => 'Invalid webhook secret'], 403);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ($request->isJson()) {
            $json = $request->json()->all();

            return is_array($json) ? $json : [];
        }

        return $request->except(array_keys($request->allFiles()));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function documentId(array $payload): ?int
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
    private function eventType(array $payload): string
    {
        $event = Arr::get($payload, 'event') ?? Arr::get($payload, 'action') ?? Arr::get($payload, 'type');

        return Str::of((string) ($event ?: 'document.changed'))->lower()->replace(' ', '_')->toString();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function paperlessModified(array $payload): ?string
    {
        $value = Arr::get($payload, 'document.modified')
            ?? Arr::get($payload, 'object.modified')
            ?? Arr::get($payload, 'modified');

        return $value === null ? null : (string) $value;
    }

    private function attemptDirectEnqueue(WebhookDelivery $delivery): void
    {
        $command = $this->enqueueCommand($delivery->id);
        if ($command === []) {
            return;
        }

        try {
            $result = Process::timeout(10)->run($command);
        } catch (\Throwable $exception) {
            $this->recordDeferredEnqueue($delivery, 'process_start_failed', null, $exception::class);

            return;
        }

        if ($result->successful()) {
            PipelineEvent::query()->create([
                'webhook_delivery_id' => $delivery->id,
                'event_type' => 'webhook.enqueue_requested',
                'paperless_document_id' => $delivery->paperless_document_id,
                'level' => 'info',
                'message' => 'Direct webhook enqueue command completed; delivery remains queued until actor processing confirms it.',
                'payload' => [
                    'status' => $delivery->status,
                    'transport' => 'configured_command',
                ],
            ]);

            Log::info('paperless webhook direct enqueue requested', [
                'webhook_delivery_id' => $delivery->id,
                'paperless_document_id' => $delivery->paperless_document_id,
            ]);

            return;
        }

        $this->recordDeferredEnqueue($delivery, 'process_failed', $result->exitCode());
    }

    /**
     * @return array<int, string>
     */
    private function enqueueCommand(int $deliveryId): array
    {
        $configured = trim((string) config('archibot.webhook_enqueue_command', ''));
        if ($configured === '') {
            return [];
        }

        $parts = str_getcsv($configured, ' ', '"', '\\');
        $parts = array_values(array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));
        if ($parts === []) {
            return [];
        }

        $hasPlaceholder = false;
        foreach ($parts as $index => $part) {
            if (str_contains($part, '{delivery_id}')) {
                $hasPlaceholder = true;
                $parts[$index] = str_replace('{delivery_id}', (string) $deliveryId, $part);
            }
        }

        if (! $hasPlaceholder) {
            $parts[] = (string) $deliveryId;
        }

        return $parts;
    }

    private function recordDeferredEnqueue(
        WebhookDelivery $delivery,
        string $errorType,
        ?int $exitCode = null,
        ?string $exceptionClass = null,
    ): void {
        PipelineEvent::query()->create([
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'webhook.enqueue_deferred',
            'paperless_document_id' => $delivery->paperless_document_id,
            'level' => 'warning',
            'message' => 'Direct webhook enqueue failed; durable recovery will retry from queued delivery state.',
            'payload' => array_filter([
                'status' => $delivery->status,
                'error_type' => $errorType,
                'exit_code' => $exitCode,
                'exception_class' => $exceptionClass,
            ], fn ($value): bool => $value !== null),
        ]);

        Log::warning('paperless webhook direct enqueue deferred', array_filter([
            'webhook_delivery_id' => $delivery->id,
            'paperless_document_id' => $delivery->paperless_document_id,
            'error_type' => $errorType,
            'exit_code' => $exitCode,
            'exception_class' => $exceptionClass,
        ], fn ($value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{0: WebhookDelivery, 1: bool}
     */
    private function persistDelivery(
        Request $request,
        string $eventType,
        int $documentId,
        string $dedupeKey,
        string $payloadHash,
        array $payload,
        array $normalizedPayload,
    ): array {
        $headers = collect($request->headers->all())
            ->except(['authorization', 'x-api-key', 'x-webhook-secret'])
            ->map(fn (array $values): array => array_map('strval', $values))
            ->all();

        try {
            $delivery = WebhookDelivery::query()->create([
                'source' => 'paperless',
                'event_type' => $eventType,
                'paperless_document_id' => $documentId,
                'dedupe_key' => $dedupeKey,
                'payload_hash' => $payloadHash,
                'raw_payload' => $payload,
                'normalized_payload' => $normalizedPayload,
                'headers' => $headers,
                'status' => WebhookDelivery::STATUS_QUEUED,
                'request_id' => (string) $request->headers->get('X-Request-Id', Str::uuid()->toString()),
                'received_at' => now(),
            ]);

            return [$delivery, false];
        } catch (UniqueConstraintViolationException) {
            /** @var WebhookDelivery $delivery */
            $delivery = WebhookDelivery::query()
                ->where('source', 'paperless')
                ->where('dedupe_key', $dedupeKey)
                ->firstOrFail();

            return [$delivery, true];
        }
    }
}
