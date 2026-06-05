<?php

namespace App\Http\Controllers;

use App\Jobs\RunPythonActorJob;
use App\Models\PipelineEvent;
use App\Models\WebhookDelivery;
use App\Services\Pipeline\DocumentPipelineStarter;
use App\Services\Pipeline\PipelineStartResult;
use App\Services\Webhooks\PaperlessWebhookNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class PaperlessEventWebhookController extends Controller
{
    public function __construct(
        private readonly DocumentPipelineStarter $pipelineStarter,
        private readonly PaperlessWebhookNormalizer $webhookNormalizer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $authError = $this->verifySecret($request);
        if ($authError !== null) {
            return $authError;
        }

        $payload = $this->payload($request);
        $normalizedPayload = $this->webhookNormalizer->normalize($payload);
        $documentId = $normalizedPayload['paperless_document_id'];
        if ($documentId === null) {
            return response()->json(['detail' => 'Could not extract document_id from payload'], 422);
        }

        $eventType = $normalizedPayload['event_type'];
        $webhookAction = $normalizedPayload['webhook_action'];
        $paperlessModified = $normalizedPayload['paperless_modified'];
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
                'webhook_action' => $webhookAction,
                'status' => $delivery->status,
            ],
        ]);

        $startResult = null;
        if (! $duplicate) {
            if ($webhookAction === 'process_document') {
                try {
                    $startResult = $this->pipelineStarter->start(
                        triggerSource: 'webhook',
                        paperlessDocumentId: $documentId,
                        paperlessModified: $paperlessModified,
                        webhookDeliveryId: $delivery->id,
                    );
                    $this->markProcessDeliveryHandled($delivery, $startResult);
                } catch (\Throwable $exception) {
                    $this->recordPipelineStartFailure($delivery, $exception::class);

                    return response()->json([
                        'status' => 'pipeline_start_failed',
                        'retry' => true,
                        'duplicate' => false,
                        'document_id' => $documentId,
                        'webhook_delivery_id' => $delivery->id,
                        'webhook_action' => $webhookAction,
                    ], 503);
                }
            }

            if ($webhookAction !== 'process_document') {
                $enqueueResult = $this->attemptWebhookActorDispatch($delivery);
                if ($enqueueResult['status'] === 'failed') {
                    return response()->json($this->responsePayload(
                        'enqueue_failed',
                        false,
                        $documentId,
                        $delivery,
                        $startResult,
                        ['retry' => true, 'webhook_action' => $webhookAction],
                    ), 503);
                }
            }
        }

        Log::info('paperless webhook persisted', [
            'webhook_delivery_id' => $delivery->id,
            'paperless_document_id' => $documentId,
            'event_type' => $eventType,
            'webhook_action' => $webhookAction,
            'duplicate' => $duplicate,
            'pipeline_run_id' => $startResult?->pipelineRun->id,
            'pipeline_outcome' => $startResult?->outcome,
        ]);

        return response()->json($this->responsePayload(
            $duplicate ? WebhookDelivery::STATUS_DUPLICATE : $delivery->fresh()->status,
            $duplicate,
            $documentId,
            $delivery,
            $startResult,
            ['webhook_action' => $webhookAction],
        ));
    }

    private function markProcessDeliveryHandled(WebhookDelivery $delivery, PipelineStartResult $startResult): void
    {
        $status = $startResult->blockedReason === null
            ? WebhookDelivery::STATUS_PROCESSED
            : WebhookDelivery::STATUS_BLOCKED;

        $delivery->forceFill([
            'status' => $status,
            'error' => $startResult->blockedReason,
            'processed_at' => now(),
        ])->save();

        PipelineEvent::query()->create([
            'webhook_delivery_id' => $delivery->id,
            'pipeline_run_id' => $startResult->pipelineRun->id,
            'event_type' => $status === WebhookDelivery::STATUS_PROCESSED
                ? 'webhook.process_delivery_handled'
                : 'webhook.process_delivery_blocked',
            'paperless_document_id' => $delivery->paperless_document_id,
            'level' => $status === WebhookDelivery::STATUS_PROCESSED ? 'info' : 'warning',
            'message' => $status === WebhookDelivery::STATUS_PROCESSED
                ? 'Process-document webhook delivery handed off to durable document pipeline run.'
                : 'Process-document webhook delivery blocked by durable document pipeline gate.',
            'payload' => [
                'pipeline_run_id' => $startResult->pipelineRun->id,
                'pipeline_outcome' => $startResult->outcome,
                'blocked_reason' => $startResult->blockedReason,
            ],
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

        $payload = [];
        foreach ($request->except(array_keys($request->allFiles())) as $key => $value) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $payload = array_replace_recursive($payload, $decoded);

                    continue;
                }
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * @return array{status: string}
     */
    private function attemptWebhookActorDispatch(WebhookDelivery $delivery): array
    {
        try {
            Queue::push(RunPythonActorJob::webhookDelivery($delivery->id));
        } catch (\Throwable $exception) {
            $this->recordDeferredEnqueue($delivery, 'queue_dispatch_failed', null, $exception::class);

            return ['status' => 'failed'];
        }

        PipelineEvent::query()->create([
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'webhook.enqueue_requested',
            'paperless_document_id' => $delivery->paperless_document_id,
            'level' => 'info',
            'message' => 'Webhook actor queued through the Laravel database queue; delivery remains queued until actor processing confirms it.',
            'payload' => [
                'status' => $delivery->status,
                'transport' => 'laravel_queue',
            ],
        ]);

        Log::info('paperless webhook actor queued', [
            'webhook_delivery_id' => $delivery->id,
            'paperless_document_id' => $delivery->paperless_document_id,
        ]);

        return ['status' => 'requested'];
    }

    private function recordPipelineStartFailure(WebhookDelivery $delivery, string $exceptionClass): void
    {
        PipelineEvent::query()->create([
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'webhook.pipeline_start_failed',
            'paperless_document_id' => $delivery->paperless_document_id,
            'level' => 'error',
            'message' => 'Webhook delivery was persisted but durable pipeline start failed; Paperless should retry delivery.',
            'payload' => [
                'status' => $delivery->status,
                'error_type' => 'pipeline_start_failed',
                'exception_class' => $exceptionClass,
            ],
        ]);

        Log::error('paperless webhook pipeline start failed', [
            'webhook_delivery_id' => $delivery->id,
            'paperless_document_id' => $delivery->paperless_document_id,
            'exception_class' => $exceptionClass,
        ]);
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
            'message' => 'Webhook actor queue dispatch failed; Paperless should retry and durable recovery can retry from queued delivery state.',
            'payload' => array_filter([
                'status' => $delivery->status,
                'error_type' => $errorType,
                'exit_code' => $exitCode,
                'exception_class' => $exceptionClass,
            ], fn ($value): bool => $value !== null),
        ]);

        Log::warning('paperless webhook actor queue deferred', array_filter([
            'webhook_delivery_id' => $delivery->id,
            'paperless_document_id' => $delivery->paperless_document_id,
            'error_type' => $errorType,
            'exit_code' => $exitCode,
            'exception_class' => $exceptionClass,
        ], fn ($value): bool => $value !== null));
    }

    private function redactSensitiveKey(string $key): bool
    {
        $normalized = Str::of($key)->lower()->replace(['-', '_', ' '], '')->toString();

        return str_contains($normalized, 'authorization')
            || str_contains($normalized, 'apikey')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'signature')
            || str_contains($normalized, 'cookie');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactSensitivePayload(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->redactSensitiveKey($key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redactSensitivePayload($value) : $value;
        }

        return $redacted;
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
            ->map(fn (array $values, string $key): array => $this->redactSensitiveKey($key)
                ? array_fill(0, count($values), '[redacted]')
                : array_map('strval', $values))
            ->all();

        $existing = WebhookDelivery::query()
            ->where('source', 'paperless')
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing instanceof WebhookDelivery) {
            return [$existing, true];
        }

        $delivery = WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => $eventType,
            'paperless_document_id' => $documentId,
            'dedupe_key' => $dedupeKey,
            'payload_hash' => $payloadHash,
            'raw_payload' => $this->redactSensitivePayload($payload),
            'normalized_payload' => $normalizedPayload,
            'headers' => $headers,
            'status' => WebhookDelivery::STATUS_QUEUED,
            'request_id' => (string) $request->headers->get('X-Request-Id', Str::uuid()->toString()),
            'received_at' => now(),
        ]);

        return [$delivery, false];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function responsePayload(
        string $status,
        bool $duplicate,
        int $documentId,
        WebhookDelivery $delivery,
        ?PipelineStartResult $startResult,
        array $extra = [],
    ): array {
        $payload = [
            'status' => $status,
            'duplicate' => $duplicate,
            'document_id' => $documentId,
            'webhook_delivery_id' => $delivery->id,
        ];

        if ($startResult !== null) {
            $payload['pipeline_run_id'] = $startResult->pipelineRun->id;
            $payload['pipeline_outcome'] = $startResult->outcome;
            $payload['blocked_reason'] = $startResult->blockedReason;
        }

        return array_merge($payload, $extra);
    }
}
