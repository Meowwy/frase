<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\Card;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A base word keeps one row per lemma + part of speech but can hold several meanings, and
 * /base splits the word's translations and cards by meaning.
 */
class BaseWordMeaningsTest extends TestCase
{
    use RefreshDatabase;

    private function bankWithTwoMeanings(): array
    {
        $user = User::factory()->create();
        $english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'flag' => '🇬🇧']);
        $user->languages()->attach($english->id, ['users_level' => 'B1']);

        $bank = BaseWord::create([
            'user_id' => $user->id, 'language_id' => $english->id, 'lemma' => 'bank',
            'part_of_speech' => 'noun', 'translation' => 'banka',
        ]);
        $money = $bank->addSense('banka');
        $river = $bank->addSense('břeh');

        foreach ([['I went to the bank', $money], ['We sat on the bank', $river]] as [$term, $sense]) {
            $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $english->id, 'term' => $term]);
            $card->baseWords()->attach($bank->id, ['base_word_sense_id' => $sense->id]);
        }

        return [$user, $bank, $money, $river];
    }

    public function test_the_base_panel_groups_terms_under_their_meaning(): void
    {
        [$user] = $this->bankWithTwoMeanings();

        $html = $this->actingAs($user)->get('/base')->assertOk()->getContent();

        $this->assertStringContainsString('banka · břeh', $html);
        $this->assertMatchesRegularExpression('/banka<\/p>\s*<a[^>]*>I went to the bank.*břeh<\/p>\s*<a[^>]*>We sat on the bank/s', $html);
    }

    public function test_each_meanings_translation_can_be_edited_and_the_first_stays_the_words_own(): void
    {
        [$user, $bank, $money, $river] = $this->bankWithTwoMeanings();

        $this->actingAs($user)->putJson("/base/word/{$bank->id}", [
            'part_of_speech' => 'noun',
            'senses' => [$money->id => 'bankovní ústav', $river->id => 'břeh řeky'],
        ])->assertNoContent();

        $this->assertSame(['bankovní ústav', 'břeh řeky'], $bank->senses()->pluck('translation')->all());
        $this->assertSame('bankovní ústav', $bank->fresh()->translation);
    }

    public function test_adding_a_meaning_twice_keeps_one(): void
    {
        [, $bank] = $this->bankWithTwoMeanings();

        $bank->addSense('břeh');

        $this->assertCount(2, $bank->senses);
    }
}
