<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('language_user', function (Blueprint $table) {
            // The user's proficiency in this specific target language (CEFR: A1..C2).
            // Nullable so existing rows and freshly added languages start without a level.
            $table->string('users_level', 2)->nullable()->after('language_id');
        });
    }

    public function down(): void
    {
        Schema::table('language_user', function (Blueprint $table) {
            $table->dropColumn('users_level');
        });
    }
};
