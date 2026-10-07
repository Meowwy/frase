<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging no longer merges existing cards away on approval: a proposal whose words are all
 * on one card is refused instead. See docs/cards.md "Staging".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('merge_card_ids');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->json('merge_card_ids')->nullable()->after('senses');
        });
    }
};
