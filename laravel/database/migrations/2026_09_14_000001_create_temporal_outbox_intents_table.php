<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporal_outbox_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('intent_key')->unique();
            $table->string('operation');
            $table->string('workflow_id');
            $table->string('workflow_type')->nullable();
            $table->string('task_queue')->nullable();
            $table->string('signal_name')->nullable();
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id']);
            $table->index(['workflow_id', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporal_outbox_intents');
    }
};
