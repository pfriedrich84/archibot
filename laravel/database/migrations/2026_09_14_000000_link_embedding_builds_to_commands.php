<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embedding_index_state', function (Blueprint $table): void {
            $table->foreignId('command_id')->nullable()->after('id')
                ->constrained('commands')->nullOnDelete();
            $table->index(['command_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('embedding_index_state', function (Blueprint $table): void {
            $table->dropIndex(['command_id', 'status']);
            $table->dropConstrainedForeignId('command_id');
        });
    }
};
