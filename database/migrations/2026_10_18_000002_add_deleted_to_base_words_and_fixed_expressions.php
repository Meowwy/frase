<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A base word or fixed expression the learner deleted from /base: hidden everywhere, but its
 * card links and Frammenti progress stay, and approving it again clears the flag.
 * See docs/cards.md "Deleting an entry".
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['base_words', 'fixed_expressions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('deleted')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['base_words', 'fixed_expressions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('deleted');
            });
        }
    }
};
