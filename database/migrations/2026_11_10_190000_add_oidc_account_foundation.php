<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
            $table->timestamp('disabled_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('password')->exists()) {
            throw new RuntimeException('Cannot reverse OIDC account support while passwordless users remain.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('disabled_at');
            $table->string('password')->nullable(false)->change();
        });
    }
};
