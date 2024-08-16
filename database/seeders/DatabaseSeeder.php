<?php

namespace Database\Seeders;

use App\Models\Card;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Database\Factories\CardFactory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'username' => 'Rosťa',
            'email' => 'rosta@gmail.com',
            'password' => '123456',
        ]);

        User::factory()->create([
            'username' => 'Kája',
            'email' => 'kaja@gmail.com',
            'password' => '123456',
        ]);

        Card::factory(50)->create();
    }
}
