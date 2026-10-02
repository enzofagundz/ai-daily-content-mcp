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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->unique();
            $table->string('url');
            $table->text('text');
            $table->string('author');
            $table->timestamp('published_at');
            $table->timestamp('presented_at')->nullable();
            $table->timestamps();

            $table->index(['presented_at', 'published_at']);
            $table->index(['profile_id', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
