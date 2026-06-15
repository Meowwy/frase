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
        Schema::table('gap_fill_exercises', function (Blueprint $table) {
            $table->string('title')->nullable()->after('theme_preference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gap_fill_exercises', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
