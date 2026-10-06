<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sense picker: the senses CALL 1 offers for an ambiguous lone word captured
     * without a Context. See docs/cards.md "Staging".
     */
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->json('senses')->nullable()->after('term');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('senses');
        });
    }
};
