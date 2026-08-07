<?php

namespace Database\Seeders;

use App\Models\ChallengeScene;
use Illuminate\Database\Seeder;

class ChallengeSceneSeeder extends Seeder
{
    /**
     * A curated set of everyday situations the Conversation Challenge Game draws from.
     * The list is static; re-running is idempotent via updateOrCreate on `name`.
     *
     * @var array<int, array{0:string,1:string}> [name, description]
     */
    public const SCENES = [
        ['At a restaurant', 'The learner is a customer at a restaurant. A waiter takes their order, answers questions about the menu, and handles requests during the meal.'],
        ['Checking into a hotel', 'The learner arrives at a hotel reception. The receptionist checks them in, asks about their booking, and explains the room and facilities.'],
        ['Asking for directions', 'The learner is lost in a city and stops a friendly passer-by to find the way to a specific place, learning about turns, distances and landmarks.'],
        ['Shopping for clothes', 'The learner is in a clothing shop. A shop assistant helps them find sizes, colours and styles, and handles trying on and paying.'],
        ['At the doctor', 'The learner visits a doctor about a minor health problem. The doctor asks about symptoms, gives advice and explains what to do next.'],
        ['Ordering coffee in a café', 'The learner is at the counter of a busy café. The barista takes their drink and food order and asks about sizes, milk and extras.'],
        ['A job interview', 'The learner attends an interview for a job they want. The interviewer asks about their experience, strengths and availability.'],
        ['Meeting a new colleague', 'The learner meets a new colleague on their first day. They introduce themselves, make small talk and arrange to work together.'],
        ['At the train station', 'The learner needs to travel by train. A ticket clerk helps them buy a ticket, choose a time and find the right platform.'],
        ['Renting an apartment', 'The learner views an apartment they might rent. The landlord shows them around and answers questions about price, rules and the neighbourhood.'],
    ];

    public function run(): void
    {
        foreach (self::SCENES as [$name, $description]) {
            ChallengeScene::updateOrCreate(
                ['name' => $name],
                ['description' => $description],
            );
        }
    }
}
