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
        $card = Card::factory()->create(['user_id' => $owner->id, 'term' => 'original']);

        $response = $this->actingAs($other)->post("/cards/{$card->id}", [
            'term' => 'hacked',
            'term_type' => Card::TYPE_LEXICAL,
            'definition' => 'x',
            'translation' => 'x',
        ]);

        $response->assertStatus(403);
        $this->assertSame('original', $card->fresh()->term);
    }

    public function test_owner_can_update_their_card(): void
    {
        $owner = User::factory()->create();
        $card = Card::factory()->create(['user_id' => $owner->id, 'term' => 'original']);

        $response = $this->actingAs($owner)->post("/cards/{$card->id}", [
            'term' => 'updated',
            'term_type' => Card::TYPE_LEXICAL,
            'definition' => 'x',
            'translation' => 'x',
        ]);

        $response->assertRedirect("/cards/{$card->id}");
        $this->assertSame('updated', $card->fresh()->term);
    }

    /**
     * A lexical card's word/phrase shape is re-derived from the Term on every edit, so a
     * word card whose Term grows to several words can't stay a word card — that shape
     * carries an anchor phrase and is expected to link exactly one base word.
     */
    public function test_editing_a_word_cards_term_into_several_words_makes_it_a_phrase(): void
    {
        $owner = User::factory()->create();
        $card = Card::factory()->create([
            'user_id' => $owner->id,
            'term' => 'hus',
            'card_shape' => Card::SHAPE_WORD,
            'anchor' => 'ett stort [hus]',
            'anchor_translation' => 'a big house',
        ]);

        $this->actingAs($owner)->post("/cards/{$card->id}", [
            'term' => 'ett stort hus',
            'term_type' => Card::TYPE_LEXICAL,
            'definition' => 'x',
            'translation' => 'x',
        ]);

        $card->refresh();
        $this->assertSame(Card::SHAPE_PHRASE, $card->card_shape);
        $this->assertSame(Card::TYPE_LEXICAL, $card->termType());
        // Only a word card may carry an anchor phrase.
        $this->assertNull($card->anchor);
        $this->assertNull($card->anchor_translation);
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
