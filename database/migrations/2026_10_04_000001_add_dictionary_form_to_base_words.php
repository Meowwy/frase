<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The dictionary-style display form for the parts of speech whose language writes one
     * — a Swedish verb's "komm|a -er". Stored rather than computed, because where a verb's
     * stem ends and which present-tense ending it takes are facts about that one verb and
     * not derivable from the lemma the way a noun's "en"/"ett" is from its gender.
     *
     * Null for every word whose guideline asks for none, which is most of them. It is not
     * part of the dedup key: "komma" stays one entry. See docs/cards.md.
     */
    public function up(): void
    {
        Schema::table('base_words', function (Blueprint $table) {
            $table->string('dictionary_form')->nullable()->after('part_of_speech');
        });

        Schema::table('proposal_base_words', function (Blueprint $table) {
            $table->string('dictionary_form')->nullable()->after('part_of_speech');
        });
    }

    public function down(): void
    {
        Schema::table('base_words', function (Blueprint $table) {
            $table->dropColumn('dictionary_form');
        });

        Schema::table('proposal_base_words', function (Blueprint $table) {
            $table->dropColumn('dictionary_form');
        });
    }
};
