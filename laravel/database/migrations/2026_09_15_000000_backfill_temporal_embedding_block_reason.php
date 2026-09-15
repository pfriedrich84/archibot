<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pipeline_runs')) {
            return;
        }

        DB::table('pipeline_runs')
            ->where('type', 'document')
            ->where('orchestration_driver', 'temporal')
            ->where('status', 'blocked')
            ->where('progress_current_phase', 'waiting_for_embedding')
            ->whereNull('error_type')
            ->update([
                'error_type' => 'embedding_index_not_ready',
                'error' => 'Waiting for embedding index to complete.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // This migration repairs missing lifecycle metadata and is intentionally irreversible.
    }
};
