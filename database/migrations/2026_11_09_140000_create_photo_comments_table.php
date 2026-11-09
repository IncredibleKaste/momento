<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_post_id')->constrained('photo_posts')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->uuid('submission_key');
            $table->char('body_fingerprint', 64);
            $table->text('body')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->unique(['author_id', 'photo_post_id', 'submission_key']);
            $table->index(['photo_post_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_comments');
    }
};
