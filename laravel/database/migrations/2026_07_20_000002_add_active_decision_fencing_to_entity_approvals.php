<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('decision_version')->default(0);
            $table->uuid('active_decision_token')->nullable()->unique();
            $table->string('active_decision_action')->nullable();
            $table->foreignId('active_decision_command_id')->nullable()
                ->constrained('commands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entity_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_decision_command_id');
            $table->dropUnique(['active_decision_token']);
            $table->dropColumn([
                'decision_version',
                'active_decision_token',
                'active_decision_action',
            ]);
        });
    }
};
