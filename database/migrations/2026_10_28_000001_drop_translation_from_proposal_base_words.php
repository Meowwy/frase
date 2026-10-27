<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CALL 1 no longer translates the candidate words: only a word the base doesn't hold yet is
 * described, by CALL 2 at approval, so there is nothing to carry on the chip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_base_words', function (Blueprint $table) {
            $table->dropColumn('translation');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_base_words', function (Blueprint $table) {
            $table->string('translation')->default('');
        });
    }
};
