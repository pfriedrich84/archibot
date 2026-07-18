<?php

namespace App\Http\Controllers;

use App\Models\WebhookDelivery;
use App\Support\DiagnosticPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ErrorsController extends Controller
{
    public function __construct(private readonly DiagnosticPresenter $diagnostics) {}

    public function __invoke(Request $request): Response
    {
        $webhookStatuses = [
            WebhookDelivery::STATUS_FAILED,
            WebhookDelivery::STATUS_BLOCKED,
            WebhookDelivery::STATUS_FAILED_PERMANENT,
        ];

        $validated = $request->validate([
            'source' => ['nullable', Rule::in(['all', 'webhook'])],
            'status' => ['nullable', Rule::in(array_merge(['all'], $webhookStatuses))],
        ]);

        $source = $validated['source'] ?? 'all';
        $status = $validated['status'] ?? 'all';
        $isAdmin = (bool) $request->user()?->is_admin;

        $webhookErrors = WebhookDelivery::query()
            ->when(! in_array($source, ['all', 'webhook'], true), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereIn('status', $webhookStatuses)
            ->when(in_array($status, $webhookStatuses, true), fn ($query) => $query->where('status', $status))
            ->latest('received_at')
            ->latest('id')
            ->paginate(15, ['*'], 'webhook_page')
            ->withQueryString()
            ->through(fn (WebhookDelivery $delivery) => $this->webhookPayload($delivery, $isAdmin));

        return Inertia::render('diagnostics/Errors', [
            'filters' => [
                'source' => $source,
                'status' => $status,
            ],
            'filterOptions' => [
                'sources' => ['all', 'webhook'],
                'statuses' => array_values(array_unique(array_merge(['all'], $webhookStatuses))),
            ],
            'webhookErrors' => $webhookErrors,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookPayload(WebhookDelivery $delivery, bool $isAdmin): array
    {
        $canControl = $isAdmin && in_array($delivery->status, [
            WebhookDelivery::STATUS_FAILED,
            WebhookDelivery::STATUS_FAILED_PERMANENT,
            WebhookDelivery::STATUS_BLOCKED,
        ], true);

        return [
            'id' => $delivery->id,
            'source' => $this->diagnostics->typedScalar('source', $delivery->source),
            'event_type' => $this->diagnostics->webhookEventType($delivery->event_type),
            'paperless_document_id' => $delivery->paperless_document_id,
            'status' => $this->diagnostics->typedScalar('status', $delivery->status),
            'dedupe_key' => $this->diagnostics->opaqueReference($delivery->dedupe_key),
            'request_id' => $this->diagnostics->opaqueReference($delivery->request_id),
            'received_at' => $delivery->received_at?->toISOString(),
            'processed_at' => $delivery->processed_at?->toISOString(),
            'error' => $this->diagnostics->redactedMessage($delivery->error),
            'payload_summary' => $this->diagnostics->webhook($delivery->normalized_payload ?: $delivery->raw_payload),
            'show_url' => route('webhook-deliveries.show', $delivery),
            'retry_url' => $canControl ? route('webhook-deliveries.retry', $delivery) : null,
            'dismiss_url' => $canControl ? route('webhook-deliveries.dismiss', $delivery) : null,
            'can_retry' => $canControl,
            'can_dismiss' => $canControl,
        ];
    }

}
