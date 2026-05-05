<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('paperless_username')->nullable()->unique()->after('email');
            $table->unsignedBigInteger('paperless_user_id')->nullable()->index()->after('paperless_username');
            $table->boolean('is_admin')->default(false)->after('paperless_user_id');
            $table->text('paperless_token')->nullable()->after('is_admin');
            $table->timestamp('paperless_profile_refreshed_at')->nullable()->after('paperless_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'paperless_username',
                'paperless_user_id',
                'is_admin',
                'paperless_token',
                'paperless_profile_refreshed_at',
            ]);
        });
    }
};
