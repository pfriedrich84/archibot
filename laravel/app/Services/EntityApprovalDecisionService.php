<?php

namespace App\Services;

use App\Jobs\ApplyEntityApprovalCommand;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\EntityApproval;
use App\Models\PipelineEvent;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class EntityApprovalDecisionService
{
    public function __construct(private readonly PaperlessClient $paperless) {}

    /** Queue an idempotent Laravel-owned entity decision. */
    public function enqueue(EntityApproval $selected, string $action, User $actor): Command
    {
        if (! in_array($action, ['approved', 'rejected', 'unblacklisted'], true)) {
            throw new \InvalidArgumentException('Unsupported entity approval action.');
        }

        [$command, $dispatchRequired] = DB::transaction(function () use ($selected, $action, $actor): array {
            $entity = EntityApproval::query()->lockForUpdate()->findOrFail($selected->id);
            $existing = $entity->active_decision_command_id === null
                ? null
                : Command::query()->lockForUpdate()->find($entity->active_decision_command_id);

            $activeStatuses = [
                Command::STATUS_PENDING,
                Command::STATUS_QUEUED,
                Command::STATUS_RUNNING,
                Command::STATUS_FAILED,
            ];
            if ($existing !== null && in_array($existing->status, $activeStatuses, true)
                && ! $this->matchesActiveDecision($entity, $existing)) {
                $existing->update([
                    'status' => Command::STATUS_SKIPPED,
                    'finished_at' => now(),
                    'error' => 'invalid_entity_decision_fence',
                ]);
                $existing = null;
            }

            if ($existing !== null && in_array($existing->status, $activeStatuses, true)) {
                if ($entity->active_decision_action !== $action) {
                    throw new \DomainException(sprintf(
                        'entity_decision_conflict: active action %s conflicts with requested action %s',
                        $entity->active_decision_action,
                        $action,
                    ));
                }
                $dispatchRequired = in_array($existing->status, [Command::STATUS_PENDING, Command::STATUS_FAILED], true);
                if ($dispatchRequired) {
                    $existing->update([
                        'status' => Command::STATUS_QUEUED,
                        'error' => null,
                        'finished_at' => null,
                        'next_retry_at' => null,
                    ]);
                }
                $entity->forceFill(['sync_status' => EntityApproval::SYNC_STATUS_QUEUED])->save();

                return [$existing->fresh(), $dispatchRequired];
            }

            $version = ((int) $entity->decision_version) + 1;
            $token = (string) Str::uuid();
            $command = Command::query()->create([
                'type' => Command::TYPE_SYNC_ENTITY_APPROVAL,
                'status' => Command::STATUS_QUEUED,
                'idempotency_key' => "entity-approval:{$entity->id}:{$version}:{$token}",
                'payload' => [
                    'entity_approval_id' => $entity->id,
                    'action' => $action,
                    'decision_token' => $token,
                    'decision_version' => $version,
                    'type' => $entity->type,
                    'paperless_id' => $entity->paperless_id,
                    'owner' => 'laravel_postgresql',
                ],
                'created_by_user_id' => $actor->id,
            ]);
            $entity->forceFill([
                'sync_status' => EntityApproval::SYNC_STATUS_QUEUED,
                'decision_version' => $version,
                'active_decision_token' => $token,
                'active_decision_action' => $action,
                'active_decision_command_id' => $command->id,
            ])->save();
            $this->event($command, 'entity_approval.application_queued', 'Entity approval application queued.', $actor);

            return [$command, true];
        });

        if ($dispatchRequired) {
            ApplyEntityApprovalCommand::dispatch($command->id)->afterCommit();
        }
        return $command;
    }

    /** Execute one durable command. Re-entry is safe after worker death. */
    public function execute(Command $selected): void
    {
        $claimed = DB::transaction(function () use ($selected): ?Command {
            $entityId = (int) ($selected->payload['entity_approval_id'] ?? 0);
            $entity = EntityApproval::query()->lockForUpdate()->find($entityId);
            $command = Command::query()->lockForUpdate()->findOrFail($selected->id);
            if ($command->type !== Command::TYPE_SYNC_ENTITY_APPROVAL
                || ! in_array($command->status, [Command::STATUS_PENDING, Command::STATUS_QUEUED, Command::STATUS_FAILED], true)) {
                return null;
            }
            if ($entity === null || ! $this->matchesActiveDecision($entity, $command)) {
                $command->update([
                    'status' => Command::STATUS_SKIPPED,
                    'finished_at' => now(),
                    'error' => 'superseded_entity_decision',
                ]);

                return null;
            }
            $command->update(['status' => Command::STATUS_RUNNING, 'started_at' => $command->started_at ?? now(), 'finished_at' => null, 'error' => null]);

            return $command->fresh();
        });
        if ($claimed === null) {
            return;
        }

        $actor = User::query()->findOrFail($claimed->created_by_user_id);
        $entity = EntityApproval::query()->findOrFail((int) $claimed->payload['entity_approval_id']);
        $action = (string) $claimed->payload['action'];
        $this->event($claimed, 'entity_approval.application_started', 'Entity approval durable application started.', $actor);

        try {
            if ($action === 'approved') {
                $token = $actor->paperless_token;
                if (! $token) {
                    throw new \RuntimeException('Approved entity application requires an operator token.');
                }
                $this->assertCurrentDecision($entity, $claimed);
                $paperlessId = $this->resolvePaperlessEntity($entity, $token);
                // Fence the remote identity checkpoint with the active decision.
                // A superseded worker can never publish its Paperless id.
                $this->checkpointPaperlessId($entity, $claimed, $actor, $paperlessId);
                $entity->refresh();
                [$updated, $patched] = $this->applyApproval($entity, $claimed, $token);
            } else {
                $this->assertCurrentDecision($entity, $claimed);
                $paperlessId = $entity->paperless_id;
                [$updated, $patched] = [0, 0];
            }

            $outcome = ['updated_suggestions' => $updated, 'patched_documents' => $patched];
            DB::transaction(function () use ($claimed, $entity, $action, $actor, $paperlessId, $outcome): void {
                $currentEntity = EntityApproval::query()->lockForUpdate()->findOrFail($entity->id);
                $currentCommand = Command::query()->lockForUpdate()->findOrFail($claimed->id);
                if (! $this->matchesActiveDecision($currentEntity, $currentCommand)) {
                    $currentCommand->update([
                        'status' => Command::STATUS_SKIPPED,
                        'finished_at' => now(),
                        'error' => 'superseded_entity_decision',
                    ]);

                    return;
                }
                $currentEntity->mark(
                    $action === 'approved'
                        ? EntityApproval::STATUS_APPROVED
                        : ($action === 'rejected' ? EntityApproval::STATUS_REJECTED : EntityApproval::STATUS_PENDING),
                    $actor,
                    $paperlessId,
                );
                $currentCommand->forceFill([
                    'status' => Command::STATUS_SUCCEEDED,
                    'payload' => [...$currentCommand->payload, 'paperless_id' => $paperlessId, 'outcome' => $outcome],
                    'finished_at' => now(), 'error' => null,
                ])->save();
                $currentEntity->forceFill([
                    'sync_status' => EntityApproval::SYNC_STATUS_SYNCED,
                    'active_decision_token' => null,
                    'active_decision_action' => null,
                    'active_decision_command_id' => null,
                ])->save();
            });
            $claimed->refresh();
            $entity->refresh();
            if ($claimed->status !== Command::STATUS_SUCCEEDED) {
                return;
            }
            $this->event($claimed, 'entity_approval.application_succeeded', 'Entity approval applied from PostgreSQL.', $actor, $outcome);
            $this->audit($claimed, $entity, 'entity_approval.application_succeeded', $actor, $outcome);
        } catch (Throwable $exception) {
            if ($exception instanceof \DomainException
                && $exception->getMessage() === 'superseded_entity_decision') {
                Command::query()->whereKey($claimed->id)
                    ->whereIn('status', [Command::STATUS_PENDING, Command::STATUS_QUEUED, Command::STATUS_RUNNING, Command::STATUS_FAILED])
                    ->update([
                        'status' => Command::STATUS_SKIPPED,
                        'finished_at' => now(),
                        'error' => 'superseded_entity_decision',
                    ]);

                return;
            }
            // Keep recoverable application failures pending so the normal Laravel
            // recovery scanner can redispatch them. A hard worker death leaves the
            // command running and is recovered by the stale-running branch.
            DB::transaction(function () use ($claimed, $entity, $exception): void {
                $currentEntity = EntityApproval::query()->lockForUpdate()->find($entity->id);
                $currentCommand = Command::query()->lockForUpdate()->find($claimed->id);
                if ($currentEntity === null || $currentCommand === null
                    || ! $this->matchesActiveDecision($currentEntity, $currentCommand)) {
                    return;
                }
                $currentCommand->forceFill([
                    'status' => Command::STATUS_PENDING,
                    'finished_at' => null,
                    'next_retry_at' => now()->addMinute(),
                    'error' => 'entity_approval_application_failed:'.$exception::class,
                ])->save();
                $currentEntity->forceFill(['sync_status' => EntityApproval::SYNC_STATUS_FAILED])->save();
            });
            $claimed->refresh();
            $entity->refresh();
            $this->event($claimed, 'entity_approval.application_failed', 'Entity approval application failed.', $actor, ['error_type' => $exception::class], 'error');
            $this->audit($claimed, $entity, 'entity_approval.application_failed', $actor, ['error_type' => $exception::class]);
            throw $exception;
        }
    }

    private function resolvePaperlessEntity(EntityApproval $entity, string $token): int
    {
        if ($entity->paperless_id !== null) {
            return $entity->paperless_id;
        }
        $existing = match ($entity->type) {
            EntityApproval::TYPE_TAG => $this->paperless->findTagByName($token, $entity->name),
            EntityApproval::TYPE_CORRESPONDENT => $this->paperless->findCorrespondentByName($token, $entity->name),
            EntityApproval::TYPE_DOCUMENT_TYPE => $this->paperless->findDocumentTypeByName($token, $entity->name),
            default => throw new \RuntimeException('Unsupported entity approval type.'),
        };
        if ($existing !== null) {
            return $existing;
        }
        $this->beforePaperlessEntityCreated($entity);
        $created = match ($entity->type) {
            EntityApproval::TYPE_TAG => $this->paperless->createTag($token, $entity->name),
            EntityApproval::TYPE_CORRESPONDENT => $this->paperless->createCorrespondent($token, $entity->name),
            EntityApproval::TYPE_DOCUMENT_TYPE => $this->paperless->createDocumentType($token, $entity->name),
            default => throw new \RuntimeException('Unsupported entity approval type.'),
        };
        $this->afterPaperlessEntityCreated($entity, $created);
        return $created;
    }

    /** Testable crash boundary: no remote create has happened. */
    protected function beforePaperlessEntityCreated(EntityApproval $entity): void {}

    /** Testable crash boundary: remote create succeeded, durable id not written yet. */
    protected function afterPaperlessEntityCreated(EntityApproval $entity, int $paperlessId): void {}

    /** Testable crash boundary: no remote document patch has happened. */
    protected function beforePaperlessDocumentPatched(ReviewSuggestion $suggestion): void {}

    /** Testable crash boundary: idempotent remote patch succeeded. */
    protected function afterPaperlessDocumentPatched(ReviewSuggestion $suggestion): void {}

    /** @return array{int, int} */
    private function applyApproval(EntityApproval $entity, Command $command, string $token): array
    {
        if ($entity->paperless_id === null) {
            throw new \RuntimeException('Approved entity application requires a Paperless id.');
        }
        $suggestions = $this->matchingSuggestions($entity)->get();
        $updated = 0; $patched = 0;
        foreach ($suggestions as $suggestion) {
            $this->assertCurrentDecision($entity, $command);
            $fields = [];
            $suggestionUpdates = [];
            if ($entity->type === EntityApproval::TYPE_TAG) {
                $matched = false;
                $tags = collect($suggestion->proposed_tags ?? [])->map(function ($tag) use ($entity, &$matched) {
                    if (is_array($tag) && mb_strtolower((string) ($tag['name'] ?? '')) === mb_strtolower($entity->name)) {
                        $tag['id'] = $entity->paperless_id; $matched = true;
                    }
                    return $tag;
                })->all();
                if (! $matched) { continue; }
                $suggestionUpdates['proposed_tags'] = $tags;
                $fields['tags'] = collect($tags)->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();
            } elseif ($entity->type === EntityApproval::TYPE_CORRESPONDENT) {
                $suggestionUpdates['proposed_correspondent_id'] = $entity->paperless_id;
                $fields['correspondent'] = $entity->paperless_id;
            } else {
                $suggestionUpdates['proposed_document_type_id'] = $entity->paperless_id;
                $fields['document_type'] = $entity->paperless_id;
            }
            if ($suggestion->commit_status === ReviewSuggestion::COMMIT_STATUS_COMMITTED && $fields !== []) {
                $this->beforePaperlessDocumentPatched($suggestion);
                $this->assertCurrentDecision($entity, $command);
                $this->paperless->patchDocument($token, $suggestion->paperless_document_id, $fields);
                $this->afterPaperlessDocumentPatched($suggestion);
                $patched++;
            }
            // The PostgreSQL suggestion checkpoint follows the idempotent remote
            // patch. A crash before the patch leaves work discoverable; a crash
            // after it may replay the same PATCH but cannot lose the application.
            $this->assertCurrentDecision($entity, $command);
            $suggestion->forceFill($suggestionUpdates)->save();
            $updated++;
        }
        return [$updated, $patched];
    }

    private function checkpointPaperlessId(
        EntityApproval $entity,
        Command $command,
        User $actor,
        int $paperlessId,
    ): void {
        $updated = EntityApproval::query()
            ->whereKey($entity->id)
            ->where('active_decision_command_id', $command->id)
            ->where('active_decision_token', $command->payload['decision_token'])
            ->where('decision_version', $command->payload['decision_version'])
            ->update([
                'status' => EntityApproval::STATUS_APPROVED,
                'paperless_id' => $paperlessId,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new \DomainException('superseded_entity_decision');
        }
    }

    private function assertCurrentDecision(EntityApproval $entity, Command $command): void
    {
        $current = EntityApproval::query()->find($entity->id);
        if ($current === null || ! $this->matchesActiveDecision($current, $command)) {
            throw new \DomainException('superseded_entity_decision');
        }
    }

    private function matchesActiveDecision(EntityApproval $entity, Command $command): bool
    {
        return $entity->active_decision_command_id === $command->id
            && hash_equals((string) $entity->active_decision_token, (string) ($command->payload['decision_token'] ?? ''))
            && (int) $entity->decision_version === (int) ($command->payload['decision_version'] ?? -1)
            && $entity->active_decision_action === ($command->payload['action'] ?? null);
    }

    private function matchingSuggestions(EntityApproval $entity)
    {
        $query = ReviewSuggestion::query()->whereIn('status', [ReviewSuggestion::STATUS_PENDING, ReviewSuggestion::STATUS_ACCEPTED]);
        return match ($entity->type) {
            EntityApproval::TYPE_TAG => $query->whereNotNull('proposed_tags'),
            EntityApproval::TYPE_CORRESPONDENT => $query->whereNull('proposed_correspondent_id')->whereRaw('LOWER(proposed_correspondent_name) = LOWER(?)', [$entity->name]),
            EntityApproval::TYPE_DOCUMENT_TYPE => $query->whereNull('proposed_document_type_id')->whereRaw('LOWER(proposed_document_type_name) = LOWER(?)', [$entity->name]),
            default => throw new \RuntimeException('Unsupported entity approval type.'),
        };
    }

    private function audit(Command $command, EntityApproval $entity, string $event, User $actor, array $metadata): void
    {
        AuditLog::query()->create(['actor_user_id' => $actor->id, 'event' => $event, 'target_type' => 'entity_approval', 'target_id' => (string) $entity->id, 'metadata' => ['actor_principal' => 'authenticated_user', 'command_id' => $command->id, 'type' => $entity->type, ...$metadata], 'user_agent' => 'laravel-entity-approval-service']);
    }

    private function event(Command $command, string $type, string $message, User $actor, array $payload = [], string $level = 'info'): void
    {
        PipelineEvent::query()->create(['command_id' => $command->id, 'event_type' => $type, 'level' => $level, 'message' => $message, 'payload' => ['actor_user_id' => $actor->id, 'actor_principal' => 'authenticated_user', ...$payload]]);
    }
}
