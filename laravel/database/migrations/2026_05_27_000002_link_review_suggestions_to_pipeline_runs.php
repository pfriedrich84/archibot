<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->foreignId('pipeline_run_id')
                ->nullable()
                ->after('dedupe_key')
                ->constrained('pipeline_runs')
                ->nullOnDelete();
            $table->unique('pipeline_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->dropUnique(['pipeline_run_id']);
            $table->dropConstrainedForeignId('pipeline_run_id');
        });
    }
};
