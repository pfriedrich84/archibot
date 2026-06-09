<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->unsignedBigInteger('source_suggestion_id')
                ->nullable()
                ->after('id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->dropIndex(['source_suggestion_id']);
            $table->dropColumn('source_suggestion_id');
        });
    }
};
