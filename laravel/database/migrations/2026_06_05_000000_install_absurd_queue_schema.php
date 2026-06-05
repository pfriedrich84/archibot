<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Install the PostgreSQL functions and tables required by Absurd.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(file_get_contents(database_path('sql/absurd.sql')));
    }

    /**
     * Remove the Absurd schema. Queue contents are runtime state and are safe to
     * recreate from ArchiBot's durable commands/pipeline tables during recovery.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP SCHEMA IF EXISTS absurd CASCADE');
    }
};
