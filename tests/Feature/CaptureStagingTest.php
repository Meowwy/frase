<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeProposalJob;
use App\Models\BaseWord;
use App\Models\Card;
use App\Models\FixedExpression;
use App\Models\KnownWord;
use App\Models\Language;
use App\Models\LexiconEntry;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Capture → staging → approve: what capture writes, what CALL 1 fills in, and what
 * approving actually puts into the vocabulary and the vocabulary base.
 */
class CaptureStagingTest extends TestCase
{
    use RefreshDatabase;

    private function learner(string $level = 'B1'): array
    {
        $user = User::factory()->create();
        $swedish = Language::firstOrCreate(['code' => 'sv'], ['name' => 'Swedish', 'native_name' => 'Svenska', 'flag' => '🇸🇪']);
        $english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'flag' => '🇬🇧']);
        $user->languages()->attach($swedish->id, ['users_level' => $level]);
        $user->update(['native_language_id' => $english->id]);

        return [$user, $swedish];
    }

    /**
     * Queue every JSON body the OpenAI chat endpoint should answer with, in call order.
     * The sequence throws once exhausted, so an unexpected extra call fails loudly.
     */
    private function fakeOpenAi(array ...$bodies): void
    {
        $sequence = Http::sequence();

        foreach ($bodies as $body) {
            $sequence->push(['choices' => [['message' => ['content' => json_encode($body)]]]]);
        }

        Http::fake(['api.openai.com/v1/chat/completions' => $sequence]);
    }

    private function analysis(array $overrides = []): array
    {
        return array_replace([
            'language' => 'Swedish',
            'term' => 'hur mycket kostar det',
            'base_words' => [
                ['lemma' => 'hur', 'part_of_speech' => 'adverb'],
                ['lemma' => 'mycket', 'part_of_speech' => 'adverb'],
                ['lemma' => 'kosta', 'part_of_speech' => 'verb'],
                ['lemma' => 'det', 'part_of_speech' => 'pronoun'],
            ],
        ], $overrides);
    }

    /**
     * CALL 2's answer. Its `base_words` default to a description of every word the tests
     * propose, which is more than any one approval asks about: the extras are ignored.
     */
    private function cardContent(array $overrides = []): array
    {
        $word = fn (string $lemma, string $pos, string $translation, string $gender = '', string $dictionaryForm = '') => [
            'lemma' => $lemma, 'part_of_speech' => $pos, 'translation' => $translation, 'gender' => $gender, 'dictionary_form' => $dictionaryForm,
        ];

        return array_replace([
            'translation' => 'how much does it cost',
            'base_words' => [
                $word('hur', 'adverb', 'how'),
                $word('mycket', 'adverb', 'much'),
                $word('kosta', 'verb', 'to cost', dictionaryForm: 'kost|a -ar'),
                $word('det', 'pronoun', 'it'),
                ...array_map(fn ($n) => $word('ord'.$n, 'noun', 'word'.$n), range(1, 4)),
            ],
        ], $overrides);
    }

    public function test_capture_writes_a_proposal_and_returns_without_calling_the_ai(): void
    {
        [$user] = $this->learner();
        Queue::fake();
        Http::fake();

        $response = $this->actingAs($user)->postJson('/capture', [
            'capturedWord' => 'hur mycket kostar det',
            'context' => 'in a shop',
        ]);

        $response->assertStatus(200)->assertJson(['staged_count' => 1]);

        $proposal = $user->proposals()->sole();
        $this->assertSame('hur mycket kostar det', $proposal->raw_input);
        $this->assertSame('in a shop', $proposal->context);
        $this->assertSame(Proposal::STATUS_PENDING, $proposal->status);
        $this->assertNull($proposal->language_id);
        $this->assertSame(0, Card::count());

        Queue::assertPushed(AnalyzeProposalJob::class);
        Http::assertNothingSent();
    }

    public function test_call_one_fills_the_proposal_in_and_drops_basic_function_words_at_b1(): void
    {
        [$user] = $this->learner('B1');
        $this->fakeOpenAi($this->analysis());

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'hur mycket kostar det']);

        $proposal = $user->proposals()->sole();
        $this->assertSame(Proposal::STATUS_COMPLETED, $proposal->status);
        $this->assertSame('hur mycket kostar det', $proposal->term);
        $this->assertSame('Swedish', $proposal->language->name);

        // "det" is a pronoun, so the proficiency filter drops it at B1.
        $this->assertSame(['hur', 'kosta', 'mycket'], $proposal->baseWords->pluck('lemma')->sort()->values()->all());
    }

    public function test_an_a2_learner_keeps_the_function_words(): void
    {
        [$user] = $this->learner('A2');
        $this->fakeOpenAi($this->analysis());

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'hur mycket kostar det']);

        $this->assertCount(4, $user->proposals()->sole()->baseWords);
    }

    /**
     * The proficiency filter always applies, even when it empties the tray: a card may end
     * with no base words at all and is still approvable.
     */
    public function test_the_proficiency_filter_always_applies_even_down_to_no_base_words(): void
    {
        [$user] = $this->learner('C1');
        $this->fakeOpenAi($this->analysis([
            'term' => 'in spite of',
            'base_words' => [
                ['lemma' => 'in', 'part_of_speech' => 'preposition'],
                ['lemma' => 'of', 'part_of_speech' => 'preposition'],
            ],
        ]), $this->cardContent());

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'in spite of']);

        $proposal = $user->proposals()->sole();
        $this->assertCount(0, $proposal->baseWords);
        $this->assertTrue($proposal->isApprovable());

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);
        $this->assertCount(0, Card::sole()->baseWords);
    }

    /**
     * The Term is kept exactly as typed, a lone inflected word included: the card shows
     * "kostade" while the base word gets the lemma.
     */
    public function test_an_inflected_lone_word_is_stored_as_typed_while_its_base_word_gets_the_lemma(): void
    {
        [$user] = $this->learner();
        $this->fakeOpenAi($this->analysis([
            'term' => 'kostade',
            'base_words' => [
                ['lemma' => 'kosta', 'part_of_speech' => 'verb'],
            ],
        ]), $this->cardContent(['translation' => 'cost']));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'kostade']);

        $proposal = $user->proposals()->sole();
        $this->assertSame('kostade', $proposal->term);

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $card = Card::sole();
        $this->assertSame('kostade', $card->term);
        $this->assertSame('kosta', $card->baseWords->sole()->lemma);
    }

    /**
     * CALL 1 names the word and nothing more, so a chip reads the bare lemma; the gender
     * arrives with CALL 2 at approval and from then on the entry reads "ett hus".
     */
    public function test_a_swedish_nouns_gender_arrives_with_call_two_at_approval(): void
    {
        [$user] = $this->learner();
        $this->fakeOpenAi($this->analysis([
            'term' => 'hus',
            'base_words' => [
                ['lemma' => 'hus', 'part_of_speech' => 'noun'],
            ],
        ]), $this->cardContent(['base_words' => [
            ['lemma' => 'hus', 'part_of_speech' => 'noun', 'translation' => 'house', 'gender' => 'neuter', 'dictionary_form' => ''],
        ]]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'hus']);

        $proposal = $user->proposals()->sole();
        $word = $proposal->baseWords->sole();
        $this->assertNull($word->grammar_attributes);
        $this->assertSame('hus', $word->displayForm());

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $entry = BaseWord::sole();
        $this->assertSame(['gender' => 'neuter'], $entry->grammar_attributes);
        $this->assertSame('house', $entry->translation);
        $this->assertSame('ett hus', $entry->displayForm());
    }

    /**
     * CALL 1 is only asked for the words, so its schema has no room for anything else; and
     * CALL 2 is asked about the words the base lacks, not the one it already holds.
     */
    public function test_call_one_names_the_words_and_call_two_describes_only_the_new_ones(): void
    {
        [$user, $language] = $this->learner('A2');
        $this->baseWord($user, $language, 'kosta', 'verb');
        $this->fakeOpenAi($this->analysis(), $this->cardContent());

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'hur mycket kostar det']);
        $proposal = $user->proposals()->sole();
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        [$callOne, $callTwo] = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();

        $words = $callOne['response_format']['json_schema']['schema']['properties']['base_words']['items'];
        $this->assertSame(['lemma', 'part_of_speech'], $words['required']);

        $asked = $callTwo['messages'][1]['content'];
        $this->assertStringContainsString('"hur" (adverb)', $asked);
        $this->assertStringNotContainsString('"kosta"', $asked);
        // Nothing new is a noun or a verb, so there is no grammar left to ask for.
        $this->assertSame(['lemma', 'part_of_speech', 'translation'], $callTwo['response_format']['json_schema']['schema']['properties']['base_words']['items']['required']);
        $this->assertStringNotContainsString('Swedish nouns', $callTwo['messages'][0]['content']);
    }

    /**
     * What the lexicon answers with a single value is not asked of the AI: a lexicon noun and
     * verb need only a translation, and are stored with the dictionary's gender and form.
     */
    public function test_call_two_is_not_asked_what_the_lexicon_already_answers(): void
    {
        [$user] = $this->learner();
        $this->lexicon('hus', 'noun', gender: 'neuter');
        $this->lexicon('komma', 'verb', dictionaryForm: 'komm|a -er');
        $this->fakeOpenAi($this->analysis([
            'term' => 'komma till ett hus',
            'base_words' => [
                ['lemma' => 'hus', 'part_of_speech' => 'noun'],
                ['lemma' => 'komma', 'part_of_speech' => 'verb'],
            ],
        ]), $this->cardContent(['base_words' => [
            ['lemma' => 'hus', 'part_of_speech' => 'noun', 'translation' => 'house'],
            ['lemma' => 'komma', 'part_of_speech' => 'verb', 'translation' => 'to come'],
        ]]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'komma till ett hus']);
        $proposal = $user->proposals()->sole();
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $callTwo = Http::recorded()[1][0]->data();
        $this->assertSame(['lemma', 'part_of_speech', 'translation'], $callTwo['response_format']['json_schema']['schema']['properties']['base_words']['items']['required']);

        $this->assertSame('ett hus', BaseWord::firstWhere('lemma', 'hus')->displayForm());
        $this->assertSame('komm|a -er', BaseWord::firstWhere('lemma', 'komma')->displayForm());
    }

    /**
     * A word the lexicon lacks, and a homograph it can't settle, are still asked; the word it
     * does settle is marked as needing a translation only.
     */
    public function test_call_two_is_asked_only_for_the_words_the_lexicon_cannot_settle(): void
    {
        [$user] = $this->learner();
        $this->lexicon('hus', 'noun', gender: 'neuter');
        $this->lexicon('plan', 'noun', gender: 'neuter');
        $this->lexicon('plan', 'noun', gender: 'common');
        $this->fakeOpenAi($this->analysis([
            'term' => 'hus bil plan',
            'base_words' => [
                ['lemma' => 'hus', 'part_of_speech' => 'noun'],
                ['lemma' => 'bil', 'part_of_speech' => 'noun'],
                ['lemma' => 'plan', 'part_of_speech' => 'noun'],
            ],
        ]), $this->cardContent(['base_words' => [
            ['lemma' => 'hus', 'part_of_speech' => 'noun', 'translation' => 'house', 'gender' => ''],
            ['lemma' => 'bil', 'part_of_speech' => 'noun', 'translation' => 'car', 'gender' => 'common'],
            ['lemma' => 'plan', 'part_of_speech' => 'noun', 'translation' => 'plan', 'gender' => 'common'],
        ]]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'hus bil plan']);
        $proposal = $user->proposals()->sole();
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $callTwo = Http::recorded()[1][0]->data();
        $this->assertSame(['lemma', 'part_of_speech', 'translation', 'gender'], $callTwo['response_format']['json_schema']['schema']['properties']['base_words']['items']['required']);
        $this->assertStringContainsString('"hus" (noun; translation only)', $callTwo['messages'][1]['content']);
        $this->assertStringContainsString('"bil" (noun)', $callTwo['messages'][1]['content']);
        $this->assertStringContainsString('"plan" (noun)', $callTwo['messages'][1]['content']);

        $this->assertSame(['gender' => 'neuter'], BaseWord::firstWhere('lemma', 'hus')->grammar_attributes);
        $this->assertSame(['gender' => 'common'], BaseWord::firstWhere('lemma', 'bil')->grammar_attributes);
        $this->assertSame(['gender' => 'common'], BaseWord::firstWhere('lemma', 'plan')->grammar_attributes);
    }

    /**
     * A new word CALL 2 leaves undescribed would be an entry with nothing to show, so the
     * approval fails and the proposal stays in staging.
     */
    public function test_an_approval_fails_when_call_two_leaves_a_new_word_undescribed(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $proposal = $this->completedProposal($user, $language);

        $this->fakeOpenAi($this->cardContent(['base_words' => []]));
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(500);

        $this->assertSame(0, Card::count());
        $this->assertSame(0, BaseWord::count());
        $this->assertModelExists($proposal);
    }

    /**
     * A native-language card has no translation to write, so with every word already in the
     * base there is nothing left to ask the AI.
     */
    public function test_a_native_language_card_with_no_new_words_makes_no_ai_call(): void
    {
        [$user] = $this->learner();
        $english = Language::firstWhere('code', 'en');
        $user->languages()->attach($english->id, ['users_level' => 'B1']);
        Queue::fake();
        $this->baseWord($user, $english, 'house', 'noun');
        $proposal = $user->proposals()->create(['language_id' => $english->id, 'raw_input' => 'a house', 'term' => 'a house', 'status' => Proposal::STATUS_COMPLETED]);
        $proposal->baseWords()->create(['lemma' => 'house', 'part_of_speech' => 'noun']);
        Http::fake();

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $this->assertSame('', Card::sole()->translation);
        Http::assertNothingSent();
    }

    public function test_approving_writes_the_card_its_links_and_the_base_entries(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $proposal = $this->completedProposal($user, $language);

        $this->fakeOpenAi($this->cardContent());
        $response = $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve");

        $card = Card::sole();
        // Staging stays open, so the answer is what the collapsed row and the badge need.
        $response->assertStatus(200)->assertJson([
            'url' => '/cards/'.$card->id,
            'term' => 'hur mycket kostar det',
            'staged_count' => 0,
        ]);

        $this->assertSame('hur mycket kostar det', $card->term);
        $this->assertSame('how much does it cost', $card->translation);
        $this->assertSame(['hur', 'kosta', 'mycket'], $card->baseWords->pluck('lemma')->sort()->values()->all());

        // The dictionary form is carried onto the base entry, so the verb is shown the way
        // a Swedish learner meets it rather than as a bare infinitive.
        $verb = $card->baseWords->firstWhere('lemma', 'kosta');
        $this->assertSame('kost|a -ar', $verb->dictionary_form);
        $this->assertSame('kost|a -ar', $verb->displayForm());

        // The proposal is gone: staging holds nothing once it has been acted on.
        $this->assertSame(0, Proposal::count());
    }

    /**
     * Striking records the word as known and governs base membership only — the Term is
     * untouched.
     */
    public function test_striking_a_word_records_it_as_known_without_rewriting_the_term(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $proposal = $this->completedProposal($user, $language);
        $struck = $proposal->baseWords->firstWhere('lemma', 'hur');

        $this->actingAs($user)
            ->postJson("/staging/{$proposal->id}/words/{$struck->id}/known", ['known' => 1])
            ->assertNoContent();

        $this->assertDatabaseHas('known_words', [
            'user_id' => $user->id, 'language_id' => $language->id, 'lemma' => 'hur', 'part_of_speech' => 'adverb',
        ]);

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $card = Card::sole();
        $this->assertSame('hur mycket kostar det', $card->term);
        $this->assertSame(['kosta', 'mycket'], $card->baseWords->pluck('lemma')->sort()->values()->all());
        $this->assertSame(0, BaseWord::where('lemma', 'hur')->count());
    }

    /**
     * A known word is matched on lemma + part of speech, so a later capture of any inflected
     * form shows it aside as known, and approving doesn't link it.
     */
    public function test_a_known_word_is_shown_as_known_in_a_later_proposal_and_not_linked(): void
    {
        [$user, $language] = $this->learner();
        KnownWord::create(['user_id' => $user->id, 'language_id' => $language->id, 'lemma' => 'kosta', 'part_of_speech' => 'verb']);
        $this->fakeOpenAi($this->analysis([
            'term' => 'kostade',
            'base_words' => [
                ['lemma' => 'kosta', 'part_of_speech' => 'verb'],
            ],
        ]), $this->cardContent());

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'kostade']);
        $word = $user->proposals()->sole()->baseWords->sole();

        $rows = $this->actingAs($user)->getJson('/staging/list')->json('rows');
        $this->assertStringContainsString('data-word-id="'.$word->id.'" data-known="0"', $rows);
        $this->assertStringNotContainsString('data-known="1"', $rows);

        $this->actingAs($user)->postJson("/staging/{$word->proposal_id}/approve")->assertStatus(200);

        $this->assertCount(0, Card::sole()->baseWords);
        $this->assertSame(0, BaseWord::count());
    }

    public function test_un_knowing_a_word_brings_its_chip_back_as_new(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        KnownWord::create(['user_id' => $user->id, 'language_id' => $language->id, 'lemma' => 'hur', 'part_of_speech' => 'adverb']);
        $proposal = $this->completedProposal($user, $language);
        $word = $proposal->baseWords->firstWhere('lemma', 'hur');

        $this->actingAs($user)
            ->postJson("/staging/{$proposal->id}/words/{$word->id}/known", ['known' => 0])
            ->assertNoContent();

        $this->assertSame(0, KnownWord::count());
        $rows = $this->actingAs($user)->getJson('/staging/list')->json('rows');
        $this->assertStringContainsString('data-word-id="'.$word->id.'" data-known="1"', $rows);
    }

    /**
     * Approval is never gated on how many base words a card has: none, or more than five.
     */
    public function test_a_proposal_with_every_word_known_still_approves(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $proposal = $this->completedProposal($user, $language);

        foreach ($proposal->baseWords as $word) {
            KnownWord::create(['user_id' => $user->id, 'language_id' => $language->id, 'lemma' => $word->lemma, 'part_of_speech' => $word->part_of_speech]);
        }

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $this->assertCount(0, Card::sole()->baseWords);
    }

    public function test_a_proposal_with_more_than_five_base_words_approves(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $proposal = $this->completedProposal($user, $language);

        foreach (range(1, 4) as $n) {
            $proposal->baseWords()->create([
                'lemma' => 'ord'.$n, 'part_of_speech' => 'noun',
            ]);
        }

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $this->assertCount(7, Card::sole()->baseWords);
    }

    /**
     * Two proposals for the same lemma AND part of speech land on one base entry, whichever
     * order they are approved in. A different part of speech is a different entry.
     */
    public function test_the_base_deduplicates_on_lemma_and_part_of_speech(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();

        $existing = BaseWord::create([
            'user_id' => $user->id, 'language_id' => $language->id,
            'lemma' => 'kosta', 'part_of_speech' => 'verb', 'translation' => 'ALREADY THERE',
        ]);
        BaseWord::create([
            'user_id' => $user->id, 'language_id' => $language->id,
            'lemma' => 'mycket', 'part_of_speech' => 'noun', 'translation' => 'a lot (noun)',
        ]);

        $proposal = $this->completedProposal($user, $language);
        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        // 'kosta' the verb was reused, not duplicated, and its translation stands.
        $this->assertSame(1, BaseWord::where('lemma', 'kosta')->count());
        $this->assertSame('ALREADY THERE', $existing->fresh()->translation);
        // 'mycket' the adverb is a different vocabulary item from 'mycket' the noun.
        $this->assertSame(2, BaseWord::where('lemma', 'mycket')->count());
    }

    public function test_a_duplicate_term_blocks_approval_and_names_the_existing_card(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        Http::fake();
        $existing = Card::factory()->create([
            'user_id' => $user->id,
            'language_id' => $language->id,
            // The check is case-insensitive and narrows to the Term alone.
            'term' => 'Hur Mycket Kostar Det',
        ]);
        $proposal = $this->completedProposal($user, $language);

        $this->actingAs($user)
            ->postJson("/staging/{$proposal->id}/approve")
            ->assertStatus(409)
            ->assertJson(['duplicate_card_id' => $existing->id]);

        $this->assertSame(1, Card::count());
        Http::assertNothingSent();
    }

    /**
     * Owning a base word of a Term is a different fact from owning a card for it: the word
     * is shown in the tray with no strike control, never blocks the capture, and is linked.
     */
    public function test_an_already_present_word_is_shown_unstrikeable_and_linked_on_approval(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $existing = BaseWord::create([
            'user_id' => $user->id, 'language_id' => $language->id,
            'lemma' => 'kosta', 'part_of_speech' => 'verb', 'translation' => 'to cost',
        ]);
        $proposal = $this->completedProposal($user, $language);
        $word = $proposal->baseWords->firstWhere('lemma', 'kosta');

        $rows = $this->actingAs($user)->getJson('/staging/list')->json('rows');
        $this->assertStringContainsString('js-in-base', $rows);
        $this->assertStringNotContainsString('data-word-id="'.$word->id.'"', $rows);

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $this->assertTrue(Card::sole()->baseWords->contains($existing));
    }

    /**
     * A deleted word isn't in the base any more, so a new proposal offers it as new, and
     * approving brings the same row back — its old card links and Frammenti progress with it.
     */
    public function test_a_deleted_word_is_proposed_as_new_and_comes_back_on_approval(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $deleted = BaseWord::create([
            'user_id' => $user->id, 'language_id' => $language->id,
            'lemma' => 'kosta', 'part_of_speech' => 'verb', 'translation' => 'to cost', 'frammenti_level' => 6, 'deleted' => true,
        ]);
        $oldCard = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'term' => 'det kostar']);
        $oldCard->baseWords()->attach($deleted->id);
        $proposal = $this->completedProposal($user, $language);
        $word = $proposal->baseWords->firstWhere('lemma', 'kosta');

        $rows = $this->actingAs($user)->getJson('/staging/list')->json('rows');
        $this->assertStringContainsString('data-word-id="'.$word->id.'"', $rows);

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $revived = $deleted->fresh();
        $this->assertFalse($revived->deleted);
        $this->assertSame(6, $revived->frammenti_level);
        $this->assertSame(1, BaseWord::where('lemma', 'kosta')->count());
        $this->assertTrue($oldCard->fresh()->baseWords->contains($revived));
    }

    private function ambiguousAnalysis(): array
    {
        return $this->analysis([
            'term' => 'lag',
            'senses' => [
                ['part_of_speech' => 'noun', 'gloss' => 'a team', 'translation' => 'team'],
                ['part_of_speech' => 'noun', 'gloss' => 'a rule passed by parliament', 'translation' => 'law'],
            ],
            'base_words' => [
                ['lemma' => 'lag', 'part_of_speech' => 'noun'],
            ],
        ]);
    }

    public function test_an_ambiguous_lone_word_offers_senses_that_block_approval(): void
    {
        [$user] = $this->learner();
        $this->fakeOpenAi($this->ambiguousAnalysis());

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'lag']);
        $proposal = $user->proposals()->sole();

        $this->assertCount(2, $proposal->senses);
        $this->assertFalse($proposal->isApprovable());
        $this->assertStringContainsString('data-context="lag (noun): a team"', $this->actingAs($user)->getJson('/staging/list')->json('rows'));

        // The fake has no answer left, so a CALL 2 here would fail the test loudly.
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(422);
        $this->assertSame(0, Card::count());
    }

    /**
     * Picking a sense is just saving it as the Context: CALL 1 runs again, offers no senses,
     * and its words replace the old ones.
     */
    public function test_picking_a_sense_sets_the_context_and_re_runs_call_one(): void
    {
        [$user] = $this->learner();
        $this->fakeOpenAi($this->ambiguousAnalysis(), $this->analysis([
            'term' => 'lag',
            'senses' => [],
            'base_words' => [
                ['lemma' => 'lag', 'part_of_speech' => 'noun'],
            ],
        ]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'lag']);
        $proposal = $user->proposals()->sole();

        $this->actingAs($user)
            ->postJson("/staging/{$proposal->id}/context", ['context' => 'lag (noun): a rule passed by parliament'])
            ->assertStatus(200);

        $proposal->refresh();
        $this->assertSame('lag (noun): a rule passed by parliament', $proposal->context);
        $this->assertNull($proposal->senses);
        $this->assertSame('lag', $proposal->baseWords->sole()->lemma);
        $this->assertTrue($proposal->isApprovable());
    }

    public function test_editing_the_context_of_any_proposal_re_runs_call_one(): void
    {
        [$user, $language] = $this->learner();
        $proposal = $this->completedProposal($user, $language);
        $this->fakeOpenAi($this->analysis([
            'base_words' => [
                ['lemma' => 'kosta', 'part_of_speech' => 'verb'],
            ],
        ]));

        $this->actingAs($user)
            ->postJson("/staging/{$proposal->id}/context", ['context' => 'in a shop'])
            ->assertStatus(200);

        $proposal->refresh();
        $this->assertSame('in a shop', $proposal->context);
        $this->assertSame(Proposal::STATUS_COMPLETED, $proposal->status);
        $this->assertSame(['kosta'], $proposal->baseWords->pluck('lemma')->all());
    }

    public function test_senses_are_ignored_when_a_context_was_given(): void
    {
        [$user] = $this->learner();
        $this->fakeOpenAi($this->ambiguousAnalysis());

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'lag', 'context' => 'fotboll']);

        $this->assertNull($user->proposals()->sole()->senses);
    }

    public function test_a_term_at_home_in_two_languages_asks_for_the_language_first(): void
    {
        [$user, $swedish] = $this->learner();
        $german = Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'native_name' => 'Deutsch', 'flag' => '🇩🇪']);
        $user->languages()->attach($german->id, ['users_level' => 'B1']);
        $this->fakeOpenAi($this->analysis(['term' => 'bad', 'other_languages' => ['German']]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'bad']);
        $proposal = $user->proposals()->sole();

        $this->assertSame([$swedish->id, $german->id], $proposal->language_options);
        $this->assertNull($proposal->language_id);
        $this->assertCount(0, $proposal->baseWords);
        $this->assertFalse($proposal->isApprovable());
        $this->assertStringContainsString('Which language is this?', $this->actingAs($user)->getJson('/staging/list')->json('rows'));
    }

    /**
     * Picking the language pins it, exactly like correcting a wrong detection: CALL 1 runs
     * again offered only that language, so it has nothing left to ask.
     */
    public function test_picking_the_language_pins_it_and_re_runs_call_one(): void
    {
        [$user] = $this->learner();
        $german = Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'native_name' => 'Deutsch', 'flag' => '🇩🇪']);
        $user->languages()->attach($german->id, ['users_level' => 'B1']);
        $this->fakeOpenAi(
            $this->analysis(['term' => 'bad', 'other_languages' => ['German']]),
            $this->analysis(['language' => 'German', 'term' => 'bad', 'base_words' => [
                ['lemma' => 'Bad', 'part_of_speech' => 'noun'],
            ]]),
        );

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'bad']);
        $proposal = $user->proposals()->sole();

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/language", ['language_id' => $german->id])->assertStatus(200);

        $proposal->refresh();
        $this->assertSame($german->id, $proposal->language_id);
        $this->assertNull($proposal->language_options);
        $this->assertSame(['Bad'], $proposal->baseWords->pluck('lemma')->all());
        $this->assertTrue($proposal->isApprovable());
        Http::assertSent(fn ($request) => ! str_contains($request->body(), 'other_languages') && str_contains($request->body(), '"enum":["German"]'));
    }

    public function test_fixed_expressions_are_staged_and_linked_on_approval(): void
    {
        [$user] = $this->learner();
        $this->fakeOpenAi($this->analysis([
            'term' => 'jag tycker om dig',
            'fixed_expressions' => [
                ['form' => 'tycka om', 'translation' => 'to like'],
                // A repeat of the same form, differently cased, is one chip.
                ['form' => 'Tycka om', 'translation' => 'to like'],
            ],
            'base_words' => [],
        ]), $this->cardContent());

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'jag tycker om dig']);
        $proposal = $user->proposals()->sole();

        $this->assertSame(['tycka om'], $proposal->fixedExpressions->pluck('form')->all());
        $this->assertStringContainsString('js-strike-expression', $this->actingAs($user)->getJson('/staging/list')->json('rows'));

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $expression = Card::sole()->fixedExpressions->sole();
        $this->assertSame('tycka om', $expression->form);
        $this->assertSame('to like', $expression->translation);
    }

    public function test_a_struck_fixed_expression_is_not_saved(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $proposal = $this->completedProposal($user, $language);
        $expression = $proposal->fixedExpressions()->create(['form' => 'hur mycket', 'translation' => 'how much']);

        $this->actingAs($user)
            ->postJson("/staging/{$proposal->id}/expressions/{$expression->id}/strike", ['struck' => 1])
            ->assertNoContent();

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $this->assertSame(0, FixedExpression::count());
        $this->assertCount(0, Card::sole()->fixedExpressions);
    }

    /**
     * Deduplicated against the expression base case-insensitively: shown with no
     * strike control, reused (its translation stands) and linked.
     */
    public function test_a_fixed_expression_already_in_the_expression_base_is_shown_unstrikeable_and_reused(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $existing = FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'Hur mycket', 'translation' => 'ALREADY THERE']);
        $proposal = $this->completedProposal($user, $language);
        $proposal->fixedExpressions()->create(['form' => 'hur mycket', 'translation' => 'how much']);

        $rows = $this->actingAs($user)->getJson('/staging/list')->json('rows');
        $this->assertStringContainsString('js-in-base', $rows);
        $this->assertStringNotContainsString('js-strike-expression', $rows);

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $this->assertSame(1, FixedExpression::count());
        $this->assertSame('ALREADY THERE', $existing->fresh()->translation);
        $this->assertTrue(Card::sole()->fixedExpressions->contains($existing));
    }

    public function test_a_failed_analysis_is_offered_a_retry_that_runs_call_one_again(): void
    {
        [$user] = $this->learner();
        $this->fakeOpenAi($this->analysis());
        $proposal = $user->proposals()->create(['raw_input' => 'hur mycket kostar det', 'status' => Proposal::STATUS_FAILED]);

        $rows = $this->actingAs($user)->getJson('/staging/list')->assertJson(['pending' => false])->json('rows');
        $this->assertStringContainsString('js-retry', $rows);

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/retry")->assertStatus(200);

        $this->assertSame(Proposal::STATUS_COMPLETED, $proposal->fresh()->status);
        $this->assertSame('hur mycket kostar det', $proposal->fresh()->term);
    }

    public function test_an_analysis_that_stalls_shows_as_failed_and_stops_the_poll(): void
    {
        [$user] = $this->learner();
        $proposal = $user->proposals()->create(['raw_input' => 'hus', 'status' => Proposal::STATUS_PROCESSING]);

        $this->actingAs($user)->getJson('/staging/list')->assertJson(['pending' => true]);

        $this->travel(Proposal::STALLED_AFTER_MINUTES + 1)->minutes();

        $rows = $this->actingAs($user)->getJson('/staging/list')->assertJson(['pending' => false])->json('rows');
        $this->assertStringContainsString('js-retry', $rows);
        $this->assertTrue($proposal->fresh()->hasFailed());
    }

    public function test_staging_lists_proposals_in_the_order_they_were_captured(): void
    {
        [$user] = $this->learner();
        Queue::fake();

        foreach (['första', 'andra', 'tredje'] as $term) {
            $this->actingAs($user)->postJson('/capture', ['capturedWord' => $term]);
        }

        $this->actingAs($user)->get('/staging')->assertSeeInOrder(['första', 'andra', 'tredje']);
    }

    public function test_discarding_leaves_nothing_behind(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $proposal = $this->completedProposal($user, $language);

        $this->actingAs($user)->deleteJson("/staging/{$proposal->id}")->assertJson(['staged_count' => 0]);

        $this->assertSame(0, Proposal::count());
        $this->assertSame(0, \App\Models\ProposalBaseWord::count());
    }

    public function test_a_learner_cannot_act_on_someone_elses_proposal(): void
    {
        [$owner, $language] = $this->learner();
        $other = User::factory()->create();
        $proposal = $this->completedProposal($owner, $language);

        $this->actingAs($other)->postJson("/staging/{$proposal->id}/approve")->assertStatus(403);
        $this->actingAs($other)->postJson("/staging/{$proposal->id}/context", ['context' => 'stolen'])->assertStatus(403);
        $this->actingAs($other)->deleteJson("/staging/{$proposal->id}")->assertStatus(403);
    }

    /**
     * Swedish verbs are learned in dictionary style, and unlike a noun's gender that string
     * cannot be derived from the lemma — so it is stored. With an empty lexicon (as here)
     * CALL 2 supplies it at approval, so a chip reads the bare "komma" until then and every
     * later screen reads "komm|a -er".
     */
    public function test_a_swedish_verb_is_staged_and_stored_in_dictionary_style(): void
    {
        [$user] = $this->learner();
        $this->fakeOpenAi($this->analysis([
            'term' => 'komma',
            'base_words' => [
                ['lemma' => 'komma', 'part_of_speech' => 'verb'],
            ],
        ]), $this->cardContent(['base_words' => [
            ['lemma' => 'komma', 'part_of_speech' => 'verb', 'translation' => 'to come', 'gender' => '', 'dictionary_form' => 'komm|a -er'],
        ]]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'komma']);

        $proposal = $user->proposals()->sole();
        $this->assertSame('komma', $proposal->baseWords->sole()->displayForm());

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $this->assertSame('komm|a -er', BaseWord::sole()->displayForm());
    }

    private function lexicon(string $lemma, string $partOfSpeech, ?string $gender = null, ?string $dictionaryForm = null): void
    {
        LexiconEntry::create([
            'language_code' => 'sv', 'lemma' => $lemma, 'part_of_speech' => $partOfSpeech,
            'gender' => $gender, 'dictionary_form' => $dictionaryForm, 'paradigm' => 'test',
        ]);
    }

    /**
     * Capture one word and approve it, with CALL 2 answering $baseWord about it. Returns the
     * base entry that was written.
     */
    private function approveWord(User $user, array $baseWord): BaseWord
    {
        $this->fakeOpenAi($this->analysis([
            'term' => $baseWord['lemma'],
            'base_words' => [['lemma' => $baseWord['lemma'], 'part_of_speech' => $baseWord['part_of_speech']]],
        ]), $this->cardContent([
            'base_words' => [array_replace(['translation' => 'x', 'gender' => '', 'dictionary_form' => ''], $baseWord)],
        ]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => $baseWord['lemma']]);
        $proposal = $user->proposals()->sole();
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        return BaseWord::sole();
    }

    /**
     * Where the lexicon knows the word, its gender wins over a wrong CALL 2 answer — and a
     * chip already reads "ett hus", since that needs no AI.
     */
    public function test_the_lexicon_overrides_a_wrong_swedish_noun_gender(): void
    {
        [$user] = $this->learner();
        $this->lexicon('hus', 'noun', gender: 'neuter');

        $word = $this->approveWord($user, ['lemma' => 'hus', 'part_of_speech' => 'noun', 'gender' => 'common']);

        $this->assertSame(['gender' => 'neuter'], $word->grammar_attributes);
        $this->assertSame('ett hus', $word->displayForm());
    }

    /**
     * The lexicon is a free lookup, so a chip reads "ett hus" at capture where it knows the word.
     */
    public function test_a_chip_reads_the_lexicons_gender_at_capture(): void
    {
        [$user] = $this->learner();
        $this->lexicon('hus', 'noun', gender: 'neuter');
        $this->fakeOpenAi($this->analysis(['term' => 'hus', 'base_words' => [['lemma' => 'hus', 'part_of_speech' => 'noun']]]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'hus']);

        $this->assertSame('ett hus', $user->proposals()->sole()->baseWords->sole()->displayForm());
    }

    /**
     * Homographs ("ett plan" / "en plan") leave the sense to CALL 2, but only among the
     * values the dictionary allows — an answer outside them falls back to the first.
     */
    public function test_a_homograph_keeps_the_ai_choice_among_the_lexicon_options(): void
    {
        [$user] = $this->learner();
        $this->lexicon('plan', 'noun', gender: 'neuter');
        $this->lexicon('plan', 'noun', gender: 'common');

        $noun = $this->approveWord($user, ['lemma' => 'plan', 'part_of_speech' => 'noun', 'gender' => 'common']);

        $this->assertSame(['gender' => 'common'], $noun->grammar_attributes);
    }

    /**
     * A chip shows a lexicon value only when it is the one answer: homographs stay bare
     * until CALL 2 has picked a sense.
     */
    public function test_a_homograph_chip_stays_bare_until_call_two_chooses(): void
    {
        [$user] = $this->learner();
        $this->lexicon('plan', 'noun', gender: 'neuter');
        $this->lexicon('plan', 'noun', gender: 'common');
        $this->fakeOpenAi($this->analysis(['term' => 'plan', 'base_words' => [['lemma' => 'plan', 'part_of_speech' => 'noun']]]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'plan']);

        $word = $user->proposals()->sole()->baseWords->sole();
        $this->assertNull($word->grammar_attributes);
        $this->assertSame('plan', $word->displayForm());
    }

    public function test_a_verb_form_outside_the_lexicon_options_falls_back_to_the_lexicon(): void
    {
        [$user] = $this->learner();
        $this->lexicon('komma', 'verb', dictionaryForm: 'komm|a -er');

        $verb = $this->approveWord($user, ['lemma' => 'komma', 'part_of_speech' => 'verb', 'dictionary_form' => 'kom|ma -er']);

        $this->assertSame('komm|a -er', $verb->dictionary_form);
    }

    /**
     * A compound the lexicon doesn't list takes its last element's gender.
     */
    public function test_an_unlisted_compound_takes_its_last_elements_gender(): void
    {
        [$user] = $this->learner();
        $this->lexicon('hus', 'noun', gender: 'neuter');

        $compound = $this->approveWord($user, ['lemma' => 'sommarhus', 'part_of_speech' => 'noun', 'gender' => 'common']);

        $this->assertSame(['gender' => 'neuter'], $compound->grammar_attributes);
    }

    public function test_a_word_the_lexicon_does_not_know_keeps_the_ai_answer(): void
    {
        [$user] = $this->learner();
        $this->lexicon('hus', 'noun', gender: 'neuter');

        $unknown = $this->approveWord($user, ['lemma' => 'bil', 'part_of_speech' => 'noun', 'gender' => 'common']);

        $this->assertSame(['gender' => 'common'], $unknown->grammar_attributes);
    }

    /**
     * The dictionary form is kept only where the detected language's guideline asks for it.
     * The schema's property is as wide for a language without the convention, so the model
     * can offer one for a part of speech — or a language — that writes none.
     */
    public function test_a_dictionary_form_is_dropped_where_the_guideline_asks_for_none(): void
    {
        [$user] = $this->learner();

        $this->fakeOpenAi($this->analysis([
            'term' => 'komma till ett hus',
            'base_words' => [
                ['lemma' => 'hus', 'part_of_speech' => 'noun'],
                ['lemma' => 'komma', 'part_of_speech' => 'verb'],
            ],
        ]), $this->cardContent(['base_words' => [
            // A Swedish noun: Swedish declares a dictionary form for verbs only.
            ['lemma' => 'hus', 'part_of_speech' => 'noun', 'translation' => 'house', 'gender' => 'neuter', 'dictionary_form' => 'hus, -et'],
            ['lemma' => 'komma', 'part_of_speech' => 'verb', 'translation' => 'to come', 'gender' => '', 'dictionary_form' => 'komm|a -er'],
        ]]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'komma till ett hus']);
        $proposal = $user->proposals()->sole();
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $noun = BaseWord::firstWhere('lemma', 'hus');
        $this->assertNull($noun->dictionary_form);
        $this->assertSame('ett hus', $noun->displayForm());
        $this->assertSame('komm|a -er', BaseWord::firstWhere('lemma', 'komma')->dictionary_form);
    }

    /**
     * An English verb gets none either: English declares no dictionary form, so an answer
     * for one is discarded.
     */
    public function test_an_english_verb_is_shown_as_a_bare_lemma(): void
    {
        [$user] = $this->learner();
        $english = Language::firstWhere('code', 'en');
        $user->languages()->attach($english->id, ['users_level' => 'B1']);

        $this->fakeOpenAi($this->analysis([
            'language' => 'English',
            'term' => 'come',
            'base_words' => [
                ['lemma' => 'come', 'part_of_speech' => 'verb'],
            ],
        ]), $this->cardContent(['base_words' => [
            ['lemma' => 'come', 'part_of_speech' => 'verb', 'translation' => 'to come', 'gender' => '', 'dictionary_form' => 'com|e -es'],
        ]]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'come']);
        $proposal = $user->proposals()->sole();
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $word = BaseWord::sole();
        $this->assertNull($word->dictionary_form);
        $this->assertSame('come', $word->displayForm());
    }

    /**
     * Like the translation, the dictionary form is written once with the base entry and
     * never revised — a later proposal for the same lemma reuses the row as it stands.
     */
    public function test_a_second_proposal_does_not_revise_a_stored_dictionary_form(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();

        BaseWord::create([
            'user_id' => $user->id, 'language_id' => $language->id,
            'lemma' => 'kosta', 'part_of_speech' => 'verb',
            'translation' => 'to cost', 'dictionary_form' => 'kost|a -ar',
        ]);

        $proposal = $this->completedProposal($user, $language);
        $proposal->baseWords->firstWhere('lemma', 'kosta')->update(['dictionary_form' => 'REVISED']);

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $this->assertSame(1, BaseWord::where('lemma', 'kosta')->count());
        $this->assertSame('kost|a -ar', BaseWord::where('lemma', 'kosta')->sole()->dictionary_form);
    }

    /**
     * An existing card of the learner's, linked to the given base words.
     */
    private function cardUsing(User $user, Language $language, string $term, BaseWord ...$words): Card
    {
        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'term' => $term]);
        $card->baseWords()->attach(collect($words)->pluck('id')->all());

        return $card;
    }

    private function baseWord(User $user, Language $language, string $lemma, string $partOfSpeech): BaseWord
    {
        return BaseWord::create([
            'user_id' => $user->id, 'language_id' => $language->id,
            'lemma' => $lemma, 'part_of_speech' => $partOfSpeech, 'translation' => $lemma,
        ]);
    }

    /**
     * A card is related when it shares two base words with the proposal, or one noun.
     */
    public function test_related_cards_share_two_words_or_a_noun_sorted_and_capped(): void
    {
        [$user, $language] = $this->learner();
        $hur = $this->baseWord($user, $language, 'hur', 'adverb');
        $mycket = $this->baseWord($user, $language, 'mycket', 'adverb');
        $pris = $this->baseWord($user, $language, 'pris', 'noun');

        foreach (range(1, 5) as $i) {
            $this->cardUsing($user, $language, "ett bra pris $i", $pris);
        }
        $both = $this->cardUsing($user, $language, 'hur mycket', $hur, $mycket);
        $lone = $this->cardUsing($user, $language, 'hur gammal', $hur);

        $proposal = $this->completedProposal($user, $language);
        $proposal->baseWords()->create(['lemma' => 'pris', 'part_of_speech' => 'noun']);
        $related = $proposal->load('baseWords')->relatedCards(Proposal::presenceIndex(collect([$proposal])));

        $this->assertCount(Proposal::MAX_RELATED_CARDS, $related);
        $this->assertSame($both->id, $related->first()['card']->id);
        $this->assertSame(2, $related->first()['shared']);
        $this->assertNotContains($lone->id, $related->pluck('card.id'));
    }

    /**
     * A proposal whose every linked word is already on one card adds nothing to the base:
     * staging refuses it, pointing at the most recently saved such card.
     */
    public function test_a_proposal_whose_words_are_all_on_one_card_is_refused(): void
    {
        [$user, $language] = $this->learner();
        Http::fake();
        $hur = $this->baseWord($user, $language, 'hur', 'adverb');
        $mycket = $this->baseWord($user, $language, 'mycket', 'adverb');
        $kosta = $this->baseWord($user, $language, 'kosta', 'verb');

        $this->cardUsing($user, $language, 'hur mycket kostade biljetten', $hur, $mycket, $kosta);
        $latest = $this->cardUsing($user, $language, 'hur mycket kostar kaffet', $hur, $mycket, $kosta);
        $this->cardUsing($user, $language, 'hur mycket', $hur, $mycket);

        $proposal = $this->completedProposal($user, $language);
        $batch = collect([$proposal]);

        $this->assertSame($latest->id, $proposal->coveringCard(Proposal::presenceIndex($batch), Proposal::knownIndex($batch), Proposal::expressionPresenceIndex($batch))->id);

        $rows = $this->actingAs($user)->getJson('/staging/list')->json('rows');
        $this->assertStringContainsString('at least one card with exactly these words', $rows);
        $this->assertStringContainsString('href="/cards/'.$latest->id.'"', $rows);
        $this->assertStringNotContainsString('js-approve', $rows);

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(409)->assertJson(['covering_card_id' => $latest->id]);
        Http::assertNothingSent();
    }

    public function test_a_known_word_does_not_stop_a_proposal_being_covered_but_a_new_word_does(): void
    {
        [$user, $language] = $this->learner();
        $hur = $this->baseWord($user, $language, 'hur', 'adverb');
        $mycket = $this->baseWord($user, $language, 'mycket', 'adverb');
        $card = $this->cardUsing($user, $language, 'hur mycket', $hur, $mycket);

        $proposal = $this->completedProposal($user, $language);
        $covering = fn () => $proposal->coveringCard(...array_map(fn ($index) => Proposal::$index(collect([$proposal])), ['presenceIndex', 'knownIndex', 'expressionPresenceIndex']));

        // kosta is new, so the card adds a word to the base.
        $this->assertNull($covering());

        KnownWord::create(['user_id' => $user->id, 'language_id' => $language->id, 'lemma' => 'kosta', 'part_of_speech' => 'verb']);

        $this->assertSame($card->id, $covering()->id);
    }

    public function test_a_fixed_expression_the_card_lacks_stops_a_proposal_being_covered(): void
    {
        [$user, $language] = $this->learner();
        $words = collect([['hur', 'adverb'], ['mycket', 'adverb'], ['kosta', 'verb']])
            ->map(fn ($word) => $this->baseWord($user, $language, ...$word));
        $card = $this->cardUsing($user, $language, 'hur mycket kostade det', ...$words);
        $expression = FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'hur mycket', 'translation' => 'how much']);

        $proposal = $this->completedProposal($user, $language);
        $proposal->fixedExpressions()->create(['form' => 'hur mycket', 'translation' => 'how much']);
        $proposal->load('fixedExpressions');
        $covering = fn () => $proposal->coveringCard(...array_map(fn ($index) => Proposal::$index(collect([$proposal])), ['presenceIndex', 'knownIndex', 'expressionPresenceIndex']));

        $this->assertNull($covering());

        $card->fixedExpressions()->attach($expression->id);

        $this->assertSame($card->id, $covering()->id);
    }

    public function test_an_identical_term_is_blocked_without_a_context(): void
    {
        [$user, $language] = $this->learner();
        Http::fake();
        $this->cardUsing($user, $language, 'hur mycket kostar det');
        $proposal = $this->completedProposal($user, $language);

        $this->assertFalse($proposal->isApprovable());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(409);

        $rows = $this->actingAs($user)->getJson('/staging/list')->json('rows');
        $this->assertStringContainsString('Regenerate that card', $rows);
    }

    public function test_an_identical_term_with_a_context_is_approved_as_a_second_card(): void
    {
        [$user, $language] = $this->learner();
        $existing = $this->cardUsing($user, $language, 'hur mycket kostar det');
        $proposal = $this->completedProposal($user, $language);
        $proposal->update(['context' => 'asking a friend, not a shop']);

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $this->assertSame(2, Card::count());
        $this->assertModelExists($existing);
    }

    /**
     * A card whose every word the proposal carries too is offered for replacing, on its own
     * line rather than among related cards; one with a word the proposal lacks is not.
     */
    public function test_a_card_the_proposal_contains_is_offered_for_replacing(): void
    {
        [$user, $language] = $this->learner();
        $hur = $this->baseWord($user, $language, 'hur', 'adverb');
        $mycket = $this->baseWord($user, $language, 'mycket', 'adverb');
        $gammal = $this->baseWord($user, $language, 'gammal', 'adjective');
        $contained = $this->cardUsing($user, $language, 'hur mycket', $hur, $mycket);
        $wider = $this->cardUsing($user, $language, 'hur gammal är det mycket', $hur, $mycket, $gammal);

        $proposal = $this->completedProposal($user, $language);
        $replaceable = fn () => $proposal->replaceableCards(...array_map(fn ($index) => Proposal::$index(collect([$proposal])), ['presenceIndex', 'knownIndex', 'expressionPresenceIndex']));

        $this->assertSame([$contained->id], $replaceable()->pluck('id')->all());

        // An expression the card has and the proposal lacks keeps it out.
        $expression = FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'hur mycket', 'translation' => 'how much']);
        $contained->fixedExpressions()->attach($expression->id);
        $this->assertTrue($replaceable()->isEmpty());

        $proposal->fixedExpressions()->create(['form' => 'hur mycket', 'translation' => 'how much']);
        $proposal->load('fixedExpressions');
        $this->assertSame([$contained->id], $replaceable()->pluck('id')->all());

        $rows = $this->actingAs($user)->getJson('/staging/list')->json('rows');
        $this->assertStringContainsString('all its words are in this card', $rows);
        $this->assertStringContainsString('data-card-id="'.$contained->id.'"', $rows);
        $this->assertStringNotContainsString('data-card-id="'.$wider->id.'"', $rows);
    }

    public function test_approving_replaces_a_switched_on_card_and_carries_its_note(): void
    {
        [$user, $language] = $this->learner();
        $hur = $this->baseWord($user, $language, 'hur', 'adverb');
        $mycket = $this->baseWord($user, $language, 'mycket', 'adverb');
        $old = $this->cardUsing($user, $language, 'hur mycket', $hur, $mycket);
        $old->update(['note' => 'asked at the market']);
        $kept = $this->cardUsing($user, $language, 'mycket', $mycket);

        $proposal = $this->completedProposal($user, $language);
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/replace/{$old->id}", ['replace' => 1])->assertNoContent();

        $this->fakeOpenAi($this->cardContent());
        $url = $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200)->json('url');

        $this->assertModelMissing($old);
        $this->assertModelExists($kept);
        $this->assertSame('asked at the market', Card::find((int) basename($url))->note);
    }

    public function test_switching_a_card_off_keeps_it_and_another_learners_card_is_refused(): void
    {
        [$user, $language] = $this->learner();
        $hur = $this->baseWord($user, $language, 'hur', 'adverb');
        $old = $this->cardUsing($user, $language, 'hur', $hur);
        $proposal = $this->completedProposal($user, $language);

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/replace/{$old->id}", ['replace' => 1]);
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/replace/{$old->id}", ['replace' => 0]);
        $this->assertFalse($proposal->fresh()->isMarkedForReplace($old));

        [$other] = $this->learner();
        $foreign = Card::factory()->create(['user_id' => $other->id, 'language_id' => $language->id]);
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/replace/{$foreign->id}", ['replace' => 1])->assertForbidden();

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);
        $this->assertModelExists($old);
    }

    /**
     * A proposal already through CALL 1, built directly so the tests that only care about
     * what happens next don't have to fake the call.
     */
    private function completedProposal(User $user, Language $language): Proposal
    {
        $proposal = $user->proposals()->create([
            'language_id' => $language->id,
            'raw_input' => 'hur mycket kostar det',
            'term' => 'hur mycket kostar det',
            'status' => Proposal::STATUS_COMPLETED,
        ]);

        $candidates = [
            ['hur', 'adverb', null],
            ['mycket', 'adverb', null],
            ['kosta', 'verb', 'kost|a -ar'],
        ];

        foreach ($candidates as [$lemma, $pos, $dictionaryForm]) {
            $proposal->baseWords()->create([
                'lemma' => $lemma, 'part_of_speech' => $pos,
                'dictionary_form' => $dictionaryForm,
            ]);
        }

        return $proposal->load('baseWords');
    }
}
