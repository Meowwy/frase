<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Known words: what the learner struck in staging, remembered so it is never proposed
     * again. A candidate's state is derived live from this table, so the per-proposal
     * `struck` flag goes. See docs/cards.md "Staging".
     */
    public function up(): void
    {
        Schema::create('known_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('lemma');
            $table->string('part_of_speech');
            $table->timestamps();

            // The same key as the vocabulary base, so every inflected form is covered.
            $table->unique(['user_id', 'language_id', 'lemma', 'part_of_speech']);
        });

        Schema::table('proposal_base_words', function (Blueprint $table) {
            $table->dropColumn('struck');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_base_words', function (Blueprint $table) {
            $table->boolean('struck')->default(false);
        });

        Schema::dropIfExists('known_words');
    }
};
