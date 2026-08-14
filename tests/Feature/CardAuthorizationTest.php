<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_their_card(): void
    {
        $owner = User::factory()->create();
        $card = Card::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)->get("/cards/{$card->id}");

        $response->assertStatus(200);
    }

    public function test_other_user_cannot_view_someone_elses_card(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = Card::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->get("/cards/{$card->id}");

        $response->assertStatus(403);
    }

    public function test_other_user_cannot_view_edit_form(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = Card::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->get("/cards/edit/{$card->id}");

        $response->assertStatus(403);
    }

    public function test_other_user_cannot_update_someone_elses_card(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = Card::factory()->create(['user_id' => $owner->id, 'phrase' => 'original']);

        $response = $this->actingAs($other)->post("/cards/{$card->id}", [
            'phrase' => 'hacked',
            'term_type' => Card::TYPE_LEXICAL,
            'definition' => 'x',
            'translation' => 'x',
        ]);

        $response->assertStatus(403);
        $this->assertSame('original', $card->fresh()->phrase);
    }

    public function test_owner_can_update_their_card(): void
    {
        $owner = User::factory()->create();
        $card = Card::factory()->create(['user_id' => $owner->id, 'phrase' => 'original']);

        $response = $this->actingAs($owner)->post("/cards/{$card->id}", [
            'phrase' => 'updated',
            'term_type' => Card::TYPE_LEXICAL,
            'definition' => 'x',
            'translation' => 'x',
        ]);

        $response->assertRedirect("/cards/{$card->id}");
        $this->assertSame('updated', $card->fresh()->phrase);
    }

    public function test_other_user_cannot_view_synonyms_of_someone_elses_card(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = Card::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->get("/cards/{$card->id}/synonyms");

        $response->assertStatus(403);
    }
}
