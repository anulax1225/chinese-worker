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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Conversation state
            $table->enum('status', ['active', 'paused', 'completed', 'failed', 'cancelled'])->default('active');
            $table->json('messages'); // Full ChatMessage[] array
            $table->json('metadata')->nullable(); // Custom data, context

            // Statistics
            $table->integer('turn_count')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // CLI session tracking
            $table->string('cli_session_id')->nullable(); // Track which CLI instance
            $table->enum('waiting_for', ['none', 'tool_result', 'user_input'])->default('none');
            $table->json('pending_tool_request')->nullable(); // Tool waiting for CLI execution

            $table->timestamps();

            // Indexes for common queries
            $table->index(['agent_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
