<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    public function up(): void
    {
        Schema::create('message_embeddings', function (Blueprint $table) {
            $table->id();
            $table->ulid('message_id');
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            // Embedding data
            $table->json('embedding_raw')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('embedding_dimensions')->default(1536);
            $table->timestamp('embedding_generated_at')->nullable();

            // Sparse vector for hybrid search
            $table->json('sparse_vector')->nullable();

            // Content tracking
            $table->string('content_hash', 64);
            $table->unsignedInteger('token_count')->nullable();

            // Quality and usage
            $table->float('quality_score')->default(1.0);
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->unique('message_id');
            $table->index('conversation_id');
            $table->index('content_hash');
            $table->index('embedding_generated_at');
        });

        // PostgreSQL-specific: Add native pgvector column and indexes
        if ($this->isPostgres()) {
            // Add native vector column for efficient similarity search
            DB::statement('ALTER TABLE message_embeddings ADD COLUMN embedding vector(1536)');

            // Create HNSW index for fast approximate nearest neighbor search
            DB::statement('
                CREATE INDEX idx_message_embeddings_hnsw
                ON message_embeddings
                USING hnsw (embedding vector_cosine_ops)
                WITH (m=16, ef_construction=64)
            ');

            // Create GIN index for sparse vector
            DB::statement('ALTER TABLE message_embeddings ALTER COLUMN sparse_vector TYPE jsonb USING sparse_vector::jsonb');
            DB::statement('CREATE INDEX idx_message_embeddings_sparse ON message_embeddings USING GIN (sparse_vector jsonb_path_ops)');
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS idx_message_embeddings_sparse');
            DB::statement('DROP INDEX IF EXISTS idx_message_embeddings_hnsw');
        }

        Schema::dropIfExists('message_embeddings');
    }
};
