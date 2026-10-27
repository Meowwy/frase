<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The article drill on /frammenti (docs/frammenti.md "Article drill"): a noun's own
     * schedule for recalling its article, independent of its Frammenti schedule.
     */
    public function up(): void
    {
        Schema::table('base_words', function (Blueprint $table) {
            // 0–3: 3 is learned and never due again.
            $table->unsignedTinyInteger('article_level')->default(0);
            $table->date('article_due_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('base_words', function (Blueprint $table) {
            $table->dropColumn(['article_level', 'article_due_on']);
        });
    }
};
