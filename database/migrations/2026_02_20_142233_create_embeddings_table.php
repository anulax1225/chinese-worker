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
        Schema::create('embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->string('text_hash', 64);
            $table->json('embedding_raw')->nullable();
            $table->string('model');
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->unsignedInteger('dimensions')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('text_hash');
        });

        // Add pgvector native vector column (unbounded to support different embedding models)
        // Note: HNSW index requires fixed dimensions, so we skip it here.
        // For high-performance similarity search, consider creating dimension-specific
        // indexes on commonly used embedding sizes.
        DB::statement('ALTER TABLE embeddings ADD COLUMN embedding vector');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('embeddings');
    }
};
