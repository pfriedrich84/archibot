<?php

namespace App\Http\Controllers;

use App\Models\WebhookDelivery;
use App\Models\WorkerJob;
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
            'source' => ['nullable', Rule::in(['all', 'worker', 'webhook', 'legacy'])],
            'status' => ['nullable', 'string', 'max:64'],
        ]);

        $source = $validated['source'] ?? 'all';
        $status = $validated['status'] ?? 'all';
        $isAdmin = (bool) $request->user()?->is_admin;

        $workerStatuses = [
            WorkerJob::STATUS_FAILED,
            WorkerJob::STATUS_PARTIALLY_FAILED,
            WorkerJob::STATUS_CANCELLED,
        ];
        $webhookStatuses = [
            WebhookDelivery::STATUS_FAILED,
            WebhookDelivery::STATUS_BLOCKED,
            WebhookDelivery::STATUS_FAILED_PERMANENT,
        ];

        $failedJobs = WorkerJob::query()
            ->when(! in_array($source, ['all', 'worker'], true), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereIn('status', $workerStatuses)
            ->when(in_array($status, $workerStatuses, true), fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(15, ['*'], 'worker_page')
            ->withQueryString()
            ->through(fn (WorkerJob $job) => [
                'id' => $job->id,
                'type' => $job->type,
                'status' => $job->status,
                'error' => $job->error,
                'payload' => $job->payload ?? [],
                'progress' => $job->progress ?? [],
                'result' => $job->result ?? [],
                'exit_code' => $job->exit_code,
                'created_at' => $job->created_at?->toISOString(),
                'started_at' => $job->started_at?->toISOString(),
                'finished_at' => $job->finished_at?->toISOString(),
                'show_url' => route('worker-jobs.show', $job),
                'retry_url' => $isAdmin ? route('worker-jobs.retry', $job) : null,
                'can_retry' => $isAdmin && in_array($job->status, WorkerJob::terminalStatuses(), true),
                'can_retry_failed_only' => $isAdmin && in_array($job->status, WorkerJob::terminalStatuses(), true) && $this->failedDocumentIds($job) !== [],
            ]);

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
                'sources' => ['all', 'worker', 'webhook', 'legacy'],
                'statuses' => array_values(array_unique(array_merge(['all'], $workerStatuses, $webhookStatuses))),
            ],
            'failedJobs' => $failedJobs,
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

    /**
     * @return array<int, int>
     */
    private function failedDocumentIds(WorkerJob $job): array
    {
        $ids = data_get($job->result ?? [], 'failed_document_ids', data_get($job->progress ?? [], 'failed_document_ids', []));

        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
