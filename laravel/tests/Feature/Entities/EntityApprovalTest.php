<?php

namespace Tests\Feature\Entities;

use App\Jobs\ApplyEntityApprovalCommand;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\EntityApproval;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Services\EntityApprovalDecisionService;
use App\Services\Paperless\PaperlessClient;
use App\Services\Pipeline\PipelineRecoveryDispatcher;
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

    public function test_entity_queue_failure_returns_an_accessible_session_error(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create([
            'type' => EntityApproval::TYPE_TAG,
            'name' => 'Accounting',
        ]);
        $decisions = $this->mock(EntityApprovalDecisionService::class);
        $decisions->shouldReceive('enqueue')->once()->andThrow(new \DomainException('A newer decision already exists.'));

        $this->actingAs($admin)
            ->from(route('entities.index', ['segment' => 'tags']))
            ->post(route('entities.approve', ['segment' => 'tags', 'entityApproval' => $entity]))
            ->assertRedirect(route('entities.index', ['segment' => 'tags']))
            ->assertSessionHas('error', 'Entity approval could not be queued: A newer decision already exists.');
    }

    public function test_admin_can_approve_pending_entity_and_create_it_in_paperless(): void
    {
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
            ->assertRedirect()
            ->assertSessionHas('status', "Approval for 'Accounting' was queued.");
        $command = Command::query()->firstOrFail();
        $this->execute($command);

        $entity->refresh();
        $this->assertSame(EntityApproval::STATUS_APPROVED, $entity->status);
        $this->assertSame(77, $entity->paperless_id);
        $this->assertSame($admin->id, $entity->reviewed_by_user_id);
        $this->assertNotNull($entity->reviewed_at);
        $this->assertSame(EntityApproval::SYNC_STATUS_SYNCED, $entity->sync_status);
        $this->assertSame(Command::TYPE_SYNC_ENTITY_APPROVAL, $command->type);
        $this->assertSame(Command::STATUS_SUCCEEDED, $command->status);
        $this->assertSame('laravel_postgresql', $command->payload['owner']);
        $this->assertSame(0, $command->payload['outcome']['updated_suggestions']);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'entity_approval.approved',
            'target_type' => 'entity_approval',
            'target_id' => (string) $entity->id,
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'https://paperless.test/api/tags/'
            && $request['name'] === 'Accounting'
            && $request->hasHeader('Authorization', 'Token admin-token'));
        $this->assertDatabaseHas('pipeline_events', [
            'command_id' => $command->id,
            'event_type' => 'entity_approval.application_succeeded',
        ]);
    }

    public function test_approval_resolves_postgresql_suggestions_and_patches_committed_document(): void
    {
        Http::fake([
            'paperless.test/api/correspondents/' => Http::response(['id' => 88], 201),
            'paperless.test/api/documents/42/' => Http::response([], 200),
        ]);
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create([
            'type' => EntityApproval::TYPE_CORRESPONDENT,
            'name' => 'ACME',
        ]);
        $suggestion = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 42,
            'status' => ReviewSuggestion::STATUS_ACCEPTED,
            'commit_status' => ReviewSuggestion::COMMIT_STATUS_COMMITTED,
            'proposed_correspondent_name' => 'ACME',
            'proposed_correspondent_id' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('entities.approve', ['segment' => 'correspondents', 'entityApproval' => $entity]))
            ->assertRedirect();
        $this->execute(Command::query()->firstOrFail());

        $this->assertSame(88, $suggestion->refresh()->proposed_correspondent_id);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->url() === 'https://paperless.test/api/documents/42/'
            && $request['correspondent'] === 88);
        $this->assertSame(1, Command::query()->firstOrFail()->payload['outcome']['patched_documents']);
    }

    public function test_tag_approval_only_updates_matching_postgresql_suggestions(): void
    {
        Http::fake([
            'paperless.test/api/tags/' => Http::response(['id' => 77], 201),
            'paperless.test/api/documents/42/' => Http::response([], 200),
        ]);
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG, 'name' => 'Accounting']);
        $matching = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 42,
            'status' => ReviewSuggestion::STATUS_ACCEPTED,
            'commit_status' => ReviewSuggestion::COMMIT_STATUS_COMMITTED,
            'proposed_tags' => [['id' => null, 'name' => 'Accounting'], ['id' => 2, 'name' => 'Archive']],
        ]);
        $unrelated = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 43,
            'status' => ReviewSuggestion::STATUS_ACCEPTED,
            'commit_status' => ReviewSuggestion::COMMIT_STATUS_COMMITTED,
            'proposed_tags' => [['id' => null, 'name' => 'Travel']],
        ]);

        $this->actingAs($admin)
            ->post(route('entities.approve', ['segment' => 'tags', 'entityApproval' => $entity]))
            ->assertRedirect();
        $this->execute(Command::query()->firstOrFail());

        $this->assertSame(77, $matching->refresh()->proposed_tags[0]['id']);
        $this->assertNull($unrelated->refresh()->proposed_tags[0]['id']);
        $this->assertSame(1, Command::query()->firstOrFail()->payload['outcome']['updated_suggestions']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/documents/43/'));
    }

    public function test_entity_application_failure_is_durable_without_sqlite_fallback(): void
    {
        Http::fake(['paperless.test/api/tags/' => Http::response([], 500)]);
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG, 'name' => 'Accounting']);

        $this->actingAs($admin)
            ->post(route('entities.approve', ['segment' => 'tags', 'entityApproval' => $entity]))
            ->assertRedirect();

        $command = Command::query()->firstOrFail();
        try {
            $this->execute($command);
            $this->fail('Entity application failure was not propagated by the worker seam.');
        } catch (\RuntimeException) {
            // The durable command remains recoverable after the queued worker fails.
        }
        $this->assertSame(Command::STATUS_PENDING, $command->status);
        $this->assertNotNull($command->next_retry_at);
        $this->assertStringStartsWith('entity_approval_application_failed:', $command->error);
        $this->assertSame(EntityApproval::STATUS_PENDING, $entity->refresh()->status);
        $this->assertSame(EntityApproval::SYNC_STATUS_FAILED, $entity->sync_status);
        $this->assertDatabaseHas('pipeline_events', [
            'command_id' => $command->id,
            'event_type' => 'entity_approval.application_failed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'entity_approval.application_failed',
            'target_id' => (string) $entity->id,
        ]);

        Queue::fake();
        $this->travel(2)->minutes();
        $this->assertSame(1, app(PipelineRecoveryDispatcher::class)->recoverPendingCommands());
        $this->assertSame(Command::STATUS_QUEUED, $command->fresh()->status);
        Queue::assertPushed(ApplyEntityApprovalCommand::class, fn ($job) => $job->commandId === $command->id);
    }

    public function test_failed_retroactive_application_can_retry_without_duplicate_paperless_entity(): void
    {
        Http::fake([
            'paperless.test/api/tags/' => Http::response(['id' => 77], 201),
            'paperless.test/api/documents/42/' => Http::response([], 500),
        ]);
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG, 'name' => 'Accounting']);
        ReviewSuggestion::factory()->create([
            'paperless_document_id' => 42,
            'status' => ReviewSuggestion::STATUS_ACCEPTED,
            'commit_status' => ReviewSuggestion::COMMIT_STATUS_COMMITTED,
            'proposed_tags' => [['id' => null, 'name' => 'Accounting']],
        ]);

        $this->actingAs($admin)
            ->post(route('entities.approve', ['segment' => 'tags', 'entityApproval' => $entity]))
            ->assertRedirect();
        try {
            $this->execute(Command::query()->firstOrFail());
            $this->fail('Retroactive Paperless failure was not propagated.');
        } catch (\RuntimeException) {
            // Retry assertions below verify the durable recovery state.
        }

        $entity->refresh();
        $this->assertSame(EntityApproval::STATUS_APPROVED, $entity->status);
        $this->assertSame(EntityApproval::SYNC_STATUS_FAILED, $entity->sync_status);
        $this->assertSame(77, $entity->paperless_id);

        Http::fake(['paperless.test/api/documents/42/' => Http::response([], 200)]);
        $this->actingAs($admin)
            ->post(route('entities.approve', ['segment' => 'tags', 'entityApproval' => $entity]))
            ->assertRedirect();
        $this->execute(Command::query()->firstOrFail());

        $this->assertSame(EntityApproval::SYNC_STATUS_SYNCED, $entity->refresh()->sync_status);
        $this->assertSame(1, Command::query()->count());
        $this->assertSame(Command::STATUS_SUCCEEDED, Command::query()->firstOrFail()->status);
        $tagCreates = Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/tags/'));
        $documentPatches = Http::recorded(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/api/documents/42/'));
        $this->assertCount(1, $tagCreates);
        $this->assertCount(2, $documentPatches);
    }

    public function test_entity_decisions_remain_durable_after_application_restart(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_DOCUMENT_TYPE]);

        $this->actingAs($admin)
            ->post(route('entities.reject', ['segment' => 'doctypes', 'entityApproval' => $entity]))
            ->assertRedirect();
        $command = Command::query()->firstOrFail();
        $this->execute($command);
        $commandId = $command->id;

        $this->app->flush();
        $this->refreshApplication();

        $this->assertDatabaseHas('entity_approvals', [
            'id' => $entity->id,
            'status' => EntityApproval::STATUS_REJECTED,
            'sync_status' => EntityApproval::SYNC_STATUS_SYNCED,
        ]);
        $this->assertDatabaseHas('commands', [
            'id' => $commandId,
            'type' => Command::TYPE_SYNC_ENTITY_APPROVAL,
            'status' => Command::STATUS_SUCCEEDED,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'command_id' => $commandId,
            'event_type' => 'entity_approval.application_succeeded',
        ]);
    }

    public function test_entity_http_action_only_queues_before_worker_execution(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG, 'name' => 'Accounting']);

        $this->actingAs($admin)
            ->post(route('entities.approve', ['segment' => 'tags', 'entityApproval' => $entity]))
            ->assertRedirect();

        $command = Command::query()->firstOrFail();
        $this->assertSame(Command::STATUS_QUEUED, $command->status);
        $this->assertSame(EntityApproval::STATUS_PENDING, $entity->fresh()->status);
        $this->assertSame(EntityApproval::SYNC_STATUS_QUEUED, $entity->fresh()->sync_status);
        Queue::assertPushed(ApplyEntityApprovalCommand::class, fn ($job) => $job->commandId === $command->id);
        Http::assertNothingSent();
    }

    public function test_conflicting_active_decision_is_rejected_and_same_action_reuses_fenced_command(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG, 'name' => 'Accounting']);
        $service = app(EntityApprovalDecisionService::class);

        $approved = $service->enqueue($entity, 'approved', $admin);
        $same = $service->enqueue($entity->fresh(), 'approved', $admin);
        $this->assertSame($approved->id, $same->id);
        $this->assertSame($approved->payload['decision_token'], $entity->fresh()->active_decision_token);
        $this->assertSame($approved->payload['decision_version'], $entity->fresh()->decision_version);

        try {
            $service->enqueue($entity->fresh(), 'rejected', $admin);
            $this->fail('Conflicting active entity decision was accepted.');
        } catch (\DomainException $exception) {
            $this->assertSame(
                'entity_decision_conflict: active action approved conflicts with requested action rejected',
                $exception->getMessage(),
            );
        }
        $this->assertDatabaseCount('commands', 1);
        Queue::assertPushed(ApplyEntityApprovalCommand::class, 1);
    }

    public function test_every_conflicting_action_pair_is_fenced_by_one_active_command(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $actions = ['approved', 'rejected', 'unblacklisted'];

        foreach ($actions as $activeAction) {
            foreach ($actions as $requestedAction) {
                $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG]);
                $service = app(EntityApprovalDecisionService::class);
                $active = $service->enqueue($entity, $activeAction, $admin);

                if ($requestedAction === $activeAction) {
                    $this->assertSame(
                        $active->id,
                        $service->enqueue($entity->fresh(), $requestedAction, $admin)->id,
                    );
                } else {
                    try {
                        $service->enqueue($entity->fresh(), $requestedAction, $admin);
                        $this->fail("Conflicting {$activeAction}/{$requestedAction} decision was accepted.");
                    } catch (\DomainException $exception) {
                        $this->assertSame(
                            "entity_decision_conflict: active action {$activeAction} conflicts with requested action {$requestedAction}",
                            $exception->getMessage(),
                        );
                    }
                }

                $this->assertSame($active->id, $entity->fresh()->active_decision_command_id);
                $this->assertSame(1, Command::query()
                    ->where('payload->entity_approval_id', $entity->id)
                    ->count());
            }
        }
    }

    public function test_conflicting_http_request_returns_deterministic_conflict(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG]);

        $this->actingAs($admin)
            ->post(route('entities.approve', ['segment' => 'tags', 'entityApproval' => $entity]))
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('entities.reject', ['segment' => 'tags', 'entityApproval' => $entity]))
            ->assertConflict();

        $this->assertDatabaseCount('commands', 1);
    }

    public function test_recovered_stale_queue_delivery_cannot_overwrite_newer_decision(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_DOCUMENT_TYPE]);
        $service = app(EntityApprovalDecisionService::class);

        $rejected = $service->enqueue($entity, 'rejected', $admin);
        $service->execute($rejected);
        $unblacklisted = $service->enqueue($entity->fresh(), 'unblacklisted', $admin);

        // Simulate an old queue delivery appearing after the next decision was
        // accepted. Its token/version no longer owns the entity row.
        $rejected->forceFill([
            'status' => Command::STATUS_PENDING,
            'finished_at' => null,
            'next_retry_at' => now()->subMinute(),
        ])->save();
        $this->assertSame(1, app(PipelineRecoveryDispatcher::class)->recoverPendingCommands());
        Queue::assertPushed(ApplyEntityApprovalCommand::class, fn ($job) => $job->commandId === $rejected->id);
        $service->execute($rejected->fresh());
        $this->assertSame(Command::STATUS_SKIPPED, $rejected->fresh()->status);
        $this->assertSame($unblacklisted->id, $entity->fresh()->active_decision_command_id);
        $this->assertSame(EntityApproval::STATUS_REJECTED, $entity->fresh()->status);

        $service->execute($unblacklisted);
        $this->assertSame(EntityApproval::STATUS_PENDING, $entity->fresh()->status);
        $this->assertSame(Command::STATUS_SUCCEEDED, $unblacklisted->fresh()->status);
    }

    public function test_stale_approval_delivery_cannot_make_remote_calls_or_overwrite_newer_rejection(): void
    {
        Queue::fake();
        Http::fake(['paperless.test/api/tags/' => Http::response(['id' => 77], 201)]);
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG, 'name' => 'Accounting']);
        $service = app(EntityApprovalDecisionService::class);

        $approval = $service->enqueue($entity, 'approved', $admin);
        $service->execute($approval);
        $rejection = $service->enqueue($entity->fresh(), 'rejected', $admin);
        $requestsBeforeStaleDelivery = Http::recorded()->count();

        // A duplicate old queue message can arrive after the next action owns
        // the row. Even if its durable status is corrupted back to pending,
        // the old token/version must fail before any Paperless side effect.
        $approval->forceFill([
            'status' => Command::STATUS_PENDING,
            'finished_at' => null,
            'next_retry_at' => now()->subMinute(),
        ])->save();
        $service->execute($approval->fresh());

        $this->assertSame(Command::STATUS_SKIPPED, $approval->fresh()->status);
        $this->assertSame('superseded_entity_decision', $approval->fresh()->error);
        $this->assertSame($rejection->id, $entity->fresh()->active_decision_command_id);
        $this->assertSame(EntityApproval::STATUS_APPROVED, $entity->fresh()->status);
        $this->assertCount($requestsBeforeStaleDelivery, Http::recorded());

        $service->execute($rejection);
        $this->assertSame(EntityApproval::STATUS_REJECTED, $entity->fresh()->status);
    }

    public function test_duplicate_queue_request_reuses_command_and_does_not_dispatch_twice(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG, 'name' => 'Accounting']);
        $service = app(EntityApprovalDecisionService::class);

        $first = $service->enqueue($entity, 'approved', $admin);
        $second = $service->enqueue($entity->fresh(), 'approved', $admin);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('commands', 1);
        Queue::assertPushed(ApplyEntityApprovalCommand::class, 1);
        $this->assertStringStartsWith('entity-approval:', $first->idempotency_key);
    }

    public function test_crash_before_remote_create_retries_same_command_without_duplicate_entity(): void
    {
        Queue::fake();
        Http::fakeSequence('paperless.test/api/tags/*')
            ->push(['results' => []], 200)
            ->push(['results' => []], 200)
            ->push(['id' => 77, 'name' => 'Accounting'], 201);
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG, 'name' => 'Accounting']);
        $paperless = app(PaperlessClient::class);
        $crashing = new class($paperless) extends EntityApprovalDecisionService
        {
            protected function beforePaperlessEntityCreated(EntityApproval $entity): void
            {
                throw new \RuntimeException('simulated_worker_death_before_create');
            }
        };
        $command = $crashing->enqueue($entity, 'approved', $admin);
        try {
            $crashing->execute($command);
        } catch (\RuntimeException) {
        }

        $retry = (new EntityApprovalDecisionService($paperless))->enqueue($entity->fresh(), 'approved', $admin);
        (new EntityApprovalDecisionService($paperless))->execute($retry);

        $this->assertSame($command->id, $retry->id);
        $this->assertSame(77, $entity->fresh()->paperless_id);
        $creates = Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/tags/'));
        $this->assertCount(1, $creates);
    }

    public function test_crash_after_remote_create_recovers_without_duplicate_entity(): void
    {
        Queue::fake();
        Http::fakeSequence('paperless.test/api/tags/*')
            ->push(['results' => []], 200)
            ->push(['id' => 77, 'name' => 'Accounting'], 201)
            ->push(['results' => [['id' => 77, 'name' => 'Accounting']]], 200);
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG, 'name' => 'Accounting']);
        $paperless = app(PaperlessClient::class);
        $crashing = new class($paperless) extends EntityApprovalDecisionService
        {
            protected function afterPaperlessEntityCreated(EntityApproval $entity, int $paperlessId): void
            {
                throw new \RuntimeException('simulated_worker_death_after_create');
            }
        };
        $command = $crashing->enqueue($entity, 'approved', $admin);
        try {
            $crashing->execute($command);
            $this->fail('Crash seam did not interrupt execution.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated_worker_death_after_create', $exception->getMessage());
        }

        $normal = new EntityApprovalDecisionService($paperless);
        $retry = $normal->enqueue($entity->fresh(), 'approved', $admin);
        $this->assertSame($command->id, $retry->id);
        $normal->execute($retry);

        $this->assertSame(77, $entity->fresh()->paperless_id);
        $this->assertSame(Command::STATUS_SUCCEEDED, $command->fresh()->status);
        $creates = Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/tags/'));
        $this->assertCount(1, $creates);
    }

    public function test_crash_before_remote_patch_retries_same_command_and_applies_once(): void
    {
        Queue::fake();
        Http::fake(['paperless.test/api/documents/42/' => Http::response([], 200)]);
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create([
            'type' => EntityApproval::TYPE_CORRESPONDENT,
            'name' => 'ACME',
            'paperless_id' => 88,
        ]);
        ReviewSuggestion::factory()->create([
            'paperless_document_id' => 42,
            'status' => ReviewSuggestion::STATUS_ACCEPTED,
            'commit_status' => ReviewSuggestion::COMMIT_STATUS_COMMITTED,
            'proposed_correspondent_name' => 'ACME',
            'proposed_correspondent_id' => null,
        ]);
        $paperless = app(PaperlessClient::class);
        $crashing = new class($paperless) extends EntityApprovalDecisionService
        {
            protected function beforePaperlessDocumentPatched(ReviewSuggestion $suggestion): void
            {
                throw new \RuntimeException('simulated_worker_death_before_patch');
            }
        };
        $command = $crashing->enqueue($entity, 'approved', $admin);
        try {
            $crashing->execute($command);
        } catch (\RuntimeException) {
        }
        $retry = (new EntityApprovalDecisionService($paperless))->enqueue($entity->fresh(), 'approved', $admin);
        (new EntityApprovalDecisionService($paperless))->execute($retry);

        $this->assertSame($command->id, $retry->id);
        $patches = Http::recorded(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/api/documents/42/'));
        $this->assertCount(1, $patches);
        $this->assertSame(Command::STATUS_SUCCEEDED, $command->fresh()->status);
    }

    public function test_stale_running_command_after_patch_is_recovery_allowlisted_and_idempotent(): void
    {
        Queue::fake();
        Http::fake(['paperless.test/api/documents/42/' => Http::response([], 200)]);
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $entity = EntityApproval::factory()->create([
            'type' => EntityApproval::TYPE_TAG,
            'name' => 'Accounting',
            'paperless_id' => 77,
        ]);
        ReviewSuggestion::factory()->create([
            'paperless_document_id' => 42,
            'status' => ReviewSuggestion::STATUS_ACCEPTED,
            'commit_status' => ReviewSuggestion::COMMIT_STATUS_COMMITTED,
            'proposed_tags' => [['id' => null, 'name' => 'Accounting']],
        ]);
        $paperless = app(PaperlessClient::class);
        $crashing = new class($paperless) extends EntityApprovalDecisionService
        {
            protected function afterPaperlessDocumentPatched(ReviewSuggestion $suggestion): void
            {
                throw new \RuntimeException('simulated_worker_death_after_patch');
            }
        };
        $command = $crashing->enqueue($entity, 'approved', $admin);
        try {
            $crashing->execute($command);
        } catch (\RuntimeException) {
            // Simulate process death leaving the source in running state.
        }
        $command->forceFill(['status' => Command::STATUS_RUNNING, 'updated_at' => now()->subHours(2)])->save();

        app(PipelineRecoveryDispatcher::class)->recoverPendingCommands();

        $this->assertSame(Command::STATUS_QUEUED, $command->fresh()->status);
        Queue::assertPushed(ApplyEntityApprovalCommand::class, fn ($job) => $job->commandId === $command->id);
        (new EntityApprovalDecisionService($paperless))->execute($command->fresh());
        $this->assertSame(Command::STATUS_SUCCEEDED, $command->fresh()->status);
        $this->assertSame(1, Command::query()->whereKey($command->id)->count());
        $patches = Http::recorded(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/api/documents/42/'));
        $this->assertCount(2, $patches, 'the same idempotent PATCH is replayed after the crash window');
        $this->assertSame(77, ReviewSuggestion::query()->firstOrFail()->proposed_tags[0]['id']);
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
        $admin = User::factory()->create(['is_admin' => true]);
        $entity = EntityApproval::factory()->create([
            'type' => EntityApproval::TYPE_DOCUMENT_TYPE,
            'name' => 'Invoice',
        ]);

        $this->actingAs($admin)
            ->post(route('entities.reject', ['segment' => 'doctypes', 'entityApproval' => $entity]))
            ->assertRedirect()
            ->assertSessionHas('status', "Rejection of 'Invoice' was queued.");
        $this->execute(Command::query()->latest('id')->firstOrFail());

        $this->assertSame(EntityApproval::STATUS_REJECTED, $entity->refresh()->status);

        $this->actingAs($admin)
            ->post(route('entities.unblacklist', ['segment' => 'doctypes', 'entityApproval' => $entity]))
            ->assertRedirect()
            ->assertSessionHas('status', "Blocklist removal for 'Invoice' was queued.");
        $this->execute(Command::query()->latest('id')->firstOrFail());

        $this->assertSame(EntityApproval::STATUS_PENDING, $entity->refresh()->status);
        $this->assertSame(2, AuditLog::query()
            ->where('target_type', 'entity_approval')
            ->where('target_id', (string) $entity->id)
            ->whereIn('event', ['entity_approval.rejected', 'entity_approval.unblacklisted'])
            ->count());
    }

    private function execute(Command $command): void
    {
        (new ApplyEntityApprovalCommand($command->id))->handle(app(EntityApprovalDecisionService::class));
    }
}
