<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_jobs', function (Blueprint $table): void {
            $table->string('dispatch_key', 64)->nullable()->after('payload')->index();
            $table->unsignedInteger('dispatch_attempts')->default(0)->after('dispatch_key');
            $table->timestamp('dispatched_at')->nullable()->after('dispatch_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('worker_jobs', function (Blueprint $table): void {
            $table->dropIndex(['dispatch_key']);
            $table->dropColumn(['dispatch_key', 'dispatch_attempts', 'dispatched_at']);
        });
    }
};
