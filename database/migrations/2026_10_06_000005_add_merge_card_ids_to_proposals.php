<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merge: the existing cards the learner marked to be removed when this proposal is
     * approved. See docs/cards.md "Staging".
     */
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->json('merge_card_ids')->nullable()->after('senses');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('merge_card_ids');
        });
    }
};
