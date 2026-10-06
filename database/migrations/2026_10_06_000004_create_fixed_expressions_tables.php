<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The expression base: fixed expressions (multi-word units learnt as a whole), stored
     * per learner alongside the vocabulary base, linked to cards and staged on proposals.
     * See docs/cards.md "The expression base".
     */
    public function up(): void
    {
        Schema::create('fixed_expressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();

            // Canonical form, `…` marking a gap. NOCASE so the unique key and every lookup
            // treat "På grund av" and "på grund av" as one expression.
            $table->string('form')->collation('NOCASE');

            $table->string('translation');
            $table->timestamp('last_recalled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'language_id', 'form']);
        });

        Schema::create('card_fixed_expression', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_expression_id')->constrained()->cascadeOnDelete();

            // How this card's Term actually spells it ("tycker om" for "tycka om").
            $table->string('surface_form');

            $table->unique(['card_id', 'fixed_expression_id']);
        });

        Schema::create('proposal_fixed_expressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->string('form');
            $table->string('surface_form');
            $table->string('translation');

            // Per proposal: a struck fixed expression is not remembered as known.
            $table->boolean('struck')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_fixed_expressions');
        Schema::dropIfExists('card_fixed_expression');
        Schema::dropIfExists('fixed_expressions');
    }
};
