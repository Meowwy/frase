<?php

use App\Models\Card;
use App\Models\Wordbox;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wordbox_card', function (Blueprint $table) {
            $table->foreignIdFor(Card::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Wordbox::class)->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
