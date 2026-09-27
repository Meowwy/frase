<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeProposalJob;
use App\Models\BaseWord;
use App\Models\Card;
use App\Models\Language;
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
            'card_kind' => Card::SHAPE_PHRASE,
            'term' => 'hur mycket kostar det',
            'base_words' => [
                ['lemma' => 'hur', 'part_of_speech' => 'adverb', 'surface_form' => 'hur', 'translation' => 'how', 'gender' => ''],
                ['lemma' => 'mycket', 'part_of_speech' => 'adverb', 'surface_form' => 'mycket', 'translation' => 'much', 'gender' => ''],
                ['lemma' => 'kosta', 'part_of_speech' => 'verb', 'surface_form' => 'kostar', 'translation' => 'to cost', 'gender' => ''],
                ['lemma' => 'det', 'part_of_speech' => 'pronoun', 'surface_form' => 'det', 'translation' => 'it', 'gender' => ''],
            ],
            'anchor' => '',
        ], $overrides);
    }

    private function cardContent(array $overrides = []): array
    {
        return array_replace([
            'sentence' => 'Jag undrar [hur mycket kostar det] i den nya butiken.',
            'translation' => 'how much does it cost',
            'definition' => 'Asking after the price of something.',
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
        $this->assertSame(Card::SHAPE_PHRASE, $proposal->card_shape);
        $this->assertSame('Swedish', $proposal->language->name);

        // "det" is a pronoun, so the proficiency filter drops it at B1.
        $this->assertSame(['hur', 'kosta', 'mycket'], $proposal->baseWords->pluck('lemma')->sort()->values()->all());
        $this->assertSame('kostar', $proposal->baseWords->firstWhere('lemma', 'kosta')->surface_form);
    }

    public function test_an_a2_learner_keeps_the_function_words(): void
    {
        [$user] = $this->learner('A2');
        $this->fakeOpenAi($this->analysis());

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'hur mycket kostar det']);

        $this->assertCount(4, $user->proposals()->sole()->baseWords);
    }

    /**
     * The proficiency filter must never cut below what the shape needs. A phrase made only
     * of function words would otherwise arrive with an empty tray — permanently
     * unapprovable, with nothing to un-strike back into existence.
     */
    public function test_the_proficiency_filter_stands_down_rather_than_emptying_a_phrase(): void
    {
        [$user] = $this->learner('C1');
        $this->fakeOpenAi($this->analysis([
            'term' => 'in spite of',
            'base_words' => [
                ['lemma' => 'in', 'part_of_speech' => 'preposition', 'surface_form' => 'in', 'translation' => 'v', 'gender' => ''],
                ['lemma' => 'spite', 'part_of_speech' => 'preposition', 'surface_form' => 'spite', 'translation' => 'vzdor', 'gender' => ''],
                ['lemma' => 'of', 'part_of_speech' => 'preposition', 'surface_form' => 'of', 'translation' => 'z', 'gender' => ''],
            ],
        ]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'in spite of']);

        $proposal = $user->proposals()->sole();
        $this->assertCount(3, $proposal->baseWords);
        $this->assertTrue($proposal->isApprovable());
    }

    /**
     * An expression may legitimately end with no base words, so the filter is free to empty
     * it — and it stays approvable.
     */
    public function test_an_expression_may_be_filtered_down_to_no_base_words(): void
    {
        [$user] = $this->learner('C1');
        $this->fakeOpenAi($this->analysis([
            'card_kind' => Card::SHAPE_EXPRESSION,
            'term' => 'fine by me',
            'base_words' => [
                ['lemma' => 'by', 'part_of_speech' => 'preposition', 'surface_form' => 'by', 'translation' => 'od', 'gender' => ''],
                ['lemma' => 'me', 'part_of_speech' => 'pronoun', 'surface_form' => 'me', 'translation' => 'mě', 'gender' => ''],
            ],
        ]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'fine by me']);

        $proposal = $user->proposals()->sole();
        $this->assertCount(0, $proposal->baseWords);
        $this->assertTrue($proposal->isApprovable());
    }

    /**
     * A word card is exactly one base word — its own lemma — and is the only shape that
     * carries an anchor phrase. Swedish nouns also carry a gender, which is what makes the
     * chip and the base entry read "ett hus" rather than bare "hus".
     */
    public function test_a_word_proposal_keeps_its_anchor_and_its_swedish_noun_gender(): void
    {
        [$user] = $this->learner();
        $this->fakeOpenAi($this->analysis([
            'card_kind' => Card::SHAPE_WORD,
            'term' => 'hus',
            'base_words' => [
                ['lemma' => 'hus', 'part_of_speech' => 'noun', 'surface_form' => 'hus', 'translation' => 'house', 'gender' => 'neuter'],
            ],
            'anchor' => 'ett stort [hus]',
        ]));

        $this->actingAs($user)->postJson('/capture', ['capturedWord' => 'hus']);

        $proposal = $user->proposals()->sole();
        $this->assertSame('ett stort [hus]', $proposal->anchor);

        $word = $proposal->baseWords->sole();
        $this->assertSame(['gender' => 'neuter'], $word->grammar_attributes);
        $this->assertSame('ett hus', $word->displayForm());
    }

    public function test_approving_writes_the_card_its_links_and_the_base_entries(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $proposal = $this->completedProposal($user, $language);

        $this->fakeOpenAi($this->cardContent());
        $response = $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve");

        $card = Card::sole();
        $response->assertStatus(200)->assertJson(['redirect' => '/cards/'.$card->id]);

        $this->assertSame('hur mycket kostar det', $card->term);
        $this->assertSame(Card::SHAPE_PHRASE, $card->card_shape);
        $this->assertSame(Card::TYPE_LEXICAL, $card->termType());
        $this->assertSame('how much does it cost', $card->translation);
        $this->assertSame(['hur', 'kosta', 'mycket'], $card->baseWords->pluck('lemma')->sort()->values()->all());
        $this->assertSame('kostar', $card->baseWords->firstWhere('lemma', 'kosta')->pivot->surface_form);

        // The proposal is gone: staging holds nothing once it has been acted on.
        $this->assertSame(0, Proposal::count());
    }

    /**
     * Striking governs base membership only — the Term is untouched.
     */
    public function test_striking_a_word_keeps_it_out_of_the_base_without_rewriting_the_term(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        $proposal = $this->completedProposal($user, $language);
        $struck = $proposal->baseWords->firstWhere('lemma', 'hur');

        $this->actingAs($user)
            ->postJson("/staging/{$proposal->id}/words/{$struck->id}/strike", ['struck' => true])
            ->assertStatus(200)
            ->assertJson(['kept' => 2, 'approvable' => true]);

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $card = Card::sole();
        $this->assertSame('hur mycket kostar det', $card->term);
        $this->assertSame(['kosta', 'mycket'], $card->baseWords->pluck('lemma')->sort()->values()->all());
    }

    public function test_a_phrase_proposal_cannot_be_approved_with_every_word_struck(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        Http::fake();
        $proposal = $this->completedProposal($user, $language);
        $proposal->baseWords()->update(['struck' => true]);

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(422);

        $this->assertSame(0, Card::count());
        Http::assertNothingSent();
    }

    public function test_a_phrase_proposal_cannot_be_approved_over_the_base_word_cap(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        Http::fake();
        $proposal = $this->completedProposal($user, $language);

        foreach (range(1, 4) as $n) {
            $proposal->baseWords()->create([
                'lemma' => 'ord'.$n, 'part_of_speech' => 'noun',
                'surface_form' => 'ord'.$n, 'translation' => 'word'.$n,
            ]);
        }

        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(422);
        Http::assertNothingSent();
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
     * Owning a base word of a Term is a different fact from owning a card for it, and never
     * blocks the capture.
     */
    public function test_owning_a_base_word_of_the_term_does_not_block_approval(): void
    {
        [$user, $language] = $this->learner();
        Queue::fake();
        BaseWord::create([
            'user_id' => $user->id, 'language_id' => $language->id,
            'lemma' => 'kosta', 'part_of_speech' => 'verb', 'translation' => 'to cost',
        ]);
        $proposal = $this->completedProposal($user, $language);

        $this->fakeOpenAi($this->cardContent());
        $this->actingAs($user)->postJson("/staging/{$proposal->id}/approve")->assertStatus(200);

        $this->assertSame(1, Card::count());
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
        $this->actingAs($other)->deleteJson("/staging/{$proposal->id}")->assertStatus(403);
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
            'card_shape' => Card::SHAPE_PHRASE,
            'status' => Proposal::STATUS_COMPLETED,
        ]);

        foreach ([['hur', 'adverb', 'hur', 'how'], ['mycket', 'adverb', 'mycket', 'much'], ['kosta', 'verb', 'kostar', 'to cost']] as [$lemma, $pos, $surface, $translation]) {
            $proposal->baseWords()->create([
                'lemma' => $lemma, 'part_of_speech' => $pos,
                'surface_form' => $surface, 'translation' => $translation,
            ]);
        }

        return $proposal->load('baseWords');
    }
}
