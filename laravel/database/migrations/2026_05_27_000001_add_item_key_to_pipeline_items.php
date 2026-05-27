<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_items', function (Blueprint $table) {
            $table->string('item_key')->nullable()->after('item_type');
            $table->unique(['pipeline_run_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_items', function (Blueprint $table) {
            $table->dropUnique(['pipeline_run_id', 'item_key']);
            $table->dropColumn('item_key');
        });
    }
};
