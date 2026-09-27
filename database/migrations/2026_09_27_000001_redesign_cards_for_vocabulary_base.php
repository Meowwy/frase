<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The vocabulary-base redesign, card side (see USERFLOW.md, docs/cards.md):
     *
     *  - `phrase` becomes `term` — the canonical name for what a card teaches.
     *  - `word` goes: there is no focus word any more. A card is about its whole Term,
     *    and which of the Term's words are learnt is recorded in `card_base_word`.
     *  - `example_1..3` go with the "learn it in a phrase instead" suggestions they
     *    powered; the anchor phrase replaces that role for word cards.
     *  - `term_type` goes: it is now derived from `card_shape` (expression vs. the rest),
     *    so the two can no longer disagree.
     *  - `card_shape` is stored instead, set once from CALL 1's `card_kind`. The default
     *    covers the manual /add path, which has no AI answer to read it from.
     *  - `anchor`/`anchor_translation` hold a word card's anchor phrase.
     *
     * Existing cards get no base words — those are expendable, a fresh start on deploy is
     * acceptable (USERFLOW.md "Out of scope"). Their SHAPE is not expendable though, so it
     * is backfilled below rather than left on the default: letting every old expression
     * card become lexical would quietly undo what `term_type` existed for, putting whole
     * utterances back into the lexical-only learning modes.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->renameColumn('phrase', 'term');
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->string('card_shape')->default('word')->after('term');
            $table->string('anchor')->nullable()->after('card_shape');
            $table->string('anchor_translation')->nullable()->after('anchor');
        });

        // `term_type` only ever recorded expression-vs-lexical; the word/phrase split
        // inside lexical is derived from word count, the same rule CardController::update()
        // applies when a learner corrects the type by hand.
        DB::table('cards')->update([
            'card_shape' => DB::raw("CASE
                WHEN term_type = 'expression' THEN 'expression'
                WHEN term LIKE '% %' THEN 'phrase'
                ELSE 'word'
            END"),
        ]);

        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['word', 'example_1', 'example_2', 'example_3', 'term_type']);
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['card_shape', 'anchor', 'anchor_translation']);
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->string('word')->nullable()->after('term');
            $table->string('term_type')->default('lexical')->after('term');
            $table->string('example_1')->nullable()->after('example_sentence');
            $table->string('example_2')->nullable()->after('example_1');
            $table->string('example_3')->nullable()->after('example_2');
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->renameColumn('term', 'phrase');
        });
    }
};
