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
        Schema::create('conversation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('member_one_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('member_two_id')->constrained('users')->cascadeOnDelete();
            $table->longText('body');
            $table->uuid('client_nonce');
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();
            $table->unique(['requester_id', 'client_nonce']);
            $table->index(['member_one_id', 'member_two_id', 'id']);
            $table->index(['recipient_id', 'status', 'expires_at']);
            $table->index(['requester_id', 'status', 'expires_at']);
            $table->index(['requester_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_requests');
    }
};
