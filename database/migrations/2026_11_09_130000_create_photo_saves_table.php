<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photo_post_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_saved')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('last_request_id');
            $table->unsignedBigInteger('saved_order')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'photo_post_id']);
            $table->index(['user_id', 'is_saved', 'saved_order', 'photo_post_id']);
            $table->index(['user_id', 'saved_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_saves');
    }
};
