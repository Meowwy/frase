<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenge_game_results', function (Blueprint $table) {
            // Which seeded scene the game was played on (nullable — the AI may self-pick).
            $table->foreignId('challenge_scene_id')->nullable()->after('language_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('challenge_game_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('challenge_scene_id');
        });
    }
};
