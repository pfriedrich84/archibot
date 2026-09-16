<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('temporal_model_phase_states');
    }

    public function down(): void
    {
        Schema::create('temporal_model_phase_states', function (Blueprint $table): void {
            $table->id();
            $table->string('scheduler_workflow_id');
            $table->unsignedBigInteger('cycle');
            $table->string('phase');
            $table->string('status')->index();
            $table->string('model_id')->nullable();
            $table->string('configuration_revision')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['scheduler_workflow_id', 'cycle', 'phase'],
                'temporal_model_phase_unique',
            );
        });
    }
};
