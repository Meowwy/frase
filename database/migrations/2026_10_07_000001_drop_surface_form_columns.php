<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Term's own spelling of each base word and fixed expression was carried for a
 * hide-a-word review mode that was never built; nothing read it.
 */
return new class extends Migration
{
    private const TABLES = ['proposal_base_words', 'card_base_word', 'proposal_fixed_expressions', 'card_fixed_expression'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('surface_form');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('surface_form')->default('');
            });
        }
    }
};
