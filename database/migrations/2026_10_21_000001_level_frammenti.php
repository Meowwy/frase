<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frammenti moves to a level-based, Anki-style schedule (docs/frammenti.md): one level
     * per item replaces tier, interval and miss streak, and sessions lose the repetitions
     * they owed, the daily intake and the one-per-day rule.
     *
     * Old progress is not mapped: every item starts again at level 0, due today.
     */
    public function up(): void
    {
        foreach (['base_words', 'fixed_expressions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                // 0–8: the tier and the next due date follow from it.
                $table->unsignedTinyInteger('frammenti_level')->default(0);
                $table->dropColumn(['frammenti_tier', 'frammenti_interval', 'frammenti_miss_streak']);
            });
            DB::table($table)->update(['frammenti_due_on' => null]);
        }

        Schema::table('frammenti_sessions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'language_id', 'date']);
            $table->dropColumn(['introduced', 'owed']);
        });

        Schema::table('language_user', fn (Blueprint $table) => $table->dropColumn('frammenti_new_per_day'));
    }

    /**
     * Back to the old schedule with every item new and one session per day, the latest kept.
     */
    public function down(): void
    {
        Schema::table('language_user', function (Blueprint $table) {
            $table->unsignedTinyInteger('frammenti_new_per_day')->default(12);
        });

        DB::table('frammenti_sessions')->whereNotIn('id', DB::table('frammenti_sessions')
            ->selectRaw('max(id)')->groupBy('user_id', 'language_id', 'date'))->delete();
        Schema::table('frammenti_sessions', function (Blueprint $table) {
            $table->unsignedSmallInteger('introduced')->default(0);
            $table->json('owed')->default('[]');
            $table->unique(['user_id', 'language_id', 'date']);
        });

        foreach (['base_words', 'fixed_expressions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('frammenti_level');
                $table->unsignedTinyInteger('frammenti_tier')->default(1);
                $table->unsignedSmallInteger('frammenti_interval')->default(0);
                $table->unsignedInteger('frammenti_miss_streak')->default(0);
            });
            DB::table($table)->update(['frammenti_due_on' => null]);
        }
    }
};
