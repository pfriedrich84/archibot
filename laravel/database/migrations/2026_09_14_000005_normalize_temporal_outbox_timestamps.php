<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const OUTBOX_TIMESTAMPS = ['available_at', 'locked_at', 'delivered_at'];

    /** @var list<string> */
    private const ACTIVE_COMMAND_STATUSES = ['pending', 'queued', 'running'];

    /** @var list<string> */
    private const CLAIMABLE_OUTBOX_STATUSES = ['pending', 'delivering'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('temporal_outbox_intents')) {
            return;
        }

        $oldTimestampColumns = array_values(array_filter(
            self::OUTBOX_TIMESTAMPS,
            fn (string $column): bool => $this->dataType($column) === 'timestamp without time zone',
        ));

        if (in_array('available_at', $oldTimestampColumns, true)) {
            DB::statement(<<<'SQL'
                UPDATE temporal_outbox_intents
                SET status = 'pending',
                    available_at = timezone('UTC', CURRENT_TIMESTAMP),
                    locked_at = NULL
                WHERE status IN ('pending', 'delivering')
                SQL);
        }

        foreach ($oldTimestampColumns as $column) {
            DB::statement(<<<SQL
                ALTER TABLE temporal_outbox_intents
                ALTER COLUMN {$column} TYPE TIMESTAMP(0) WITH TIME ZONE
                USING {$column} AT TIME ZONE 'UTC'
                SQL);
        }

        DB::statement(<<<'SQL'
            UPDATE temporal_outbox_intents
            SET status = 'pending',
                available_at = CURRENT_TIMESTAMP,
                locked_at = NULL
            WHERE status IN ('pending', 'delivering')
            SQL);

        $this->supersedeDuplicateEmbeddingBuilds();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('temporal_outbox_intents')) {
            return;
        }

        foreach (self::OUTBOX_TIMESTAMPS as $column) {
            if ($this->dataType($column) !== 'timestamp with time zone') {
                continue;
            }

            DB::statement(<<<SQL
                ALTER TABLE temporal_outbox_intents
                ALTER COLUMN {$column} TYPE TIMESTAMP(0) WITHOUT TIME ZONE
                USING {$column} AT TIME ZONE 'UTC'
                SQL);
        }
    }

    private function dataType(string $column): ?string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'temporal_outbox_intents'
              AND column_name = ?
            SQL, [$column]);

        return isset($row->data_type) ? (string) $row->data_type : null;
    }

    private function supersedeDuplicateEmbeddingBuilds(): void
    {
        $activeCommands = DB::table('commands')
            ->where('type', 'embedding_index_build')
            ->whereIn('status', self::ACTIVE_COMMAND_STATUSES)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (count($activeCommands) < 2) {
            return;
        }

        $keeper = array_shift($activeCommands);
        foreach ($activeCommands as $commandId) {
            $outbox = DB::table('temporal_outbox_intents')
                ->where('operation', 'start_workflow')
                ->where('workflow_id', "archibot/embedding-index/{$commandId}")
                ->whereIn('status', self::CLAIMABLE_OUTBOX_STATUSES);
            if (! $outbox->exists()) {
                continue;
            }

            $message = "Superseded by active embedding index build command #{$keeper}.";
            $updated = DB::table('commands')
                ->where('id', $commandId)
                ->whereIn('status', self::ACTIVE_COMMAND_STATUSES)
                ->update([
                    'status' => 'skipped',
                    'error' => $message,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                continue;
            }

            $outbox->update([
                'status' => 'delivered',
                'locked_at' => null,
                'delivered_at' => DB::raw('CURRENT_TIMESTAMP'),
                'last_error' => null,
                'updated_at' => now(),
            ]);
        }
    }
};
