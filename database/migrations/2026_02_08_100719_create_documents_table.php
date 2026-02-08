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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('source_type'); // upload, url, paste
            $table->string('source_path'); // original file path or URL
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('status')->default('pending'); // pending, extracting, cleaning, normalizing, chunking, ready, failed
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
