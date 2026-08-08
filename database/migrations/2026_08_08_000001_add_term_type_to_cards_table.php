<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flag what kind of term a card teaches, so the AI can generate content of the
     * right shape and the learning flow can treat the two differently later:
     *
     *  - `lexical`    a naming unit — answers "X means ...". Single words, collocations
     *                 and idioms alike (cabinet, traffic jam, under the weather).
     *  - `expression` a ready-made utterance or utterance frame — answers "you say X
     *                 when you want to ..." (I'd rather not, can you hand me the ...).
     *
     * The database-level default covers every existing row and the legacy creation
     * paths (CreateCardJob, CallAIJob, the manual-create route closure) untouched.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('term_type')->default('lexical')->after('phrase');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('term_type');
        });
    }
};
