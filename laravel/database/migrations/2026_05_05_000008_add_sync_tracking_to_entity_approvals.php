<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_approvals', function (Blueprint $table) {
            $table->string('sync_status')->nullable()->index();
            $table->foreignId('sync_worker_job_id')->nullable()->constrained('worker_jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entity_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sync_worker_job_id');
            $table->dropColumn('sync_status');
        });
    }
};
