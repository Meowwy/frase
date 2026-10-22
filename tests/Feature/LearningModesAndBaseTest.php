<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\Card;
use App\Models\FixedExpression;
use App\Models\KnownWord;
use App\Models\Language;
use App\Models\Learning;
use App\Models\Theme;
use App\Models\User;
use App\Models\Wordbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The whole-Term learning modes, what clearing a card stamps, the vocabulary-base page and
 * its synonym links.
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

    private function entry(User $user, Language $language, string $lemma): BaseWord
    {
        return BaseWord::create(['user_id' => $user->id, 'language_id' => $language->id, 'lemma' => $lemma, 'part_of_speech' => 'adjective', 'translation' => 'en: '.$lemma]);
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
        // Fixed expressions share the list, with nothing setting them apart.
        FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'tycka om', 'translation' => 'to like']);

        $rows = fn (array $query) => $this->actingAs($user)
            ->getJson('/base?'.http_build_query($query), ['X-Requested-With' => 'XMLHttpRequest'])
            ->json('rows');

        $this->assertStringContainsString('ett hus', $rows(['search' => 'hu']));
        $this->assertStringNotContainsString('en: stor', $rows(['search' => 'hu']));
        $this->assertStringNotContainsString('tycka om', $rows(['search' => 'hu']));
        $this->assertStringContainsString('en: stor', $rows(['translation' => 'en: st']));
        $this->assertStringContainsString('tycka om', $rows(['search' => 'ty']));
        $this->assertStringNotContainsString('ett hus', $rows(['part_of_speech' => 'adjective']));
        $this->assertStringContainsString('tycka om', $rows(['part_of_speech' => 'expression']));
        $this->assertStringNotContainsString('en: stor', $rows(['part_of_speech' => 'expression']));
        $this->assertStringContainsString('Nothing matches.', $rows(['search' => 'xyz']));

        // The filter offers only the parts of speech the base has, expressions included.
        $this->actingAs($user)->get('/base')
            ->assertSee('<option value="adjective"', false)
            ->assertSee('<option value="expression"', false)
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

        $response = $this->actingAs($user)->get('/base?selected=word:'.$last->id);

        $response->assertSee('ord55');
        $response->assertDontSee('ord01');
        $this->assertSame(1, substr_count($response->getContent(), 'js-selected"'));
    }

    /**
     * Deleting only flags the entry: it leaves the base page and the card's chips, but the
     * card link stays in the pivot for when it comes back.
     */
    public function test_deleting_a_word_hides_it_and_keeps_its_card_links(): void
    {
        [$user, $language] = $this->learner();
        $card = $this->cardWithWords($user, $language, 'ett stort hus', [['hus', 'noun', ['gender' => 'neuter']]]);
        $word = $user->baseWords()->sole();

        $this->actingAs($user)->deleteJson("/base/word/{$word->id}")->assertNoContent();

        $this->actingAs($user)->get('/base')->assertDontSee('ett hus');
        $this->assertNull(BaseWord::find($word->id));
        $this->assertTrue($word->fresh()->deleted);
        $this->assertTrue($card->fresh()->baseWords->isEmpty());
        $this->assertDatabaseHas('card_base_word', ['card_id' => $card->id, 'base_word_id' => $word->id]);
        $this->assertSame(0, KnownWord::count());
    }

    public function test_deleting_a_word_as_known_records_it_as_known(): void
    {
        [$user, $language] = $this->learner();
        $this->cardWithWords($user, $language, 'ett stort hus', [['hus', 'noun', ['gender' => 'neuter']]]);
        $word = $user->baseWords()->sole();

        $this->actingAs($user)->deleteJson("/base/word/{$word->id}", ['known' => 1])->assertNoContent();

        $this->assertDatabaseHas('known_words', ['user_id' => $user->id, 'language_id' => $language->id, 'lemma' => 'hus', 'part_of_speech' => 'noun']);
        $this->assertSame(0, $user->baseWords()->count());
    }

    public function test_an_expression_is_deleted_but_never_marked_known_and_only_by_its_owner(): void
    {
        [$user, $language] = $this->learner();
        $expression = FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'tycka om', 'translation' => 'to like']);

        $this->actingAs(User::factory()->create())->deleteJson("/base/expression/{$expression->id}")->assertNotFound();
        $this->actingAs($user)->deleteJson("/base/expression/{$expression->id}", ['known' => 1])->assertNoContent();

        $this->assertSame(0, $user->fixedExpressions()->count());
        $this->assertSame(0, KnownWord::count());
    }

    /**
     * A synonym link is two mirrored rows, between base words and fixed expressions alike;
     * linking a pair twice changes nothing.
     */
    public function test_two_entries_are_linked_as_synonyms_both_ways_and_unlinked(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->entry($user, $language, 'glad');
        $expression = FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'på gott humör', 'translation' => 'in a good mood']);
        $pair = ['first' => 'word:'.$word->id, 'second' => 'expression:'.$expression->id];

        $this->actingAs($user)->postJson('/base/synonyms', $pair)->assertNoContent();
        $this->actingAs($user)->postJson('/base/synonyms', $pair)->assertNoContent();

        $this->assertDatabaseCount('entry_synonyms', 2);
        $this->assertDatabaseHas('entry_synonyms', ['user_id' => $user->id, 'language_id' => $language->id, 'kind' => 'word', 'entry_id' => $word->id, 'other_kind' => 'expression', 'other_id' => $expression->id]);
        $this->assertDatabaseHas('entry_synonyms', ['kind' => 'expression', 'entry_id' => $expression->id, 'other_kind' => 'word', 'other_id' => $word->id]);
        $this->assertTrue($word->synonyms()->sole()->is($expression));
        $this->assertTrue($expression->synonyms()->sole()->is($word));

        // Either side unlinks it.
        $this->actingAs($user)->deleteJson('/base/synonyms', ['first' => $pair['second'], 'second' => $pair['first']])->assertNoContent();

        $this->assertDatabaseCount('entry_synonyms', 0);
        $this->assertTrue($word->synonyms()->isEmpty());
    }

    public function test_synonyms_are_two_of_the_learners_own_entries_in_one_language(): void
    {
        [$user, $language] = $this->learner();
        $english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'flag' => '🇬🇧']);
        $user->languages()->attach($english->id);
        $word = $this->entry($user, $language, 'glad');
        $other = $this->entry($user, $english, 'glad');
        $theirs = $this->entry(User::factory()->create(), $language, 'lycklig');
        $link = fn (BaseWord $second) => $this->actingAs($user)->postJson('/base/synonyms', ['first' => 'word:'.$word->id, 'second' => 'word:'.$second->id]);

        $link($other)->assertStatus(422)->assertJson(['message' => 'Synonyms have to be in the same language.']);
        $link($word)->assertStatus(422);
        $link($theirs)->assertNotFound();
        $this->assertDatabaseCount('entry_synonyms', 0);
    }

    /**
     * A deleted entry drops out of its synonyms' lists but keeps its link, so it comes back
     * linked.
     */
    public function test_a_deleted_synonym_is_hidden_and_returns_with_its_entry(): void
    {
        [$user, $language] = $this->learner();
        $glad = $this->entry($user, $language, 'glad');
        $lycklig = $this->entry($user, $language, 'lycklig');
        $glad->linkSynonym($lycklig);

        $this->actingAs($user)->deleteJson("/base/word/{$lycklig->id}")->assertNoContent();

        $this->assertTrue($glad->synonyms()->isEmpty());
        $this->assertDatabaseCount('entry_synonyms', 2);

        BaseWord::resolve($user, $language, 'lycklig', 'adjective', null, 'en: lycklig');

        $this->assertTrue($glad->synonyms()->sole()->is($lycklig));
    }

    public function test_the_vocabulary_base_page_has_a_checkbox_per_row_and_lists_each_rows_synonyms(): void
    {
        [$user, $language] = $this->learner();
        $glad = $this->entry($user, $language, 'glad');
        $lycklig = $this->entry($user, $language, 'lycklig');
        $this->entry($user, $language, 'ledsen');
        $glad->linkSynonym($lycklig);

        $html = $this->actingAs($user)->get('/base')->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'class="js-base-checkbox'));
        $this->assertStringContainsString('data-first="word:'.$glad->id.'" data-second="word:'.$lycklig->id.'"', $html);
        $this->assertStringContainsString('data-first="word:'.$lycklig->id.'" data-second="word:'.$glad->id.'"', $html);
        $this->assertSame(2, substr_count($html, 'class="js-synonym-unlink'));
        $this->assertSame(2, substr_count($html, 'Synonyms</p>'));
    }
}
