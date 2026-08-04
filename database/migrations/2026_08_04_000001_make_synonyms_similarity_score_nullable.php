<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `synonyms` table doubles as the store for user-created "linked cards".
     * A manual link has no similarity score, so the column must accept null.
     */
    public function up(): void
    {
        Schema::table('synonyms', function (Blueprint $table) {
            $table->float('similarity_score')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('synonyms', function (Blueprint $table) {
            $table->float('similarity_score')->nullable(false)->change();
        });
    }
};
