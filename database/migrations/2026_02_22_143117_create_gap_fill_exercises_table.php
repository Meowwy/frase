<?php

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
        Schema::create('gap_fill_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordbox_id')->constrained()->cascadeOnDelete();
            $table->string('theme_preference')->nullable();
            $table->longText('text_with_gaps')->nullable(); // AI output
            $table->json('correct_answers')->nullable();   // Structured answers
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gap_fill_exercises');
    }
};
