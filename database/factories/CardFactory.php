<?php

namespace Database\Factories;

use App\Models\User;
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
            'user_id' => fake()->randomElement([1,2]),
            'phrase' => fake()->word,
            'translation' => fake()->word,
            'example_sentence' => fake()->sentence,
            'question' => fake()->sentence,
            'definition' => fake()->sentence,
            'next_study_at' => now(),
            'level' => 1
        ];
    }
}
