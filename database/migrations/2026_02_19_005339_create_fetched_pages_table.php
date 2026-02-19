<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fetched_pages', function (Blueprint $table) {
            $table->id();

            // URL identity
            $table->text('url');
            $table->string('url_hash', 64)->unique();

            // Content
            $table->string('title')->nullable();
            $table->string('content_type')->default('text/html');
            $table->string('content_hash', 64)->index();
            $table->longText('text');

            // Timestamps
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('embedded_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fetched_pages');
    }
};
