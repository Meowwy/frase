<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Frammenti item at level 8 (MAX_LEVEL) is never due again, so it carries no due date.
 * See docs/frammenti.md "The schedule".
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['base_words', 'fixed_expressions'] as $table) {
            DB::table($table)->where('frammenti_level', '>=', 8)->update(['frammenti_due_on' => null]);
        }
    }

    public function down(): void
    {
        // The old due dates are gone; level 8 items stay undated.
    }
};
