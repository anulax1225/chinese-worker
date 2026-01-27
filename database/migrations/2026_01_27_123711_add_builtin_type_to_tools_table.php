<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify the enum to include 'builtin' type
        DB::statement("ALTER TABLE tools MODIFY COLUMN type ENUM('api', 'function', 'command', 'builtin') NOT NULL");

        // Make user_id nullable for system-level built-in tools
        DB::statement('ALTER TABLE tools MODIFY COLUMN user_id BIGINT UNSIGNED NULL');

        // Drop the foreign key constraint first, then re-add with SET NULL
        DB::statement('ALTER TABLE tools DROP FOREIGN KEY tools_user_id_foreign');
        DB::statement('ALTER TABLE tools ADD CONSTRAINT tools_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove builtin tools first
        DB::table('tools')->where('type', 'builtin')->delete();

        // Revert the enum
        DB::statement("ALTER TABLE tools MODIFY COLUMN type ENUM('api', 'function', 'command') NOT NULL");

        // Revert user_id to not null with cascade delete
        DB::statement('ALTER TABLE tools DROP FOREIGN KEY tools_user_id_foreign');
        DB::statement('ALTER TABLE tools MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE tools ADD CONSTRAINT tools_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    }
};
