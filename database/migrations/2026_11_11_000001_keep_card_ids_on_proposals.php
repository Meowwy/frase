<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replacing a contained card is now on by default, so a proposal records the cards the
 * learner switched OFF (kept) instead of the ones switched on. The old switched-on lists
 * mean nothing under the new rule and are dropped with the column. See docs/cards.md
 * "Replacing a contained card".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('replace_card_ids');
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->json('keep_card_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('keep_card_ids');
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->json('replace_card_ids')->nullable();
        });
    }
};
