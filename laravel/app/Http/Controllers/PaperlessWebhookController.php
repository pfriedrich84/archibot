<?php

namespace App\Http\Controllers;

use App\Jobs\RunPythonWorkerJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\WorkerJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class PaperlessWebhookController extends Controller
{
    public function new(Request $request): JsonResponse
    {
        return $this->queueProcessDocument($request, 'webhook/new');
    }

    public function edit(Request $request): JsonResponse
    {
        return $this->queueProcessDocument($request, 'webhook/edit');
    }

    private function queueProcessDocument(Request $request, string $endpoint): JsonResponse
    {
        $authError = $this->verifySecret($request);
        if ($authError !== null) {
            return $authError;
        }

        $payload = $this->payload($request);
        $documentId = $this->documentId($payload);

        if ($documentId === null) {
            return response()->json([
                'detail' => 'Could not extract document_id from payload',
            ], 422);
        }

        $workerJob = WorkerJob::query()->create([
            'type' => WorkerJob::TYPE_PROCESS_DOCUMENT,
            'status' => WorkerJob::STATUS_QUEUED,
            'payload' => [
                'paperless_document_id' => $documentId,
                'webhook_endpoint' => $endpoint,
                'webhook_event' => Arr::get($payload, 'event'),
            ],
        ]);

        AuditLog::query()->create([
            'event' => 'worker_job.queued',
            'target_type' => 'worker_job',
            'target_id' => (string) $workerJob->id,
            'metadata' => [
                'type' => $workerJob->type,
                'payload' => $workerJob->payload,
                'source' => 'paperless_webhook',
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        RunPythonWorkerJob::dispatch($workerJob->id);

        return response()->json([
            'status' => 'ok',
            'document_id' => $documentId,
            'worker_job_id' => $workerJob->id,
        ]);
    }

    private function verifySecret(Request $request): ?JsonResponse
    {
        $configuredSecret = AppSetting::getValue('webhook.secret', env('WEBHOOK_SECRET', '')) ?? '';

        if ($configuredSecret !== '') {
            $providedSecret = (string) $request->headers->get('X-Webhook-Secret', '');

            if ($providedSecret === '' || ! hash_equals($configuredSecret, $providedSecret)) {
                return response()->json(['detail' => 'Invalid webhook secret'], 403);
            }
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
     * @param  array<string, mixed>  $payload
     */
    private function documentId(array $payload): ?int
    {
        $objectId = Arr::get($payload, 'object.id');
        if ($objectId !== null && filter_var($objectId, FILTER_VALIDATE_INT) !== false) {
            return (int) $objectId;
        }

        $documentId = Arr::get($payload, 'document_id');
        if ($documentId !== null && filter_var($documentId, FILTER_VALIDATE_INT) !== false) {
            return (int) $documentId;
        }

        return null;
    }
}
