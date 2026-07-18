<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** PostgreSQL can atomically roll back this upgrade, including DDL. */
    public $withinTransaction = true;

    public function up(): void
    {
        foreach (['pipeline_runs', 'commands', 'webhook_deliveries'] as $source) {
            Schema::table($source, function (Blueprint $table): void {
                $table->unsignedBigInteger('lifecycle_version')->default(0);
                $table->string('active_actor_token', 64)->nullable()->index();
            });
        }
        Schema::table('commands', function (Blueprint $table): void {
            $table->timestamp('next_retry_at')->nullable()->index();
        });
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->timestamp('next_retry_at')->nullable()->index();
        });
        Schema::table('actor_executions', function (Blueprint $table): void {
            $table->string('execution_token', 64)->nullable()->unique();
            $table->unsignedBigInteger('source_version')->nullable();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Existing persistent volumes can contain more than one pending/queued/running
        // row from the pre-fence transport, including rows with different actor
        // names for the same source. Reconcile before adding source-level partial
        // unique indexes. The winner is deterministic: running, queued, pending,
        // then greatest attempt and id. Every loser is terminal and audited.
        foreach ([
            ['pipeline_run_id', 'pipeline_runs'],
            ['command_id', 'commands'],
            ['webhook_delivery_id', 'webhook_deliveries'],
        ] as [$sourceColumn, $sourceTable]) {
            DB::statement(<<<SQL
WITH ranked AS (
    SELECT id, {$sourceColumn}, actor_name,
           row_number() OVER (
               PARTITION BY {$sourceColumn}
               ORDER BY CASE status WHEN 'running' THEN 0 WHEN 'queued' THEN 1 ELSE 2 END,
                        attempt DESC, id DESC
           ) AS position
    FROM actor_executions
    WHERE {$sourceColumn} IS NOT NULL AND status IN ('pending', 'queued', 'running')
), losers AS (
    SELECT * FROM ranked WHERE position > 1
)
INSERT INTO pipeline_events (
    pipeline_run_id, webhook_delivery_id, command_id, event_type, level, message, payload, created_at
)
SELECT
    CASE WHEN '{$sourceColumn}' = 'pipeline_run_id' THEN {$sourceColumn} ELSE NULL END,
    CASE WHEN '{$sourceColumn}' = 'webhook_delivery_id' THEN {$sourceColumn} ELSE NULL END,
    CASE WHEN '{$sourceColumn}' = 'command_id' THEN {$sourceColumn} ELSE NULL END,
    'actor.execution.reconciled_duplicate', 'warning',
    'Pre-fence duplicate actor execution was made permanently stale during upgrade.',
    jsonb_build_object('actor_execution_id', id, 'actor_name', actor_name,
                       'reason', 'migration_duplicate_active_attempt'), CURRENT_TIMESTAMP
FROM losers
SQL);
            DB::statement(<<<SQL
WITH ranked AS (
    SELECT id,
           row_number() OVER (
               PARTITION BY {$sourceColumn}
               ORDER BY CASE status WHEN 'running' THEN 0 WHEN 'queued' THEN 1 ELSE 2 END,
                        attempt DESC, id DESC
           ) AS position
    FROM actor_executions
    WHERE {$sourceColumn} IS NOT NULL AND status IN ('pending', 'queued', 'running')
)
UPDATE actor_executions AS executions
SET status = 'failed_permanent', finished_at = CURRENT_TIMESTAMP,
    error_type = 'migration_duplicate_active_attempt',
    error_message = 'Superseded deterministically while enabling actor execution fencing.',
    updated_at = CURRENT_TIMESTAMP
FROM ranked WHERE executions.id = ranked.id AND ranked.position > 1
SQL);

            // Fence the surviving active row so an already queued stale job
            // cannot race a new claim immediately after the upgrade. Use a
            // fixed-width token: concatenated bigint values can exceed the
            // varchar(64) contract on persistent volumes.
            $tokenForWinner = "'migration-v1-' || md5('actor-execution:{$sourceColumn}:' || winners.{$sourceColumn}::text || ':' || winners.id::text)";
            $tokenForExecution = "'migration-v1-' || md5('actor-execution:{$sourceColumn}:' || sources.id::text || ':' || executions.id::text)";
            DB::statement(<<<SQL
WITH winners AS (
    SELECT DISTINCT ON ({$sourceColumn}) id, {$sourceColumn}
    FROM actor_executions
    WHERE {$sourceColumn} IS NOT NULL AND status IN ('pending', 'queued', 'running')
    ORDER BY {$sourceColumn},
             CASE status WHEN 'running' THEN 0 WHEN 'queued' THEN 1 ELSE 2 END,
             attempt DESC, id DESC
)
UPDATE {$sourceTable} AS sources
SET lifecycle_version = sources.lifecycle_version + 1,
    active_actor_token = {$tokenForWinner},
    updated_at = CURRENT_TIMESTAMP
FROM winners WHERE sources.id = winners.{$sourceColumn}
SQL);
            DB::statement(<<<SQL
UPDATE actor_executions AS executions
SET execution_token = sources.active_actor_token,
    source_version = sources.lifecycle_version,
    updated_at = CURRENT_TIMESTAMP
FROM {$sourceTable} AS sources
WHERE executions.{$sourceColumn} = sources.id
  AND executions.status IN ('pending', 'queued', 'running')
  AND sources.active_actor_token = {$tokenForExecution}
SQL);

            // A pre-fence pending row has no runnable queue claim. Leaving it
            // active would both suppress recovery and violate the new partial
            // unique index when RunPythonActorJob creates a real claim. Turn
            // the winner into a due retry attempt and normalize its source to
            // the source family's safe recovery state. The source and attempt
            // retain the same token/version until recovery redispatches and a
            // fresh job atomically replaces the fence.
            [$sourceStatus, $sourceRetryFields, $recoverablePredicate, $recoveryMarkerPredicate] = match ($sourceTable) {
                'pipeline_runs' => [
                    'retrying',
                    "next_retry_at = CURRENT_TIMESTAMP, finished_at = NULL, retry_reason = 'migration_pending_actor_recovery', error_type = 'migration_pending_actor_recovery', error = 'Pending pre-fence actor execution scheduled for recovery.'",
                    "sources.status IN ('pending','queued','running','retrying','failed')",
                    "sources.retry_reason = 'migration_pending_actor_recovery' AND sources.error_type = 'migration_pending_actor_recovery'",
                ],
                'commands' => [
                    'pending',
                    "next_retry_at = CURRENT_TIMESTAMP, finished_at = NULL, error = 'migration_pending_actor_recovery'",
                    "sources.status IN ('pending','queued','running','failed')",
                    "sources.error = 'migration_pending_actor_recovery'",
                ],
                default => [
                    'failed',
                    "next_retry_at = CURRENT_TIMESTAMP, processed_at = NULL, error = 'recoverable_processing'",
                    "sources.status IN ('received','queued','running','failed') AND COALESCE(sources.normalized_payload->>'webhook_action', '') <> 'process_document'",
                    "sources.error = 'recoverable_processing'",
                ],
            };
            DB::statement(<<<SQL
WITH pending_winners AS (
    SELECT executions.id, executions.{$sourceColumn}, executions.execution_token
    FROM actor_executions AS executions
    JOIN {$sourceTable} AS sources ON sources.id = executions.{$sourceColumn}
    WHERE executions.status = 'pending'
      AND executions.execution_token = sources.active_actor_token
      AND executions.source_version = sources.lifecycle_version
      AND {$recoverablePredicate}
)
UPDATE {$sourceTable} AS sources
SET status = '{$sourceStatus}', {$sourceRetryFields}, updated_at = CURRENT_TIMESTAMP
FROM pending_winners
WHERE sources.id = pending_winners.{$sourceColumn}
  AND sources.active_actor_token = pending_winners.execution_token
SQL);
            // Target status alone is not proof that the preceding source update
            // selected this execution: a failed process_document webhook can
            // already have that status. Retain both the exact family predicate
            // and the migration marker before converting pending to retrying.
            DB::statement(<<<SQL
WITH recoverable AS (
    SELECT executions.id
    FROM actor_executions AS executions
    JOIN {$sourceTable} AS sources ON sources.id = executions.{$sourceColumn}
    WHERE executions.status = 'pending'
      AND executions.execution_token = sources.active_actor_token
      AND executions.source_version = sources.lifecycle_version
      AND sources.status = '{$sourceStatus}'
      AND {$recoverablePredicate}
      AND {$recoveryMarkerPredicate}
)
UPDATE actor_executions AS executions
SET status = 'retrying', finished_at = CURRENT_TIMESTAMP,
    next_retry_at = CURRENT_TIMESTAMP, last_retry_at = CURRENT_TIMESTAMP,
    retry_reason = 'migration_pending_actor_recovery', retry_mode = 'recovery',
    error_type = 'migration_pending_actor_recovery',
    error_message = 'Pending pre-fence actor execution scheduled for a fresh fenced claim.',
    updated_at = CURRENT_TIMESTAMP
FROM recoverable WHERE executions.id = recoverable.id
SQL);
            DB::statement(<<<SQL
INSERT INTO pipeline_events (
    pipeline_run_id, webhook_delivery_id, command_id, event_type, level, message, payload, created_at
)
SELECT
    CASE WHEN '{$sourceColumn}' = 'pipeline_run_id' THEN {$sourceColumn} ELSE NULL END,
    CASE WHEN '{$sourceColumn}' = 'webhook_delivery_id' THEN {$sourceColumn} ELSE NULL END,
    CASE WHEN '{$sourceColumn}' = 'command_id' THEN {$sourceColumn} ELSE NULL END,
    'actor.execution.reconciled_pending', 'warning',
    'Pending pre-fence actor execution was scheduled for a fresh fenced claim.',
    jsonb_build_object('actor_execution_id', id, 'actor_name', actor_name,
                       'reason', 'migration_pending_actor_recovery'), CURRENT_TIMESTAMP
FROM actor_executions
WHERE {$sourceColumn} IS NOT NULL
  AND status = 'retrying'
  AND retry_reason = 'migration_pending_actor_recovery'
SQL);

            // Pending attempts attached to terminal, blocked, or source-owned
            // process-document webhook state cannot be invoked directly.
            // Retire only the obsolete attempt and release its migration fence;
            // the source remains available to its normal gate/reconciliation path.
            DB::statement(<<<SQL
UPDATE actor_executions AS executions
SET status = 'skipped', finished_at = CURRENT_TIMESTAMP,
    error_type = 'migration_source_not_directly_retryable',
    error_message = 'Pending pre-fence actor execution suppressed because its source is not directly retryable.',
    updated_at = CURRENT_TIMESTAMP
FROM {$sourceTable} AS sources
WHERE executions.{$sourceColumn} = sources.id
  AND executions.status = 'pending'
  AND executions.execution_token = sources.active_actor_token
  AND executions.source_version = sources.lifecycle_version
  AND NOT ({$recoverablePredicate})
SQL);
            DB::statement(<<<SQL
INSERT INTO pipeline_events (
    pipeline_run_id, webhook_delivery_id, command_id, event_type, level, message, payload, created_at
)
SELECT
    CASE WHEN '{$sourceColumn}' = 'pipeline_run_id' THEN {$sourceColumn} ELSE NULL END,
    CASE WHEN '{$sourceColumn}' = 'webhook_delivery_id' THEN {$sourceColumn} ELSE NULL END,
    CASE WHEN '{$sourceColumn}' = 'command_id' THEN {$sourceColumn} ELSE NULL END,
    'actor.execution.reconciled_inactive_source', 'warning',
    'Pending pre-fence actor execution was suppressed because its source is not directly retryable.',
    jsonb_build_object('actor_execution_id', id, 'actor_name', actor_name,
                       'reason', 'migration_source_not_directly_retryable'), CURRENT_TIMESTAMP
FROM actor_executions
WHERE {$sourceColumn} IS NOT NULL
  AND status = 'skipped'
  AND error_type = 'migration_source_not_directly_retryable'
SQL);
            DB::statement(<<<SQL
UPDATE {$sourceTable} AS sources
SET active_actor_token = NULL, updated_at = CURRENT_TIMESTAMP
FROM actor_executions AS executions
WHERE executions.{$sourceColumn} = sources.id
  AND executions.status = 'skipped'
  AND executions.error_type = 'migration_source_not_directly_retryable'
  AND executions.execution_token = sources.active_actor_token
  AND executions.source_version = sources.lifecycle_version
SQL);
        }

        DB::statement("CREATE UNIQUE INDEX actor_exec_active_pipeline_unique ON actor_executions (pipeline_run_id) WHERE pipeline_run_id IS NOT NULL AND status IN ('pending','queued','running')");
        DB::statement("CREATE UNIQUE INDEX actor_exec_active_command_unique ON actor_executions (command_id) WHERE command_id IS NOT NULL AND status IN ('pending','queued','running')");
        DB::statement("CREATE UNIQUE INDEX actor_exec_active_webhook_unique ON actor_executions (webhook_delivery_id) WHERE webhook_delivery_id IS NOT NULL AND status IN ('pending','queued','running')");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION archibot_enforce_actor_execution_transition()
RETURNS trigger AS $$
BEGIN
    IF NEW.status = OLD.status THEN RETURN NEW; END IF;
    IF NOT (
        (OLD.status = 'pending' AND NEW.status IN ('queued','running','retrying','cancelled','failed_permanent','skipped')) OR
        (OLD.status = 'queued' AND NEW.status IN ('running','retrying','cancelled','failed_permanent','skipped')) OR
        (OLD.status = 'running' AND NEW.status IN ('succeeded','skipped','blocked','retrying','failed_permanent','cancelled')) OR
        (OLD.status = 'retrying' AND NEW.status IN ('failed','failed_permanent','cancelled','skipped'))
    ) THEN
        RAISE EXCEPTION 'invalid actor execution transition from % to %', OLD.status, NEW.status
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER actor_execution_transition_guard
BEFORE UPDATE OF status ON actor_executions
FOR EACH ROW EXECUTE FUNCTION archibot_enforce_actor_execution_transition();
SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS actor_execution_transition_guard ON actor_executions');
            DB::statement('DROP FUNCTION IF EXISTS archibot_enforce_actor_execution_transition()');
            DB::statement('DROP INDEX IF EXISTS actor_exec_active_pipeline_unique');
            DB::statement('DROP INDEX IF EXISTS actor_exec_active_command_unique');
            DB::statement('DROP INDEX IF EXISTS actor_exec_active_webhook_unique');
        }
        Schema::table('actor_executions', function (Blueprint $table): void {
            $table->dropUnique(['execution_token']);
            $table->dropColumn(['execution_token', 'source_version']);
        });
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('next_retry_at');
        });
        Schema::table('commands', function (Blueprint $table): void {
            $table->dropColumn('next_retry_at');
        });
        foreach (['pipeline_runs', 'commands', 'webhook_deliveries'] as $source) {
            Schema::table($source, function (Blueprint $table): void {
                $table->dropIndex(['active_actor_token']);
                $table->dropColumn(['lifecycle_version', 'active_actor_token']);
            });
        }
    }
};
