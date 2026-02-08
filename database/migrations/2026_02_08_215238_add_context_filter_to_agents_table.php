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
        Schema::table('agents', function (Blueprint $table) {
            $table->string('context_strategy')->nullable()->after('model_config');
            $table->json('context_options')->nullable()->after('context_strategy');
            $table->float('context_threshold')->default(0.8)->after('context_options');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['context_strategy', 'context_options', 'context_threshold']);
        });
    }
};
