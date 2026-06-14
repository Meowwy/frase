<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            // Nullable for now so the column can be added without a default; backfilled afterwards.
            $table->foreignId('language_id')->nullable()->constrained('languages')->restrictOnDelete();
            $table->index(['user_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'language_id']);
            $table->dropConstrainedForeignId('language_id');
        });
    }
};
