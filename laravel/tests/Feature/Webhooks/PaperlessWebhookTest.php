<?php

namespace Tests\Feature\Webhooks;

use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaperlessWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_new_webhook_path_is_removed_and_does_not_dispatch(): void
    {
        $this->postJson('/webhook/new', ['document_id' => 42])->assertNotFound();

        $this->assertDatabaseCount((new WebhookDelivery)->getTable(), 0);
        $this->assertDatabaseCount((new PipelineRun)->getTable(), 0);
    }

    public function test_legacy_edit_webhook_path_is_removed_and_does_not_dispatch(): void
    {
        $this->postJson('/webhook/edit', ['document_id' => 42])->assertNotFound();

        $this->assertDatabaseCount((new WebhookDelivery)->getTable(), 0);
        $this->assertDatabaseCount((new PipelineRun)->getTable(), 0);
    }
}
