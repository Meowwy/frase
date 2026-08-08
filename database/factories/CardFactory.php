<?php

namespace Database\Factories;

use App\Models\Card;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Card>
 */
class CardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fake()->randomElement([1, 2]),
            'phrase' => fake()->word,
            'term_type' => Card::TYPE_LEXICAL,
            'translation' => fake()->word,
            'example_sentence' => fake()->sentence,
            'example_1' => fake()->words(2, true),
            'example_2' => fake()->words(2, true),
            'example_3' => fake()->words(2, true),
            'definition' => fake()->sentence,
            'note' => null,
            'next_study_at' => now(),
            'level' => 1,
        ];
    }
}
