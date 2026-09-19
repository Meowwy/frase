<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The previous/next arrows on the card detail page: they walk the user's cards in the
 * card's own language, newest first — the order /cards lists them in.
 */
class CardNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_to_the_neighbouring_cards(): void
    {
        $user = User::factory()->create();
        $language = Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'native_name' => 'Deutsch', 'flag' => '🇩🇪']);

        $older = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id]);
        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id]);
        $newer = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id]);

        $response = $this->actingAs($user)->get("/cards/{$card->id}");

        $response->assertStatus(200);

        // "Previous" is the row above on /cards (the newer card), "next" the row below.
        $response->assertSeeInOrder([
            'href="/cards/'.$newer->id.'"', 'previous card',
            'href="/cards/'.$older->id.'"', 'next card',
        ], false);
    }

    public function test_the_ends_of_the_list_have_no_neighbour_link(): void
    {
        $user = User::factory()->create();
        $language = Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'native_name' => 'Deutsch', 'flag' => '🇩🇪']);

        $first = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id]);
        $last = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id]);

        $this->actingAs($user)->get("/cards/{$last->id}")
            ->assertDontSee('href="/cards/'.$last->id.'"', false)
            ->assertSee('href="/cards/'.$first->id.'"', false);

        $this->actingAs($user)->get("/cards/{$first->id}")
            ->assertDontSee('href="/cards/'.$first->id.'"', false)
            ->assertSee('href="/cards/'.$last->id.'"', false);
    }

    public function test_it_does_not_cross_into_another_language_or_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $german = Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'native_name' => 'Deutsch', 'flag' => '🇩🇪']);
        $french = Language::firstOrCreate(['code' => 'fr'], ['name' => 'French', 'native_name' => 'Français', 'flag' => '🇫🇷']);

        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $german->id]);
        $otherLanguage = Card::factory()->create(['user_id' => $user->id, 'language_id' => $french->id]);
        $otherUser = Card::factory()->create(['user_id' => $other->id, 'language_id' => $german->id]);

        $response = $this->actingAs($user)->get("/cards/{$card->id}");

        $response->assertDontSee('href="/cards/'.$otherLanguage->id.'"', false);
        $response->assertDontSee('href="/cards/'.$otherUser->id.'"', false);
    }
}
