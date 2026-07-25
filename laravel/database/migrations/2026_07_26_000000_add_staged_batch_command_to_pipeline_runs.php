<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_runs', function (Blueprint $table): void {
            $table->foreignId('batch_command_id')
                ->nullable()
                ->after('command_id')
                ->constrained('commands')
                ->nullOnDelete();
            $table->index(['batch_command_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_runs', function (Blueprint $table): void {
            $table->dropIndex(['batch_command_id', 'status']);
            $table->dropConstrainedForeignId('batch_command_id');
        });
    }
};
