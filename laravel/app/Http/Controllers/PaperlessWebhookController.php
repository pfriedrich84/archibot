<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\WorkerJob;
use App\Services\Workers\WorkerJobDispatcher;
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

        $jobPayload = [
            'paperless_document_id' => $documentId,
            'webhook_endpoint' => $endpoint,
            'webhook_event' => Arr::get($payload, 'event'),
        ];

        $workerJob = app(WorkerJobDispatcher::class)->dispatch(
            type: WorkerJob::TYPE_PROCESS_DOCUMENT,
            payload: $jobPayload,
            request: $request,
            dedupeKey: WorkerJobDispatcher::dispatchKey(WorkerJob::TYPE_PROCESS_DOCUMENT, [
                'paperless_document_id' => $documentId,
                'webhook_endpoint' => $endpoint,
            ]),
            auditMetadata: ['source' => 'paperless_webhook'],
        );

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
