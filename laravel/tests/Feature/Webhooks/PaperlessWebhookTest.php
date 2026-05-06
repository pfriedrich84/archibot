<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\RunPythonWorkerJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\WorkerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaperlessWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_webhook_queues_process_document_from_legacy_payload(): void
    {
        Queue::fake();

        $this->postJson(route('webhook.new'), ['document_id' => 42])
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'document_id' => 42,
            ]);

        $workerJob = WorkerJob::query()->firstOrFail();
        $this->assertSame(WorkerJob::TYPE_PROCESS_DOCUMENT, $workerJob->type);
        $this->assertSame(WorkerJob::STATUS_QUEUED, $workerJob->status);
        $this->assertSame(42, $workerJob->payload['paperless_document_id']);
        $this->assertSame('webhook/new', $workerJob->payload['webhook_endpoint']);

        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $job) => $job->workerJobId === $workerJob->id);
        $this->assertSame('paperless_webhook', AuditLog::query()->firstOrFail()->metadata['source']);
    }

    public function test_workflow_payload_object_id_is_preferred_over_legacy_document_id(): void
    {
        Queue::fake();

        $this->postJson(route('webhook.new'), [
            'event' => 'document_created',
            'object' => ['id' => '123'],
            'document_id' => 999,
        ])->assertOk()->assertJson(['document_id' => 123]);

        $workerJob = WorkerJob::query()->firstOrFail();
        $this->assertSame(123, $workerJob->payload['paperless_document_id']);
        $this->assertSame('document_created', $workerJob->payload['webhook_event']);
    }

    public function test_edit_webhook_queues_process_document(): void
    {
        Queue::fake();

        $this->postJson(route('webhook.edit'), [
            'event' => 'document_updated',
            'object' => ['id' => 77],
        ])->assertOk()->assertJson([
            'status' => 'ok',
            'document_id' => 77,
        ]);

        $workerJob = WorkerJob::query()->firstOrFail();
        $this->assertSame(WorkerJob::TYPE_PROCESS_DOCUMENT, $workerJob->type);
        $this->assertSame(77, $workerJob->payload['paperless_document_id']);
        $this->assertSame('webhook/edit', $workerJob->payload['webhook_endpoint']);

        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $job) => $job->workerJobId === $workerJob->id);
    }

    public function test_missing_document_id_is_rejected(): void
    {
        Queue::fake();

        $this->postJson(route('webhook.new'), ['foo' => 'bar'])
            ->assertStatus(422)
            ->assertJson(['detail' => 'Could not extract document_id from payload']);

        $this->assertDatabaseCount('worker_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_configured_webhook_secret_is_required(): void
    {
        Queue::fake();
        AppSetting::put('webhook.secret', 'my-secret', true);

        $this->postJson(route('webhook.new'), ['document_id' => 42])
            ->assertStatus(403)
            ->assertJson(['detail' => 'Invalid webhook secret']);

        $this->assertDatabaseCount('worker_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_configured_webhook_secret_allows_matching_header(): void
    {
        Queue::fake();
        AppSetting::put('webhook.secret', 'my-secret', true);

        $this->withHeader('X-Webhook-Secret', 'my-secret')
            ->postJson(route('webhook.new'), ['document_id' => 42])
            ->assertOk();

        $this->assertDatabaseHas('worker_jobs', [
            'type' => WorkerJob::TYPE_PROCESS_DOCUMENT,
            'status' => WorkerJob::STATUS_QUEUED,
        ]);
    }

    public function test_multipart_webhook_accepts_json_payload_field_and_ignores_file(): void
    {
        Queue::fake();

        $this->post(route('webhook.new'), [
            'payload' => json_encode(['event' => 'document_created', 'object' => ['id' => 55]], JSON_THROW_ON_ERROR),
            'document' => UploadedFile::fake()->create('scan.pdf', 16, 'application/pdf'),
        ])->assertOk()->assertJson(['document_id' => 55]);

        $workerJob = WorkerJob::query()->firstOrFail();
        $this->assertSame(55, $workerJob->payload['paperless_document_id']);
    }
}
