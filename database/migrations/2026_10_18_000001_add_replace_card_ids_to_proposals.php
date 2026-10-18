<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The existing cards the learner has switched on to be replaced by this proposal when it is
 * approved. See docs/cards.md "Replacing a contained card".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->json('replace_card_ids')->nullable()->after('senses');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('replace_card_ids');
        });
    }
};
