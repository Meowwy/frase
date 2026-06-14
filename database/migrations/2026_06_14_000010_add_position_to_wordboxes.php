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
        Schema::table('wordboxes', function (Blueprint $table) {
            // Display order of a user's wordboxes within a language (lower = earlier).
            $table->unsignedInteger('position')->default(0)->after('language_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wordboxes', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
