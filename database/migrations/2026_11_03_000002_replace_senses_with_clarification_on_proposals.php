<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sense picker becomes a clarification loop: CALL 1 asks one question at a time
     * about anything the Term and Context leave open. See docs/cards.md "Staging".
     */
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('senses');
            $table->json('clarification')->nullable()->after('term');
            $table->unsignedTinyInteger('clarify_rounds')->default(0)->after('clarification');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['clarification', 'clarify_rounds']);
            $table->json('senses')->nullable()->after('term');
        });
    }
};
