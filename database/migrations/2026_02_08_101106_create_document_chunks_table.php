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
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->unsignedInteger('token_count');
            $table->unsignedInteger('start_offset');
            $table->unsignedInteger('end_offset');
            $table->string('section_title')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['document_id', 'chunk_index']);
            $table->index(['document_id', 'token_count']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
