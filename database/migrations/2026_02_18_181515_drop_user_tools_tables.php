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
        // Drop pivot table first (has FK to tools)
        Schema::dropIfExists('agent_tools');
        Schema::dropIfExists('tools');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate tools table
        Schema::create('tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 255);
            $table->string('type', 20)->default('function');
            $table->json('config');
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        // Recreate pivot table
        Schema::create('agent_tools', function (Blueprint $table) {
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tool_id')->constrained()->cascadeOnDelete();

            $table->primary(['agent_id', 'tool_id']);
        });
    }
};
