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
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->after('type')->constrained()->nullOnDelete();
            $table->string('storage_path')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
            $table->string('storage_path')->nullable(false)->change();
        });
    }
};
