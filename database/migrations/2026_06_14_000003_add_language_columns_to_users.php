<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Currently selected target language (durable default for the save-destination picker).
            $table->foreignId('active_language_id')->nullable()->constrained('languages')->nullOnDelete();
            // The user's single native language (used for translations).
            $table->foreignId('native_language_id')->nullable()->constrained('languages')->nullOnDelete();
        });
        // NOTE: the legacy target_language / native_language string columns are kept on purpose
        // (backfill source + incremental cutover). They are dropped in a later migration.
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_language_id');
            $table->dropConstrainedForeignId('native_language_id');
        });
    }
};
