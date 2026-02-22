<?php

use App\Jobs\GenerateGapFillJob;
use App\Models\GapFillExercise;
use App\Models\User;
use App\Models\Wordbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GapFillExerciseTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_trigger_gap_fill_generation(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $wordbox = Wordbox::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('gapfill.generate', $wordbox->id).'?theme_preference=Travel');

        $response->assertStatus(302);
        $this->assertDatabaseHas('gap_fill_exercises', [
            'wordbox_id' => $wordbox->id,
            'theme_preference' => 'Travel',
            'status' => 'pending',
        ]);

        Queue::assertPushed(GenerateGapFillJob::class);
    }

    public function test_user_can_view_processing_page(): void
    {
        $user = User::factory()->create();
        $wordbox = Wordbox::factory()->create(['user_id' => $user->id]);
        $exercise = GapFillExercise::create([
            'wordbox_id' => $wordbox->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('gap-fill.show', $exercise));

        $response->assertStatus(200);
        $response->assertViewIs('gap-fill.processing');
    }

    public function test_user_can_view_completed_exercise(): void
    {
        $user = User::factory()->create();
        $wordbox = Wordbox::factory()->create(['user_id' => $user->id]);
        $exercise = GapFillExercise::create([
            'wordbox_id' => $wordbox->id,
            'status' => 'completed',
            'text_with_gaps' => 'Hello [1]',
            'correct_answers' => ['1' => 'world'],
        ]);

        $response = $this->actingAs($user)->get(route('gap-fill.show', $exercise));

        $response->assertStatus(200);
        $response->assertViewIs('gap-fill.show');
        $response->assertSee('Hello');
    }

    public function test_status_endpoint_returns_json(): void
    {
        $user = User::factory()->create();
        $wordbox = Wordbox::factory()->create(['user_id' => $user->id]);
        $exercise = GapFillExercise::create([
            'wordbox_id' => $wordbox->id,
            'status' => 'processing',
        ]);

        $response = $this->actingAs($user)->get(route('gap-fill.status', $exercise));

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'processing',
            'url' => route('gap-fill.show', $exercise),
        ]);
    }
}
