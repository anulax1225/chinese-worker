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
        Schema::create('conversation_summaries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('from_position');
            $table->unsignedInteger('to_position');
            $table->text('content');
            $table->unsignedInteger('token_count');
            $table->string('backend_used');
            $table->string('model_used');
            $table->json('summarized_message_ids');
            $table->unsignedInteger('original_token_count');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'from_position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_summaries');
    }
};
