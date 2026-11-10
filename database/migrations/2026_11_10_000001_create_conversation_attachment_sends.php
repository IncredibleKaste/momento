<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_attachment_capacity', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('conversation_attachment_capacity')->insert(['id' => 1]);

        Schema::create('conversation_attachment_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->uuid('nonce');
            $table->char('request_fingerprint', 64);
            $table->uuid('attempt_token')->unique();
            $table->string('status', 24);
            $table->longText('body')->nullable();
            $table->json('files');
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedBigInteger('charged_bytes')->default(0);
            $table->foreignId('message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('cleanup_due_at')->nullable();
            $table->timestamp('cleanup_completed_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'sender_id', 'nonce']);
            $table->index(['status', 'cleanup_due_at']);
            $table->index(['sender_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_attachment_sends');
        Schema::dropIfExists('conversation_attachment_capacity');
    }
};
