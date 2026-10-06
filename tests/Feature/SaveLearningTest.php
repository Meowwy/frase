<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\Card;
use App\Models\FixedExpression;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaveLearningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_let_a_user_mutate_another_users_card(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $card = Card::factory()->create(['user_id' => $owner->id, 'level' => 1, 'next_study_at' => now()]);
        $card->refresh();
        $originalLevel = $card->level;
        $originalNextStudyAt = $card->next_study_at;

        $response = $this->actingAs($attacker)->post('/saveLearning', [
            'results' => json_encode([['id' => $card->id, 'result' => 1]]),
        ]);

        $response->assertRedirect('/completeLearning');
        $card->refresh();
        $this->assertSame($originalLevel, $card->level);
        $this->assertSame($originalNextStudyAt, $card->next_study_at);
    }

    public function test_it_updates_the_owners_own_card_on_a_correct_review(): void
    {
        $owner = User::factory()->create();
        $card = Card::factory()->create(['user_id' => $owner->id, 'level' => 1, 'next_study_at' => now()]);

        $response = $this->actingAs($owner)->post('/saveLearning', [
            'results' => json_encode([['id' => $card->id, 'result' => 1]]),
        ]);

        $response->assertRedirect('/completeLearning');
        $card->refresh();
        $this->assertSame(2, $card->level);
    }

    public function test_a_correct_review_stamps_the_cards_base_words_and_fixed_expressions(): void
    {
        $owner = User::factory()->create();
        $language = Language::firstOrCreate(['code' => 'sv'], ['name' => 'Swedish', 'native_name' => 'Svenska', 'flag' => '🇸🇪']);
        $card = Card::factory()->create(['user_id' => $owner->id, 'language_id' => $language->id, 'level' => 1, 'next_study_at' => now()]);
        $baseWord = BaseWord::create(['user_id' => $owner->id, 'language_id' => $card->language_id, 'lemma' => 'tycka', 'part_of_speech' => 'verb', 'translation' => 'to think']);
        $expression = FixedExpression::create(['user_id' => $owner->id, 'language_id' => $card->language_id, 'form' => 'tycka om', 'translation' => 'to like']);
        $card->baseWords()->attach($baseWord->id, ['surface_form' => 'tycker']);
        $card->fixedExpressions()->attach($expression->id, ['surface_form' => 'tycker om']);

        $this->actingAs($owner)->post('/saveLearning', [
            'results' => json_encode([['id' => $card->id, 'result' => 1]]),
        ]);

        $this->assertNotNull($baseWord->fresh()->last_recalled_at);
        $this->assertNotNull($expression->fresh()->last_recalled_at);
    }

    public function test_it_resets_the_level_on_a_wrong_review(): void
    {
        $owner = User::factory()->create();
        $card = Card::factory()->create(['user_id' => $owner->id, 'level' => 3, 'next_study_at' => now()]);

        $response = $this->actingAs($owner)->post('/saveLearning', [
            'results' => json_encode([['id' => $card->id, 'result' => 0]]),
        ]);

        $response->assertRedirect('/completeLearning');
        $card->refresh();
        $this->assertSame(1, $card->level);
    }

    public function test_it_skips_a_missing_card_id_without_erroring(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post('/saveLearning', [
            'results' => json_encode([['id' => 999999, 'result' => 1]]),
        ]);

        $response->assertRedirect('/completeLearning');
    }
}
