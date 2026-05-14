<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_jobs', function (Blueprint $table): void {
            $table->string('worker_id')->nullable()->after('dispatch_attempts')->index();
            $table->timestamp('lease_expires_at')->nullable()->after('worker_id')->index();
            $table->timestamp('heartbeat_at')->nullable()->after('lease_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('worker_jobs', function (Blueprint $table): void {
            $table->dropIndex(['worker_id']);
            $table->dropIndex(['lease_expires_at']);
            $table->dropColumn(['worker_id', 'lease_expires_at', 'heartbeat_at']);
        });
    }
};
