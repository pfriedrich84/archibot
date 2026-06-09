<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->string('commit_status')->nullable()->after('reviewed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->dropIndex(['commit_status']);
            $table->dropColumn('commit_status');
        });
    }
};
