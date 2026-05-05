<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('status')->default('queued')->index();
            $table->json('payload')->nullable();
            $table->string('input_path')->nullable();
            $table->string('output_path')->nullable();
            $table->json('result')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_jobs');
    }
};
