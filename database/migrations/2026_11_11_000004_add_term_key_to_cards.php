<?php

use App\Models\Card;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Term reduced for the duplicate check (Card::termKey(): case, punctuation and spacing
 * ignored), so staging's check stays one indexed lookup. See docs/cards.md "The duplicate check".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('term_key')->default('');
            $table->index(['user_id', 'language_id', 'term_key']);
        });

        DB::table('cards')->select('id', 'term')->orderBy('id')->each(function ($card) {
            DB::table('cards')->where('id', $card->id)->update(['term_key' => Card::termKey((string) $card->term)]);
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'language_id', 'term_key']);
            $table->dropColumn('term_key');
        });
    }
};
