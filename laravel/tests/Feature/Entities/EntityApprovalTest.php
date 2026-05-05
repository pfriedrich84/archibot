<?php

namespace Tests\Feature\Entities;

use App\Jobs\RunPythonWorkerJob;
use App\Models\AuditLog;
use App\Models\EntityApproval;
use App\Models\User;
use App\Models\WorkerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EntityApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_view_entity_approval_page(): void
    {
        $user = User::factory()->create();
        $pending = EntityApproval::factory()->create([
            'type' => EntityApproval::TYPE_TAG,
            'name' => 'Accounting',
        ]);
        EntityApproval::factory()->create([
            'type' => EntityApproval::TYPE_CORRESPONDENT,
            'name' => 'ACME',
        ]);

        $this->actingAs($user)
            ->get('/tags')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('entities/Index')
                ->where('segment', 'tags')
                ->where('title', 'Tags')
                ->where('pending.0.id', $pending->id)
                ->where('pending.0.name', 'Accounting')
                ->has('pending', 1)
            );
    }

    public function test_admin_can_approve_pending_entity_and_create_it_in_paperless(): void
    {
        Queue::fake();
        Http::fake([
            'paperless.test/api/tags/' => Http::response(['id' => 77, 'name' => 'Accounting'], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create([
            'type' => EntityApproval::TYPE_TAG,
            'name' => 'Accounting',
        ]);

        $this->actingAs($admin)
            ->post(route('entities.approve', ['segment' => 'tags', 'entityApproval' => $entity]))
            ->assertRedirect();

        $entity->refresh();
        $this->assertSame(EntityApproval::STATUS_APPROVED, $entity->status);
        $this->assertSame(77, $entity->paperless_id);
        $this->assertSame($admin->id, $entity->reviewed_by_user_id);
        $this->assertNotNull($entity->reviewed_at);
        $this->assertSame(EntityApproval::SYNC_STATUS_QUEUED, $entity->sync_status);
        $workerJob = WorkerJob::query()->firstOrFail();
        $this->assertSame(WorkerJob::TYPE_SYNC_ENTITY_APPROVAL, $workerJob->type);
        $this->assertSame($workerJob->id, $entity->sync_worker_job_id);
        $this->assertSame([
            'entity_approval_id' => $entity->id,
            'action' => 'approved',
            'type' => EntityApproval::TYPE_TAG,
            'name' => 'Accounting',
            'paperless_id' => 77,
        ], $workerJob->payload);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'entity_approval.approved',
            'target_type' => 'entity_approval',
            'target_id' => (string) $entity->id,
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'https://paperless.test/api/tags/'
            && $request['name'] === 'Accounting'
            && $request->hasHeader('Authorization', 'Token admin-token'));
        Queue::assertPushed(RunPythonWorkerJob::class, fn (RunPythonWorkerJob $job) => $job->workerJobId === $workerJob->id);
    }

    public function test_non_admin_can_not_mutate_entity_approvals(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'paperless_token' => 'user-token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG]);

        $this->actingAs($user)
            ->post(route('entities.reject', ['segment' => 'tags', 'entityApproval' => $entity]))
            ->assertForbidden();

        $this->assertSame(EntityApproval::STATUS_PENDING, $entity->refresh()->status);
    }

    public function test_admin_can_reject_and_unblacklist_entity(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $entity = EntityApproval::factory()->create([
            'type' => EntityApproval::TYPE_DOCUMENT_TYPE,
            'name' => 'Invoice',
        ]);

        $this->actingAs($admin)
            ->post(route('entities.reject', ['segment' => 'doctypes', 'entityApproval' => $entity]))
            ->assertRedirect();

        $this->assertSame(EntityApproval::STATUS_REJECTED, $entity->refresh()->status);

        $this->actingAs($admin)
            ->post(route('entities.unblacklist', ['segment' => 'doctypes', 'entityApproval' => $entity]))
            ->assertRedirect();

        $this->assertSame(EntityApproval::STATUS_PENDING, $entity->refresh()->status);
        $this->assertSame(2, AuditLog::query()
            ->where('target_type', 'entity_approval')
            ->where('target_id', (string) $entity->id)
            ->count());
    }
}
