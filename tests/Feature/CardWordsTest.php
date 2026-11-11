<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\Card;
use App\Models\FixedExpression;
use App\Models\IgnoredWord;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The card edit page's words: striking a linked word or expression, and linking a word of
 * the base in a chosen meaning.
 */
class CardWordsTest extends TestCase
{
    use RefreshDatabase;

    private function setUpCard(): array
    {
        $user = User::factory()->create();
        $language = Language::firstOrCreate(['code' => 'sv'], ['name' => 'Swedish', 'native_name' => 'Svenska', 'flag' => '🇸🇪']);
        $user->languages()->attach($language->id, ['users_level' => 'B1']);
        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'term' => 'vi går till banken']);

        return [$user, $language, $card];
    }

    private function word(User $user, Language $language, string $lemma, string $partOfSpeech, array $meanings = []): BaseWord
    {
        $word = BaseWord::create(['user_id' => $user->id, 'language_id' => $language->id, 'lemma' => $lemma, 'part_of_speech' => $partOfSpeech, 'translation' => $meanings[0] ?? '']);

        foreach ($meanings as $meaning) {
            $word->addSense($meaning);
        }

        return $word;
    }

    public function test_striking_a_word_unlinks_it_and_ignores_it(): void
    {
        [$user, $language, $card] = $this->setUpCard();
        $ga = $this->word($user, $language, 'gå', 'verb', ['go']);
        $card->baseWords()->attach($ga->id);
        $expression = FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'gå till', 'translation' => 'go to']);
        $card->fixedExpressions()->attach($expression->id);

        $this->actingAs($user)->get("/cards/edit/{$card->id}")->assertOk()->assertSee('gå till')->assertSee('Add a word from your base');

        $this->actingAs($user)->deleteJson("/cards/{$card->id}/words/{$ga->id}")->assertNoContent();
        $this->actingAs($user)->deleteJson("/cards/{$card->id}/expressions/{$expression->id}")->assertNoContent();

        $this->assertCount(0, $card->baseWords()->get());
        $this->assertCount(0, $card->fixedExpressions()->get());
        $this->assertDatabaseHas('ignored_words', ['user_id' => $user->id, 'lemma' => 'gå', 'part_of_speech' => 'verb']);
        $this->assertModelExists($ga);
    }

    public function test_a_base_word_is_linked_in_the_chosen_meaning(): void
    {
        [$user, $language, $card] = $this->setUpCard();
        $bank = $this->word($user, $language, 'bank', 'noun', ['bank', 'riverbank']);
        $riverbank = $bank->senses()->where('translation', 'riverbank')->sole();

        $results = $this->actingAs($user)->getJson("/cards/{$card->id}/word-search?q=ban")->assertOk()->json('results');
        $this->assertSame([$bank->id], array_column($results, 'id'));
        $this->assertCount(2, $results[0]['senses']);

        $this->actingAs($user)->postJson("/cards/{$card->id}/words", ['base_word_id' => $bank->id, 'base_word_sense_id' => $riverbank->id])->assertNoContent();

        $this->assertSame($riverbank->id, (int) $card->baseWords()->sole()->pivot->base_word_sense_id);
        $this->assertSame([], $this->actingAs($user)->getJson("/cards/{$card->id}/word-search?q=ban")->json('results'));
    }

    public function test_another_learners_card_or_word_is_refused(): void
    {
        [$user, $language, $card] = $this->setUpCard();
        [$other, , $foreignCard] = $this->setUpCard();
        $word = $this->word($other, $language, 'hus', 'noun', ['house']);

        $this->actingAs($user)->postJson("/cards/{$card->id}/words", ['base_word_id' => $word->id])->assertNotFound();
        $this->actingAs($user)->getJson("/cards/{$foreignCard->id}/word-search?q=hus")->assertForbidden();
        $this->assertSame(0, IgnoredWord::count());
    }
}
