<?php

namespace App\Http\Controllers;

use App\Models\WebhookDelivery;
use App\Services\LegacyPythonState;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ErrorsController extends Controller
{
    public function __invoke(Request $request, LegacyPythonState $legacyPythonState): Response
    {
        $validated = $request->validate([
            'source' => ['nullable', Rule::in(['all', 'webhook', 'legacy'])],
            'status' => ['nullable', 'string', 'max:64'],
        ]);

        $source = $validated['source'] ?? 'all';
        $status = $validated['status'] ?? 'all';
        $isAdmin = (bool) $request->user()?->is_admin;

        $webhookStatuses = [
            WebhookDelivery::STATUS_FAILED,
            WebhookDelivery::STATUS_BLOCKED,
            WebhookDelivery::STATUS_FAILED_PERMANENT,
        ];

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
                'sources' => ['all', 'webhook', 'legacy'],
                'statuses' => array_values(array_unique(array_merge(['all'], $webhookStatuses))),
            ],
            'webhookErrors' => $webhookErrors,
            'legacyErrors' => in_array($source, ['all', 'legacy'], true) ? $legacyPythonState->recentErrors(25) : [],
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
            'source' => $delivery->source,
            'event_type' => $delivery->event_type,
            'paperless_document_id' => $delivery->paperless_document_id,
            'status' => $delivery->status,
            'dedupe_key' => $delivery->dedupe_key,
            'request_id' => $delivery->request_id,
            'received_at' => $delivery->received_at?->toISOString(),
            'processed_at' => $delivery->processed_at?->toISOString(),
            'error' => $delivery->error,
            'payload_summary' => $this->summary($delivery->normalized_payload ?: $delivery->raw_payload),
            'show_url' => route('webhook-deliveries.show', $delivery),
            'retry_url' => $canControl ? route('webhook-deliveries.retry', $delivery) : null,
            'dismiss_url' => $canControl ? route('webhook-deliveries.dismiss', $delivery) : null,
            'can_retry' => $canControl,
            'can_dismiss' => $canControl,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array<int, array{key: string, value: mixed}>
     */
    private function summary(?array $value): array
    {
        return collect($value ?? [])
            ->take(6)
            ->map(fn (mixed $entry, string|int $key): array => [
                'key' => (string) $key,
                'value' => is_scalar($entry) || $entry === null ? $entry : json_encode($entry),
            ])
            ->values()
            ->all();
    }
}
