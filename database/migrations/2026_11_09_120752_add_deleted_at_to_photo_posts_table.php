<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_posts', function (Blueprint $table): void {
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('photo_posts', function (Blueprint $table): void {
            $table->dropColumn('deleted_at');
        });
    }
};
