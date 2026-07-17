<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actor_executions', function (Blueprint $table) {
            $table->foreignId('command_id')
                ->nullable()
                ->after('pipeline_run_id')
                ->constrained('commands')
                ->nullOnDelete();
            $table->foreignId('webhook_delivery_id')
                ->nullable()
                ->after('command_id')
                ->constrained('webhook_deliveries')
                ->nullOnDelete();
        });

        // Pre-cutover non-pipeline executions cannot be linked safely after the fact.
        // Make them explicitly terminal instead of allowing ambiguous automatic replay.
        DB::table('actor_executions')
            ->whereNull('pipeline_run_id')
            ->whereNull('command_id')
            ->whereNull('webhook_delivery_id')
            ->whereIn('status', ['running', 'retrying'])
            ->update([
                'status' => 'failed_permanent',
                'finished_at' => now(),
                'next_retry_at' => null,
                'error_type' => 'source_link_unavailable_after_upgrade',
                'error_message' => 'Pre-cutover actor execution requires operator reconciliation.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('actor_executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('webhook_delivery_id');
            $table->dropConstrainedForeignId('command_id');
        });
    }
};
