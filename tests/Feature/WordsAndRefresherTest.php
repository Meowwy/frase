<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\Card;
use App\Models\Language;
use App\Models\Learning;
use App\Models\User;
use App\Models\Wordbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two word-level review surfaces: Words mode (scheduled, inside a session, and the one
 * word-level path that can clear a card) and Refresher (unscheduled, stamps last recall
 * and nothing else).
 */
class WordsAndRefresherTest extends TestCase
{
    use RefreshDatabase;

    private function learner(): array
    {
        $user = User::factory()->create();
        $language = Language::firstOrCreate(['code' => 'sv'], ['name' => 'Swedish', 'native_name' => 'Svenska', 'flag' => '🇸🇪']);
        $user->languages()->attach($language->id, ['users_level' => 'B1']);
        $user->update(['active_language_id' => $language->id]);

        return [$user, $language];
    }

    /**
     * @param  array<int, array{0:string, 1:string, 2?:array}>  $words
     */
    private function cardWithWords(User $user, Language $language, string $term, array $words, array $attributes = []): Card
    {
        $card = Card::factory()->create([
            'user_id' => $user->id,
            'language_id' => $language->id,
            'term' => $term,
            'next_study_at' => now(),
        ] + $attributes);

        foreach ($words as [$lemma, $partOfSpeech, $grammar]) {
            $baseWord = BaseWord::create([
                'user_id' => $user->id,
                'language_id' => $language->id,
                'lemma' => $lemma,
                'part_of_speech' => $partOfSpeech,
                'grammar_attributes' => $grammar,
                'translation' => 'en: '.$lemma,
            ]);

            $card->baseWords()->attach($baseWord->id);
        }

        return $card;
    }

    private function deck(string $html): array
    {
        preg_match('/let cards = (\[.*?\]);/s', $html, $matches);

        return json_decode($matches[1], true);
    }

    public function test_words_mode_deals_base_words_in_their_display_form_with_the_part_of_speech(): void
    {
        [$user, $language] = $this->learner();
        $this->cardWithWords($user, $language, 'ett stort hus', [
            ['stor', 'adjective', null],
            ['hus', 'noun', ['gender' => 'neuter']],
        ]);

        $deck = $this->deck($this->actingAs($user)
            ->withSession(['learning_filter' => ['language_id' => $language->id, 'wordbox' => 'all', 'scope' => 'due']])
            ->get('/startLearningSet/words')->getContent());

        $this->assertCount(2, $deck);

        $house = collect($deck)->firstWhere('part_of_speech', 'noun');
        // The lemma in its display form, never the inflected form the Term uses.
        $this->assertSame('ett hus', $house['back']);
        $this->assertSame('en: hus', $house['front']);
        $this->assertSame('', $house['hint']);
    }

    /**
     * The cap is counted in words, and a card only enters if ALL of its words fit — a card
     * that contributed a partial word set could never clear.
     */
    public function test_words_mode_admits_a_card_only_when_all_of_its_words_fit_the_cap(): void
    {
        [$user, $language] = $this->learner();

        // Four cards of five words each: 15 words is three whole cards, and the fourth is
        // skipped rather than half-dealt.
        foreach (range(1, 4) as $n) {
            $this->cardWithWords($user, $language, 'big'.$n, array_map(
                fn ($i) => ['w'.$n.$i, 'noun', null],
                range(1, 5)
            ));
        }

        $deck = $this->deck($this->actingAs($user)
            ->withSession(['learning_filter' => ['language_id' => $language->id, 'wordbox' => 'all', 'scope' => 'due']])
            ->get('/startLearningSet/words')->getContent());

        $this->assertCount(Learning::WORDS_PER_SESSION, $deck);

        $perCard = collect($deck)->flatMap(fn ($entry) => $entry['card_ids'])->countBy();
        $this->assertCount(3, $perCard);
        $this->assertSame([5], $perCard->values()->unique()->all());
    }

    /**
     * A word linked to two due cards is the normal case, not an edge one — that is what the
     * base is for. It is asked once, and the answer counts towards both.
     */
    public function test_words_mode_deals_a_shared_base_word_once_and_credits_both_cards(): void
    {
        [$user, $language] = $this->learner();
        $first = $this->cardWithWords($user, $language, 'ett stort hus', [['stor', 'adjective', null], ['hus', 'noun', null]]);
        $shared = $user->baseWords()->where('lemma', 'hus')->sole();

        $second = Card::factory()->create([
            'user_id' => $user->id,
            'language_id' => $language->id,
            'term' => 'ett gult hus',
            'next_study_at' => now(),
        ]);
        $second->baseWords()->attach($shared->id);

        $deck = $this->deck($this->actingAs($user)
            ->withSession(['learning_filter' => ['language_id' => $language->id, 'wordbox' => 'all', 'scope' => 'due']])
            ->get('/startLearningSet/words')->getContent());

        // Three links across two cards, but only two distinct words to answer.
        $this->assertCount(2, $deck);

        $entry = collect($deck)->firstWhere('id', $shared->id);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $entry['card_ids']);
    }

    public function test_words_mode_skips_a_card_with_no_base_words(): void
    {
        [$user, $language] = $this->learner();
        // A card whose words were all filtered at capture can only clear elsewhere.
        $this->cardWithWords($user, $language, 'I would rather not', []);
        $this->cardWithWords($user, $language, 'hus', [['hus', 'noun', null]]);

        $deck = $this->deck($this->actingAs($user)
            ->withSession(['learning_filter' => ['language_id' => $language->id, 'wordbox' => 'all', 'scope' => 'due']])
            ->get('/startLearningSet/words')->getContent());

        $this->assertCount(1, $deck);
        $this->assertSame('hus', $deck[0]['back']);
    }

    /**
     * There is one kind of card, so every whole-Term mode serves every card — a whole
     * utterance with no base words included.
     */
    public function test_definitions_mode_serves_a_whole_utterance_card(): void
    {
        [$user, $language] = $this->learner();
        $this->cardWithWords($user, $language, 'I would rather not', [], ['definition' => 'Said to politely refuse an offer.']);

        $deck = $this->deck($this->actingAs($user)
            ->withSession(['learning_filter' => ['language_id' => $language->id, 'wordbox' => 'all', 'scope' => 'due']])
            ->get('/startLearningSet/definitions')->getContent());

        $this->assertCount(1, $deck);
        $this->assertSame('Said to politely refuse an offer.', $deck[0]['front']);
        $this->assertSame('I would rather not', $deck[0]['back']);
    }

    public function test_translation_mode_asks_for_the_term_from_its_translation(): void
    {
        [$user, $language] = $this->learner();
        $this->cardWithWords($user, $language, 'kostade', [['kosta', 'verb', null]], ['translation' => 'cost (past)']);

        $deck = $this->deck($this->actingAs($user)
            ->withSession(['learning_filter' => ['language_id' => $language->id, 'wordbox' => 'all', 'scope' => 'due']])
            ->get('/startLearningSet/translation')->getContent());

        $this->assertCount(1, $deck);
        $this->assertSame('cost (past)', $deck[0]['front']);
        $this->assertSame('kostade', $deck[0]['back']);
        $this->assertSame('', $deck[0]['hint']);
    }

    /**
     * A native-language card has no translation, so its definition stands in on the front.
     */
    public function test_translation_mode_shows_a_native_cards_definition_on_the_front(): void
    {
        [$user, $language] = $this->learner();
        $this->cardWithWords($user, $language, 'hus', [], ['translation' => '', 'definition' => 'En byggnad att bo i.']);

        $deck = $this->deck($this->actingAs($user)
            ->withSession(['learning_filter' => ['language_id' => $language->id, 'wordbox' => 'all', 'scope' => 'due']])
            ->get('/startLearningSet/translation')->getContent());

        $this->assertSame('En byggnad att bo i.', $deck[0]['front']);
        $this->assertSame('hus', $deck[0]['back']);
    }

    public function test_translation_mode_is_accepted_by_the_legacy_wordbox_entry_point(): void
    {
        [$user, $language] = $this->learner();
        $card = $this->cardWithWords($user, $language, 'hus', [], ['translation' => 'house']);
        $wordbox = Wordbox::factory()->create(['user_id' => $user->id, 'language_id' => $language->id]);
        $wordbox->cards()->attach($card->id);

        $deck = $this->deck($this->actingAs($user)->get('/startLearning/'.$wordbox->id.'/translation')->getContent());

        $this->assertSame('house', $deck[0]['front']);
    }

    /**
     * Producing the Term is producing all of its words, so a cleared card stamps every base
     * word linked to it.
     */
    public function test_clearing_a_card_stamps_every_base_word_linked_to_it(): void
    {
        [$user, $language] = $this->learner();
        $card = $this->cardWithWords($user, $language, 'ett stort hus', [['stor', 'adjective', null], ['hus', 'noun', null]]);

        $this->actingAs($user)->post('/saveLearning', [
            'results' => json_encode([['id' => $card->id, 'result' => 1]]),
        ]);

        $this->assertSame(2, $card->fresh()->level);
        $this->assertCount(2, $user->baseWords()->whereNotNull('last_recalled_at')->get());
    }

    public function test_a_wrong_card_stamps_nothing(): void
    {
        [$user, $language] = $this->learner();
        $card = $this->cardWithWords($user, $language, 'hus', [['hus', 'noun', null]], ['level' => 4]);

        $this->actingAs($user)->post('/saveLearning', [
            'results' => json_encode([['id' => $card->id, 'result' => 0]]),
        ]);

        $this->assertSame(1, $card->fresh()->level);
        $this->assertNull($user->baseWords()->sole()->last_recalled_at);
    }

    /**
     * The per-word array stamps last recall on its own; the card array beside it is what
     * decides whether the card cleared.
     */
    public function test_the_per_word_array_stamps_only_correct_words(): void
    {
        [$user, $language] = $this->learner();
        $this->cardWithWords($user, $language, 'ett stort hus', [['stor', 'adjective', null], ['hus', 'noun', null]]);
        $right = $user->baseWords()->where('lemma', 'hus')->sole();
        $wrong = $user->baseWords()->where('lemma', 'stor')->sole();

        $this->actingAs($user)->post('/saveLearning', [
            'results' => json_encode([]),
            'words' => json_encode([
                ['id' => $right->id, 'result' => 1],
                ['id' => $wrong->id, 'result' => 0],
            ]),
        ]);

        $this->assertNotNull($right->fresh()->last_recalled_at);
        $this->assertNull($wrong->fresh()->last_recalled_at);
    }

    public function test_a_learner_cannot_stamp_someone_elses_base_word(): void
    {
        [$owner, $language] = $this->learner();
        $attacker = User::factory()->create();
        $this->cardWithWords($owner, $language, 'hus', [['hus', 'noun', null]]);
        $word = $owner->baseWords()->sole();

        $this->actingAs($attacker)->post('/saveLearning', [
            'results' => json_encode([]),
            'words' => json_encode([['id' => $word->id, 'result' => 1]]),
        ]);

        $this->assertNull($word->fresh()->last_recalled_at);
    }

    public function test_refresher_orders_the_whole_base_by_staleness(): void
    {
        [$user, $language] = $this->learner();
        $this->cardWithWords($user, $language, 'ett stort hus', [
            ['stor', 'adjective', null],
            ['hus', 'noun', ['gender' => 'neuter']],
            ['gammal', 'adjective', null],
        ]);

        $user->baseWords()->where('lemma', 'stor')->update(['last_recalled_at' => now()->subDay()]);
        $user->baseWords()->where('lemma', 'hus')->update(['last_recalled_at' => now()->subYear()]);
        // 'gammal' was never recalled, which is as stale as it gets.

        $deck = $this->deck($this->actingAs($user)->get('/refresher')->getContent());

        $this->assertSame(['gammal', 'ett hus', 'stor'], array_column($deck, 'back'));
    }

    public function test_the_vocabulary_base_page_shows_the_display_form_and_coverage(): void
    {
        [$user, $language] = $this->learner();
        $this->cardWithWords($user, $language, 'ett stort hus', [['hus', 'noun', ['gender' => 'neuter']]]);

        $response = $this->actingAs($user)->get('/base');

        $response->assertStatus(200);
        $response->assertSee('ett hus');
        // Coverage: the row carries its cards' Terms for the side panel.
        $response->assertSee('ett stort hus');
    }

    public function test_the_vocabulary_base_live_search_filters_by_word_translation_and_part_of_speech(): void
    {
        [$user, $language] = $this->learner();
        $this->cardWithWords($user, $language, 'ett stort hus', [
            ['hus', 'noun', ['gender' => 'neuter']],
            ['stor', 'adjective', []],
        ]);

        $rows = fn (array $query) => $this->actingAs($user)
            ->getJson('/base?'.http_build_query($query), ['X-Requested-With' => 'XMLHttpRequest'])
            ->json('rows');

        $this->assertStringContainsString('ett hus', $rows(['search' => 'hu']));
        $this->assertStringNotContainsString('adjective', $rows(['search' => 'hu']));
        $this->assertStringContainsString('adjective', $rows(['translation' => 'en: st']));
        $this->assertStringNotContainsString('ett hus', $rows(['part_of_speech' => 'adjective']));
        $this->assertStringContainsString('No words match.', $rows(['search' => 'xyz']));

        // The filter offers only the parts of speech the base has.
        $this->actingAs($user)->get('/base')
            ->assertSee('<option value="adjective"', false)
            ->assertDontSee('<option value="verb"', false);
    }

    /**
     * A card's word chip links to /base with that word selected: the page opens on whichever
     * page of the table the word falls on, with its row picked.
     */
    public function test_the_vocabulary_base_opens_on_the_page_of_the_selected_word(): void
    {
        [$user, $language] = $this->learner();
        foreach (range(1, 55) as $i) {
            $last = BaseWord::create([
                'user_id' => $user->id, 'language_id' => $language->id,
                'lemma' => sprintf('ord%02d', $i), 'part_of_speech' => 'noun', 'translation' => 'word '.$i,
            ]);
        }

        $response = $this->actingAs($user)->get('/base?selected='.$last->id);

        $response->assertSee('ord55');
        $response->assertDontSee('ord01');
        $this->assertSame(1, substr_count($response->getContent(), 'js-selected"'));
    }
}
