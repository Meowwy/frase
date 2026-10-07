<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cards no longer carry an example sentence, and the Sentences learning modes it powered
 * are gone with it. See docs/cards.md "Schema".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('example_sentence');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('example_sentence')->default('');
        });
    }
};
