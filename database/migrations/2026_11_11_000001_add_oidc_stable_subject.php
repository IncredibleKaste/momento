<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oidc_identities', function (Blueprint $table): void {
            $table->string('stable_subject', 255)->nullable();
            $table->unique(['provider', 'issuer', 'stable_subject']);
        });
    }

    public function down(): void
    {
        if (DB::table('oidc_identities')->whereNotNull('stable_subject')->exists()) {
            throw new RuntimeException('Cannot remove stable OIDC identity bindings while stable identities remain.');
        }

        Schema::table('oidc_identities', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'issuer', 'stable_subject']);
            $table->dropColumn('stable_subject');
        });
    }
};
