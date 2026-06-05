<?php

namespace App\Services\Pipeline;

use App\Jobs\RunPythonActorJob;
use App\Models\PipelineEvent;
use App\Models\WebhookDelivery;

class PipelineRecoveryDispatcher
{
    public function recoverQueuedWebhookDeliveries(int $limit = 100): int
    {
        $recovered = 0;

        WebhookDelivery::query()
            ->where('status', WebhookDelivery::STATUS_QUEUED)
            ->oldest('received_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (WebhookDelivery $delivery) use (&$recovered): void {
                if (($delivery->normalized_payload['webhook_action'] ?? null) === 'process_document') {
                    return;
                }

                dispatch(RunPythonActorJob::webhookDelivery($delivery->id));

                PipelineEvent::query()->create([
                    'webhook_delivery_id' => $delivery->id,
                    'event_type' => 'recovery.webhook_actor_redispatched',
                    'paperless_document_id' => $delivery->paperless_document_id,
                    'level' => 'info',
                    'message' => 'Queued webhook delivery redispatched through Laravel actor transport by recovery scan.',
                    'payload' => [
                        'actor_name' => 'handle_paperless_webhook',
                        'transport' => 'laravel_database_queue',
                        'webhook_action' => $delivery->normalized_payload['webhook_action'] ?? null,
                    ],
                ]);

                $recovered++;
            });

        return $recovered;
    }
}
