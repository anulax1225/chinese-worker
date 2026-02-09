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
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_synthetic')->default(false)->after('pinned');
            $table->boolean('summarized')->default(false)->after('is_synthetic');
            $table->ulid('summary_id')->nullable()->after('summarized');

            $table->index(['conversation_id', 'is_synthetic']);
            $table->index(['conversation_id', 'summarized']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'is_synthetic']);
            $table->dropIndex(['conversation_id', 'summarized']);
            $table->dropColumn(['is_synthetic', 'summarized', 'summary_id']);
        });
    }
};
