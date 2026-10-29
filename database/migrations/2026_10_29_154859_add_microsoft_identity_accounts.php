<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
            $table->timestamp('disabled_at')->nullable();
        });
        Schema::create('microsoft_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('tenant_id');
            $table->uuid('object_id');
            $table->string('subject');
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'object_id']);
            $table->unique(['tenant_id', 'subject']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('users')->whereNull('password')->exists()) {
            throw new RuntimeException('Remove provider-only accounts before reversing this migration.');
        }
        Schema::dropIfExists('microsoft_identities');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('disabled_at');
            $table->string('password')->nullable(false)->change();
        });
    }
};
