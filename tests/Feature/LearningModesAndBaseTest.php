<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\Card;
use App\Models\Language;
use App\Models\Learning;
use App\Models\Theme;
use App\Models\User;
use App\Models\Wordbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The whole-Term learning modes, what clearing a card stamps, and the vocabulary-base page.
 */
class LearningModesAndBaseTest extends TestCase
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
     * A theme filter is matched among the learner's own themes only — another learner's
     * theme of the same name serves nothing, and doesn't crash the session.
     */
    public function test_the_legacy_theme_filter_serves_only_the_learners_own_theme(): void
    {
        [$user, $language] = $this->learner();
        $theme = Theme::create(['user_id' => $user->id, 'language_id' => $language->id, 'name' => 'Home']);
        $this->cardWithWords($user, $language, 'hus', [], ['translation' => 'house', 'theme_id' => $theme->id]);
        $this->cardWithWords($user, $language, 'bil', [], ['translation' => 'car']);

        $deck = $this->deck($this->actingAs($user)->withSession(['learning_filter' => 'Home'])
            ->get('/startLearning/0/translation')->getContent());
        $this->assertSame(['house'], array_column($deck, 'front'));

        [$other] = $this->learner();
        $this->assertSame([], $this->deck($this->actingAs($other)->withSession(['learning_filter' => 'Home'])
            ->get('/startLearning/0/translation')->getContent()));
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
