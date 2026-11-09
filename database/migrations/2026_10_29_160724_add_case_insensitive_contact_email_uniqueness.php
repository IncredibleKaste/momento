<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::selectOne('SELECT 1 FROM users GROUP BY lower(email) HAVING COUNT(*) > 1 LIMIT 1') !== null) {
            throw new RuntimeException('Cannot enforce case-insensitive contact email uniqueness while duplicate emails exist.');
        }

        DB::statement('CREATE UNIQUE INDEX users_email_case_insensitive_unique ON users (lower(email))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX users_email_case_insensitive_unique');
    }
};
