<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_runs', function (Blueprint $table) {
            $table->dropUnique(['temporal_workflow_id']);
            $table->index('temporal_workflow_id', 'pipeline_runs_temporal_workflow_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_runs', function (Blueprint $table) {
            $table->dropIndex('pipeline_runs_temporal_workflow_id_index');
            $table->unique('temporal_workflow_id');
        });
    }
};
