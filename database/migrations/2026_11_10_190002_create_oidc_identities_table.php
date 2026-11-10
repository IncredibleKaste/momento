<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('issuer', 255);
            $table->string('subject', 255);
            $table->string('institution_domain', 253)->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'issuer', 'subject']);
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('password')->exists()) {
            throw new RuntimeException('Cannot reverse OIDC account support while passwordless users remain.');
        }

        Schema::dropIfExists('oidc_identities');
    }
};
