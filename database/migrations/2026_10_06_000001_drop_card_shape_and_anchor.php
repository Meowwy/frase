<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One card type: the Term is kept as typed and no longer classified as a word, phrase
     * or expression, and the anchor phrase goes with the word shape that carried it. See
     * docs/cards.md. Existing data is expendable, so nothing is backfilled on the way down.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['card_shape', 'anchor', 'anchor_translation']);
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['card_shape', 'anchor']);
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('card_shape')->default('word')->after('term');
            $table->string('anchor')->nullable()->after('card_shape');
            $table->string('anchor_translation')->nullable()->after('anchor');
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->string('card_shape')->nullable()->after('term');
            $table->string('anchor')->nullable()->after('card_shape');
        });
    }
};
