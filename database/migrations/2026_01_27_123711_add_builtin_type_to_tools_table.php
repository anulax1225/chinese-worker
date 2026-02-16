<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Note: The type column and user_id nullable have been moved to the original
     * create_tools_table migration for PostgreSQL compatibility.
     */
    public function up(): void
    {
        // No-op: changes incorporated into original migration for PostgreSQL compatibility
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
