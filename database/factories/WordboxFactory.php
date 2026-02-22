<?php

namespace Database\Factories;

use App\Models\User;
/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Wordbox>
 */
use Illuminate\Database\Eloquent\Factories\Factory;

class WordboxFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'description' => $this->faker->sentence(),
            'user_id' => User::factory(),
            'exam_text' => '',
        ];
    }
}
