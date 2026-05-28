<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->foreignId('commit_command_id')
                ->nullable()
                ->after('commit_worker_job_id')
                ->constrained('commands')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commit_command_id');
        });
    }
};
