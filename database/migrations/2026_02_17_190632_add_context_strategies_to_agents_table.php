<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->json('context_strategies')->nullable()->after('context_strategy');
        });

        // Migrate existing context_strategy values to context_strategies array
        DB::table('agents')
            ->whereNotNull('context_strategy')
            ->update([
                'context_strategies' => DB::raw('json_build_array(context_strategy)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('context_strategies');
        });
    }
};
