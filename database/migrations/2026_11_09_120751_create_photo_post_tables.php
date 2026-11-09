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
        Schema::create('photo_upload_capacity', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
        });

        DB::table('photo_upload_capacity')->insert(['id' => 1]);

        Schema::create('photo_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('request_fingerprint', 64);
            $table->uuid('attempt_token');
            $table->string('status', 24);
            $table->text('caption')->nullable();
            $table->string('raw_key', 255);
            $table->string('output_key', 255);
            $table->unsignedBigInteger('input_bytes');
            $table->unsignedBigInteger('staging_bytes_charged')->default(0);
            $table->unsignedBigInteger('processed_bytes_charged')->default(0);
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('cleanup_due_at')->nullable();
            $table->timestamp('cleanup_completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->unique('attempt_token');
            $table->index(['status', 'cleanup_due_at']);
            $table->index(['user_id', 'status', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_posts');
        Schema::dropIfExists('photo_upload_capacity');
    }
};
