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
        Schema::table('conversations', function (Blueprint $table) {
            $table->integer('prompt_tokens')->default(0)->after('total_tokens');
            $table->integer('completion_tokens')->default(0)->after('prompt_tokens');
            $table->integer('context_limit')->nullable()->after('completion_tokens');
            $table->integer('estimated_context_usage')->default(0)->after('context_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn([
                'prompt_tokens',
                'completion_tokens',
                'context_limit',
                'estimated_context_usage',
            ]);
        });
    }
};
