<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The vocabulary base: one entry per lemma AND part of speech per language per
     * learner. See docs/cards.md "The vocabulary base".
     */
    public function up(): void
    {
        Schema::create('base_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('lemma');
            $table->string('part_of_speech');

            // The extra grammatical facts this language's guideline defines for this part
            // of speech (Swedish noun => {"gender": "neuter"}). Named `grammar_attributes`
            // rather than plain `attributes`, which collides with Eloquent's own internal
            // attribute bag ($model->attributes) and would make the column unreadable
            // from inside the model.
            $table->json('grammar_attributes')->nullable();

            $table->string('translation');
            $table->timestamp('last_recalled_at')->nullable();
            $table->timestamps();

            // The real dedup key: *run* the verb and *run* the noun are two entries.
            $table->unique(['user_id', 'language_id', 'lemma', 'part_of_speech']);
        });

        Schema::create('card_base_word', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('base_word_id')->constrained()->cascadeOnDelete();

            // The spelling this card's Term actually uses (`kostade` in the Term,
            // `kosta` in the base). Carried for the deferred hide-a-word mode.
            $table->string('surface_form');

            // One link per base entry per card, even when the Term repeats a word.
            $table->unique(['card_id', 'base_word_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_base_word');
        Schema::dropIfExists('base_words');
    }
};
