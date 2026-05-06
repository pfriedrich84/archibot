<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_jobs', function (Blueprint $table): void {
            $table->timestamp('cancellation_requested_at')->nullable()->after('finished_at');
            $table->foreignId('retry_of_worker_job_id')->nullable()->after('created_by_user_id')->constrained('worker_jobs')->nullOnDelete();
        });

        Schema::create('worker_job_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_job_id')->constrained('worker_jobs')->cascadeOnDelete();
            $table->string('stream')->default('stdout');
            $table->string('level')->default('info');
            $table->string('event')->nullable();
            $table->unsignedBigInteger('paperless_document_id')->nullable();
            $table->string('phase')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['worker_job_id', 'id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_job_logs');

        Schema::table('worker_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('retry_of_worker_job_id');
            $table->dropColumn('cancellation_requested_at');
        });
    }
};
