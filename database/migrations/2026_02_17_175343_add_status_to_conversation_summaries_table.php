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
        Schema::table('conversation_summaries', function (Blueprint $table) {
            $table->string('status')->default('completed')->after('conversation_id');
            $table->text('error_message')->nullable()->after('metadata');

            // Make columns nullable for pending/processing summaries
            $table->text('content')->nullable()->change();
            $table->unsignedInteger('token_count')->nullable()->change();
            $table->string('backend_used')->nullable()->change();
            $table->string('model_used')->nullable()->change();
            $table->json('summarized_message_ids')->nullable()->change();
            $table->unsignedInteger('original_token_count')->nullable()->change();

            $table->index('status');
        });

        // Mark existing summaries as completed
        \Illuminate\Support\Facades\DB::table('conversation_summaries')
            ->whereNull('status')
            ->update(['status' => 'completed']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_summaries', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'error_message']);

            // Revert nullable changes
            $table->text('content')->nullable(false)->change();
            $table->unsignedInteger('token_count')->nullable(false)->change();
            $table->string('backend_used')->nullable(false)->change();
            $table->string('model_used')->nullable(false)->change();
            $table->json('summarized_message_ids')->nullable(false)->change();
            $table->unsignedInteger('original_token_count')->nullable(false)->change();
        });
    }
};
