<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staging: capture writes a proposal and returns immediately, CALL 1 resolves it on
     * the queue, and nothing reaches the vocabulary until the learner approves it. See
     * docs/cards.md "Staging".
     */
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Nullable until CALL 1 resolves it — language detection is CALL 1's job.
            $table->foreignId('language_id')->nullable()->constrained()->nullOnDelete();
            $table->string('raw_input');
            $table->string('context')->nullable();
            $table->string('term')->nullable();
            $table->string('card_shape')->nullable();
            // Editable in staging; its translation is CALL 2's job, at approval.
            $table->string('anchor')->nullable();
            $table->string('source')->default('web');
            // Same async shape as gap_fill_exercises, so the staging skeleton polls the
            // same way: pending | processing | completed | failed.
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('proposal_base_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->string('lemma');
            $table->string('part_of_speech');
            $table->json('grammar_attributes')->nullable();
            $table->string('surface_form');
            $table->string('translation');
            // Struck = this word never becomes a base word for the approved card.
            $table->boolean('struck')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_base_words');
        Schema::dropIfExists('proposals');
    }
};
