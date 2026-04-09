<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synonyms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('synonym_card_id')->constrained('cards')->cascadeOnDelete();
            $table->float('similarity_score');
            $table->timestamps();

            $table->unique(['card_id', 'synonym_card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synonyms');
    }
};
