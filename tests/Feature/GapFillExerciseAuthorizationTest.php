<?php

namespace Tests\Feature;

use App\Jobs\GenerateGapFillJob;
use App\Models\GapFillExercise;
use App\Models\User;
use App\Models\Wordbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GapFillExerciseAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_other_user_cannot_trigger_generation_for_someone_elses_wordbox(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $wordbox = Wordbox::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->get(route('gapfill.generate', $wordbox->id));

        $response->assertStatus(403);
        Queue::assertNotPushed(GenerateGapFillJob::class);
    }

    public function test_other_user_cannot_view_someone_elses_exercise(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $wordbox = Wordbox::factory()->create(['user_id' => $owner->id]);
        $exercise = GapFillExercise::create(['wordbox_id' => $wordbox->id, 'status' => 'completed']);

        $response = $this->actingAs($other)->get(route('gap-fill.show', $exercise));

        $response->assertStatus(403);
    }

    public function test_other_user_cannot_poll_status_of_someone_elses_exercise(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $wordbox = Wordbox::factory()->create(['user_id' => $owner->id]);
        $exercise = GapFillExercise::create(['wordbox_id' => $wordbox->id, 'status' => 'processing']);

        $response = $this->actingAs($other)->get(route('gap-fill.status', $exercise));

        $response->assertStatus(403);
    }

    public function test_other_user_cannot_delete_someone_elses_exercise(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $wordbox = Wordbox::factory()->create(['user_id' => $owner->id]);
        $exercise = GapFillExercise::create(['wordbox_id' => $wordbox->id, 'status' => 'completed']);

        $response = $this->actingAs($other)->delete(route('gap-fill.destroy', $exercise));

        $response->assertStatus(403);
        $this->assertDatabaseHas('gap_fill_exercises', ['id' => $exercise->id]);
    }

    public function test_owner_can_view_and_delete_their_own_exercise(): void
    {
        $owner = User::factory()->create();
        $wordbox = Wordbox::factory()->create(['user_id' => $owner->id]);
        $exercise = GapFillExercise::create(['wordbox_id' => $wordbox->id, 'status' => 'completed']);

        $this->actingAs($owner)->get(route('gap-fill.show', $exercise))->assertStatus(200);

        $response = $this->actingAs($owner)->delete(route('gap-fill.destroy', $exercise));
        $response->assertRedirect(route('wordbox.show', $wordbox->id));
        $this->assertDatabaseMissing('gap_fill_exercises', ['id' => $exercise->id]);
    }
}
