<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A downloaded dictionary's grammatical facts, used in preference to CALL 1's answer
     * wherever it knows the word (Swedish: imported from SALDO by `lexicon:import-saldo`).
     * Global rather than per learner — a noun's gender is the same for everyone. See
     * docs/cards.md "The lexicon".
     */
    public function up(): void
    {
        Schema::create('lexicon_entries', function (Blueprint $table) {
            $table->id();
            $table->string('language_code');
            $table->string('lemma');
            $table->string('part_of_speech');
            $table->string('gender')->nullable();
            $table->string('dictionary_form')->nullable();

            // The source dictionary's own inflection-class code, kept for debugging.
            $table->string('paradigm');

            // Not unique: homographs are separate rows ("ett plan" and "en plan").
            $table->index(['language_code', 'lemma', 'part_of_speech']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lexicon_entries');
    }
};
