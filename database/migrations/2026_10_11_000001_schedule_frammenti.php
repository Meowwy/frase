<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frammenti becomes a daily drill on a word-level schedule (docs/frammenti.md): rest is
     * replaced by an interval and a due date, the tiers shift down by one (I fill the gap,
     * II translate, III your sentence), and the daily session, the per-language intake
     * setting and the user's timezone get their columns.
     *
     * Existing progress is mapped so the learner keeps practising the same words: an item
     * never answered stays new, any other item below old tier III is due today at tier I,
     * and old tier III or mastered is due today at tier II.
     */
    public function up(): void
    {
        $today = now()->toDateString();

        foreach (['base_words', 'fixed_expressions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedSmallInteger('frammenti_interval')->default(0);
                // Null = new: never introduced.
                $table->date('frammenti_due_on')->nullable();
            });

            $neverAnswered = fn ($query) => $query->whereNull('frammenti_rest_until')
                ->where('frammenti_correct_streak', 0)->where('frammenti_wrong_streak', 0);

            DB::table($table)->where('frammenti_tier', '<=', 2)->whereNot($neverAnswered)
                ->update(['frammenti_tier' => 1, 'frammenti_interval' => 1, 'frammenti_due_on' => $today]);
            DB::table($table)->where('frammenti_tier', '<=', 2)->where($neverAnswered)
                ->update(['frammenti_tier' => 1]);
            DB::table($table)->where('frammenti_tier', '>=', 3)
                ->update(['frammenti_tier' => 2, 'frammenti_interval' => 1, 'frammenti_due_on' => $today, 'frammenti_wrong_streak' => 0]);

            Schema::table($table, function (Blueprint $table) {
                $table->renameColumn('frammenti_wrong_streak', 'frammenti_miss_streak');
                $table->unsignedTinyInteger('frammenti_tier')->default(1)->change();
                $table->dropColumn(['frammenti_rest_until', 'frammenti_correct_streak']);
            });
        }

        Schema::create('frammenti_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            // The user's local date.
            $table->date('date');
            $table->unsignedTinyInteger('planned_batches');
            $table->unsignedTinyInteger('batches_done')->default(0);
            // New items introduced today.
            $table->unsignedSmallInteger('introduced')->default(0);
            // "type:id" => repetitions still owed today.
            $table->json('owed');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'language_id', 'date']);
        });

        Schema::table('language_user', function (Blueprint $table) {
            $table->unsignedTinyInteger('frammenti_new_per_day')->default(12);
        });

        Schema::table('users', function (Blueprint $table) {
            // IANA name, detected in the browser.
            $table->string('timezone')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('timezone'));
        Schema::table('language_user', fn (Blueprint $table) => $table->dropColumn('frammenti_new_per_day'));
        Schema::drop('frammenti_sessions');

        foreach (['base_words', 'fixed_expressions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['frammenti_interval', 'frammenti_due_on']);
                $table->renameColumn('frammenti_miss_streak', 'frammenti_wrong_streak');
                $table->unsignedTinyInteger('frammenti_tier')->default(2)->change();
                $table->unsignedInteger('frammenti_correct_streak')->default(0);
                $table->timestamp('frammenti_rest_until')->nullable();
            });
        }
    }
};
