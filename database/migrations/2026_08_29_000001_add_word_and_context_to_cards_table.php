<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `word` records which single word inside `phrase` the learner originally wanted to
     * learn, so a card built around a phrase still knows what the capture was really
     * about. It always holds the word EXACTLY as `phrase` spells it (phrase
     * "vetting candidates" => word "vetting", not the base form "vet"), which is what
     * lets the card views bold it with a plain substring match.
     *
     * `context` is the learner's own context input, which used to be passed to the AI
     * and thrown away. Keeping it lets a card be regenerated in the sense it was
     * captured in — in particular, a card built from a suggested phrase inherits the
     * context of the single-word card it replaces.
     *
     * Both are nullable: the great majority of cards have neither.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('word')->nullable()->after('phrase');
            $table->string('context')->nullable()->after('definition');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['word', 'context']);
        });
    }
};
