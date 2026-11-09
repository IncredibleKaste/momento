<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photo_post_id')->constrained()->cascadeOnDelete();
            $table->boolean('liked')->default(false);
            $table->unsignedBigInteger('revision')->default(0);
            $table->uuid('last_request_id')->nullable();
            $table->boolean('last_request_liked')->nullable();
            $table->unsignedBigInteger('last_request_expected_revision')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'photo_post_id']);
            $table->index(['photo_post_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_likes');
    }
};
