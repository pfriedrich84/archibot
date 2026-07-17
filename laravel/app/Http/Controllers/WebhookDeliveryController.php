<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PipelineEvent;
use App\Models\WebhookDelivery;
use App\Support\DiagnosticPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebhookDeliveryController extends Controller
{
    public function __construct(private readonly DiagnosticPresenter $diagnostics) {}

    public function index(Request $request): Response
    {
        $deliveries = WebhookDelivery::query()
            ->latest('received_at')
            ->latest('id')
            ->paginate(25)
            ->through(fn (WebhookDelivery $delivery) => $this->deliveryPayload($request, $delivery, includeDetails: false));

        return Inertia::render('webhooks/Index', [
            'deliveries' => $deliveries,
            'isAdmin' => (bool) $request->user()?->is_admin,
        ]);
    }

    public function show(Request $request, WebhookDelivery $webhookDelivery): Response
    {
        return Inertia::render('webhooks/Show', [
            'delivery' => $this->deliveryPayload($request, $webhookDelivery, includeDetails: true),
            'isAdmin' => (bool) $request->user()?->is_admin,
        ]);
    }

    public function retry(Request $request, WebhookDelivery $webhookDelivery): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        abort_unless(in_array($webhookDelivery->status, $this->controllableStatuses(), true), 409);

        $webhookDelivery->forceFill([
            'status' => WebhookDelivery::STATUS_QUEUED,
            'error' => null,
            'processed_at' => null,
        ])->save();

        $this->event($request, 'job_control.webhook_retry_requested', $webhookDelivery, 'Webhook delivery retry queued.');
        $this->audit($request, 'webhook_delivery.retry_queued', $webhookDelivery);

        return back()->with('status', 'Webhook delivery retry queued.');
    }

    public function dismiss(Request $request, WebhookDelivery $webhookDelivery): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        abort_unless(in_array($webhookDelivery->status, $this->controllableStatuses(), true), 409);

        $webhookDelivery->forceFill([
            'status' => WebhookDelivery::STATUS_DISMISSED,
            'error' => null,
            'processed_at' => now(),
        ])->save();

        $this->event($request, 'job_control.webhook_failure_dismissed', $webhookDelivery, 'Webhook delivery failure dismissed.');
        $this->audit($request, 'webhook_delivery.failure_dismissed', $webhookDelivery);

        return back()->with('status', 'Webhook delivery failure dismissed.');
    }

    /**
     * @return array<int, string>
     */
    private function controllableStatuses(): array
    {
        return [
            WebhookDelivery::STATUS_FAILED,
            WebhookDelivery::STATUS_FAILED_PERMANENT,
            WebhookDelivery::STATUS_BLOCKED,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryPayload(Request $request, WebhookDelivery $delivery, bool $includeDetails): array
    {
        $canControl = (bool) $request->user()?->is_admin
            && in_array($delivery->status, $this->controllableStatuses(), true);

        $payload = [
            'id' => $delivery->id,
            'source' => $this->diagnostics->typedScalar('source', $delivery->source),
            'event_type' => $this->diagnostics->webhookEventType($delivery->event_type),
            'paperless_document_id' => $delivery->paperless_document_id,
            'status' => $this->diagnostics->typedScalar('status', $delivery->status),
            'dedupe_key' => $this->diagnostics->opaqueReference($delivery->dedupe_key),
            'payload_hash' => $this->diagnostics->opaqueReference($delivery->payload_hash),
            'request_id' => $this->diagnostics->opaqueReference($delivery->request_id),
            'received_at' => $delivery->received_at?->toISOString(),
            'processed_at' => $delivery->processed_at?->toISOString(),
            'error' => $this->diagnostics->redactedMessage($delivery->error),
            'payload_summary' => $this->diagnostics->webhook($delivery->normalized_payload ?: $delivery->raw_payload),
            'show_url' => route('webhook-deliveries.show', $delivery),
            'retry_url' => route('webhook-deliveries.retry', $delivery),
            'dismiss_url' => route('webhook-deliveries.dismiss', $delivery),
            'can_retry' => $canControl,
            'can_dismiss' => $canControl,
        ];

        if ($includeDetails) {
            $payload['pipeline_events'] = PipelineEvent::query()
                ->where('webhook_delivery_id', $delivery->id)
                ->latest('created_at')
                ->latest('id')
                ->limit(25)
                ->get()
                ->map(fn (PipelineEvent $event) => [
                    'id' => $event->id,
                    'event_type' => $this->diagnostics->diagnosticEventType($event->event_type),
                    'level' => $this->diagnostics->typedScalar('level', $event->level),
                    'message' => $this->diagnostics->redactedMessage($event->message),
                    'paperless_document_id' => $event->paperless_document_id,
                    'pipeline_run_id' => $event->pipeline_run_id,
                    'command_id' => $event->command_id,
                    'metadata' => $this->diagnostics->metadata($event->payload),
                    'created_at' => $event->created_at?->toISOString(),
                ])
                ->values();
        }

        return $payload;
    }

    private function event(Request $request, string $eventType, WebhookDelivery $webhookDelivery, string $message): void
    {
        PipelineEvent::query()->create([
            'webhook_delivery_id' => $webhookDelivery->id,
            'event_type' => $eventType,
            'paperless_document_id' => $webhookDelivery->paperless_document_id,
            'level' => 'info',
            'message' => $message,
            'payload' => [
                'actor_user_id' => $request->user()->id,
                'actor_is_admin' => true,
                'webhook_delivery_id' => $webhookDelivery->id,
                'status' => $webhookDelivery->status,
            ],
        ]);
    }

    private function audit(Request $request, string $event, WebhookDelivery $webhookDelivery): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => $event,
            'target_type' => 'webhook_delivery',
            'target_id' => (string) $webhookDelivery->id,
            'metadata' => [
                'status' => $webhookDelivery->status,
                'paperless_document_id' => $webhookDelivery->paperless_document_id,
                'event_type' => $webhookDelivery->event_type,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
