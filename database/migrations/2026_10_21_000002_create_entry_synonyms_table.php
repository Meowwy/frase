<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Synonym links between base words and fixed expressions, made by hand on /base and read
     * by Frammenti (docs/cards.md "Synonym links"). A link is two mirrored rows, so either
     * entry's synonyms are one where. The entries live in two tables, so they carry no
     * foreign key: a deleted entry is only flagged, and its links stay.
     */
    public function up(): void
    {
        Schema::create('entry_synonyms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            // "word" or "expression", as /base keys its rows.
            $table->string('kind');
            $table->unsignedBigInteger('entry_id');
            $table->string('other_kind');
            $table->unsignedBigInteger('other_id');
            $table->timestamps();

            $table->unique(['kind', 'entry_id', 'other_kind', 'other_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_synonyms');
    }
};
