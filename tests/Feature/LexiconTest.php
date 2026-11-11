<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\IgnoredWord;
use App\Models\Language;
use App\Models\LexiconEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The shared lexicon search and what is built on it: adding a word to the base by hand.
 */
class LexiconTest extends TestCase
{
    use RefreshDatabase;

    private function learner(): array
    {
        $user = User::factory()->create();
        $swedish = Language::firstOrCreate(['code' => 'sv'], ['name' => 'Swedish', 'native_name' => 'Svenska', 'flag' => '🇸🇪']);
        $english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'flag' => '🇬🇧']);
        $user->languages()->attach($swedish->id, ['users_level' => 'B1']);
        $user->update(['native_language_id' => $english->id]);

        LexiconEntry::insert([
            ['language_code' => 'sv', 'lemma' => 'hus', 'part_of_speech' => 'noun', 'gender' => 'neuter', 'dictionary_form' => null, 'paradigm' => 'nn_6n_hus'],
            ['language_code' => 'sv', 'lemma' => 'husera', 'part_of_speech' => 'verb', 'gender' => null, 'dictionary_form' => 'huser|a -ar', 'paradigm' => 'vb_1a_laga'],
            ['language_code' => 'sv', 'lemma' => 'plan', 'part_of_speech' => 'noun', 'gender' => 'neuter', 'dictionary_form' => null, 'paradigm' => 'nn_6n_hus'],
            ['language_code' => 'sv', 'lemma' => 'plan', 'part_of_speech' => 'noun', 'gender' => 'common', 'dictionary_form' => null, 'paradigm' => 'nn_2u_bil'],
        ]);

        return [$user, $swedish, $english];
    }

    private function fakeMeanings(array $translations): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'meanings' => array_map(fn ($t) => ['translation' => $t], $translations),
        ])]]]])]);
    }

    public function test_the_search_lists_one_row_per_lemma_and_part_of_speech_with_every_field(): void
    {
        [$user, $swedish] = $this->learner();

        $results = $this->actingAs($user)->getJson('/lexicon/search?language_id='.$swedish->id.'&q=hus')->assertOk()->json('results');

        $this->assertSame(['hus', 'husera'], array_column($results, 'lemma'));
        $this->assertSame('ett hus', $results[0]['display_form']);
        $this->assertSame('nn_6n_hus', $results[0]['entries'][0]['paradigm']);

        // A homograph is one row with both entries, its article left open.
        $plan = $this->actingAs($user)->getJson('/lexicon/search?language_id='.$swedish->id.'&q=plan')->json('results');
        $this->assertCount(1, $plan);
        $this->assertCount(2, $plan[0]['entries']);
        $this->assertSame('plan', $plan[0]['display_form']);
    }

    public function test_the_base_offers_adding_a_word_only_for_a_language_with_a_lexicon(): void
    {
        [$user, $swedish, $english] = $this->learner();
        $user->languages()->attach($english->id, ['users_level' => 'B1']);

        $this->actingAs($user)->get('/base?language_id='.$swedish->id)->assertSee('Add word manually');
        $this->actingAs($user)->get('/base?language_id='.$english->id)->assertDontSee('Add word manually');
    }

    public function test_adding_a_word_creates_it_with_its_meanings_and_lexicon_grammar_and_unignores_it(): void
    {
        [$user, $swedish] = $this->learner();
        IgnoredWord::create(['user_id' => $user->id, 'language_id' => $swedish->id, 'lemma' => 'hus', 'part_of_speech' => 'noun']);
        $this->fakeMeanings(['house', 'building']);

        $this->actingAs($user)->postJson('/base/words', ['language_id' => $swedish->id, 'lemma' => 'hus', 'part_of_speech' => 'noun'])
            ->assertCreated();

        $word = BaseWord::sole();
        $this->assertSame(['gender' => 'neuter'], $word->grammar_attributes);
        $this->assertSame('house', $word->translation);
        $this->assertSame(['house', 'building'], $word->senses()->pluck('translation')->all());
        $this->assertSame(0, IgnoredWord::count());

        // A second time it is already there, and no call is made.
        Http::fake();
        $this->actingAs($user)->postJson('/base/words', ['language_id' => $swedish->id, 'lemma' => 'hus', 'part_of_speech' => 'noun'])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_the_ignored_words_page_lists_removes_and_adds_from_the_lexicon(): void
    {
        [$user, $swedish, $english] = $this->learner();
        $ignored = IgnoredWord::create(['user_id' => $user->id, 'language_id' => $swedish->id, 'lemma' => 'hus', 'part_of_speech' => 'noun']);

        $this->actingAs($user)->get('/base/ignored?language_id='.$swedish->id)
            ->assertOk()
            ->assertSee('ett hus')
            ->assertSee('Search the dictionary to ignore');

        $this->actingAs($user)->postJson('/base/ignored', ['language_id' => $swedish->id, 'lemma' => 'husera', 'part_of_speech' => 'verb'])->assertNoContent();
        $this->actingAs($user)->postJson('/base/ignored', ['language_id' => $swedish->id, 'lemma' => 'husera', 'part_of_speech' => 'verb'])->assertNoContent();
        $this->actingAs($user)->postJson('/base/ignored', ['language_id' => $swedish->id, 'lemma' => 'bil', 'part_of_speech' => 'noun'])->assertStatus(422);
        $this->assertSame(2, IgnoredWord::count());

        [$other] = $this->learner();
        $this->actingAs($other)->deleteJson('/base/ignored/'.$ignored->id)->assertNotFound();
        $this->actingAs($user)->deleteJson('/base/ignored/'.$ignored->id)->assertNoContent();
        $this->assertModelMissing($ignored);

        // A language with no lexicon lists its words but offers no search.
        $user->languages()->attach($english->id, ['users_level' => 'B1']);
        $this->actingAs($user)->get('/base/ignored?language_id='.$english->id)->assertOk()->assertDontSee('Search the dictionary to ignore');
    }

    public function test_a_word_the_lexicon_lacks_is_refused(): void
    {
        [$user, $swedish] = $this->learner();
        Http::fake();

        $this->actingAs($user)->postJson('/base/words', ['language_id' => $swedish->id, 'lemma' => 'bil', 'part_of_speech' => 'noun'])
            ->assertStatus(422);

        $this->assertSame(0, BaseWord::count());
    }
}
