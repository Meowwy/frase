<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meanings under one base word: *bank* stays one row per lemma + part of speech, but
     * can hold several meanings (money / riverside), each linked to the cards that use it.
     * See docs/cards.md "The vocabulary base".
     */
    public function up(): void
    {
        Schema::create('base_word_senses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_word_id')->constrained()->cascadeOnDelete();
            $table->string('translation');
            $table->timestamps();
        });

        Schema::table('card_base_word', function (Blueprint $table) {
            $table->foreignId('base_word_sense_id')->nullable()->constrained('base_word_senses')->nullOnDelete();
        });

        Schema::table('proposal_base_words', function (Blueprint $table) {
            // Staging's match against the saved meanings: the one it was judged to use, or
            // the translation of a new one.
            $table->unsignedBigInteger('base_word_sense_id')->nullable();
            $table->string('new_sense_translation')->nullable();
        });

        // Every existing word has exactly one meaning: its translation.
        $now = now();

        DB::statement(
            'INSERT INTO base_word_senses (base_word_id, translation, created_at, updated_at) '
            ."SELECT id, translation, ?, ? FROM base_words WHERE translation != ''",
            [$now, $now]
        );

        DB::statement(
            'UPDATE card_base_word SET base_word_sense_id = '
            .'(SELECT MIN(s.id) FROM base_word_senses s WHERE s.base_word_id = card_base_word.base_word_id)'
        );
    }

    public function down(): void
    {
        Schema::table('proposal_base_words', function (Blueprint $table) {
            $table->dropColumn(['base_word_sense_id', 'new_sense_translation']);
        });

        Schema::table('card_base_word', function (Blueprint $table) {
            $table->dropConstrainedForeignId('base_word_sense_id');
        });

        Schema::dropIfExists('base_word_senses');
    }
};
