<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PipelineEvent;
use App\Models\WebhookDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebhookDeliveryController extends Controller
{
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
