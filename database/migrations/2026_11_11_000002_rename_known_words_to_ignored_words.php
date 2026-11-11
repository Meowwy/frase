<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A struck word is an "ignored word" now, not a "known word": the learner may simply not want
 * it, which says nothing about knowing it. See docs/cards.md "The vocabulary base".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('known_words', 'ignored_words');
    }

    public function down(): void
    {
        Schema::rename('ignored_words', 'known_words');
    }
};
