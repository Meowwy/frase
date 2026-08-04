<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redesign card content: drop the unused `question` field, add up to three
     * short usage-example phrases and a user-editable note. The `example_sentence`
     * column is intentionally left untouched (it still powers the Sentences mode).
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('question');

            // Up to 3 short, unbracketed usage phrases showing different ways the term is used.
            $table->string('example_1')->nullable()->after('example_sentence');
            $table->string('example_2')->nullable()->after('example_1');
            $table->string('example_3')->nullable()->after('example_2');

            // Free-text note the user can add to a card.
            $table->text('note')->nullable()->after('definition');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['example_1', 'example_2', 'example_3', 'note']);
            $table->string('question');
        });
    }
};
