<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->foreignId('worker_job_id')
                ->nullable()
                ->after('id')
                ->constrained('worker_jobs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('worker_job_id');
        });
    }
};
