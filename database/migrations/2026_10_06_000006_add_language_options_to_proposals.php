<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The language picker: the learner's languages a captured Term could belong to, when
     * CALL 1 found it in more than one. See docs/cards.md "Staging".
     */
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->json('language_options')->nullable()->after('language_id');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('language_options');
        });
    }
};
