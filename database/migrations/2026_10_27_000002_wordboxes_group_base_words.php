<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A wordbox groups base words, not cards, and exists to feed gap-fill
     * (docs/wordboxes-themes-tags.md). The old card wordboxes and their exercises are
     * deleted rather than converted. An exercise remembers which of the box's words it was
     * generated from.
     */
    public function up(): void
    {
        DB::table('gap_fill_exercises')->delete();
        DB::table('wordboxes')->delete();

        Schema::dropIfExists('wordbox_card');

        Schema::create('base_word_wordbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('base_word_id')->constrained()->cascadeOnDelete();

            $table->unique(['wordbox_id', 'base_word_id']);
        });

        Schema::table('gap_fill_exercises', function (Blueprint $table) {
            $table->json('base_word_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gap_fill_exercises', function (Blueprint $table) {
            $table->dropColumn('base_word_ids');
        });

        Schema::dropIfExists('base_word_wordbox');

        Schema::create('wordbox_card', function (Blueprint $table) {
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wordbox_id')->constrained()->cascadeOnDelete();
        });
    }
};
