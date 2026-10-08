<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frammenti progress, kept on every base word and fixed expression. The defaults put
     * every item at tier II (tier I is disabled for now) and ready. See docs/frammenti.md
     * "Progress".
     */
    public function up(): void
    {
        foreach (['base_words', 'fixed_expressions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                // 2, 3, and 4 = mastered. 1 is valid but unreachable while tier I is off.
                $table->unsignedTinyInteger('frammenti_tier')->default(2);
                $table->unsignedInteger('frammenti_correct_streak')->default(0);
                $table->unsignedInteger('frammenti_wrong_streak')->default(0);
                // Null = ready.
                $table->timestamp('frammenti_rest_until')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['base_words', 'fixed_expressions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['frammenti_tier', 'frammenti_correct_streak', 'frammenti_wrong_streak', 'frammenti_rest_until']);
            });
        }
    }
};
