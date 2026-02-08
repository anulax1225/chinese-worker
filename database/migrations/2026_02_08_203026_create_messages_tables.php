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
        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('role'); // system, user, assistant, tool
            $table->string('name')->nullable(); // Tool name for tool responses
            $table->text('content');
            $table->text('thinking')->nullable(); // Assistant reasoning
            $table->unsignedInteger('token_count')->nullable();
            $table->string('tool_call_id')->nullable(); // References tool_calls.id for tool responses
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'position']);
            $table->index('role');
        });

        Schema::create('tool_calls', function (Blueprint $table) {
            $table->string('id')->primary(); // The tool call ID from the AI
            $table->foreignUlid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('function_name');
            $table->json('arguments');
            $table->unsignedInteger('position'); // Order within the message
            $table->timestamp('created_at')->nullable();

            $table->index('message_id');
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('type'); // image, document
            $table->string('filename')->nullable();
            $table->string('mime_type');
            $table->string('storage_path');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('message_id');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('tool_calls');
        Schema::dropIfExists('messages');
    }
};
