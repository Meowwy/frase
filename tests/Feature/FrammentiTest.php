<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\Card;
use App\Models\FixedExpression;
use App\Models\Frammenti;
use App\Models\FrammentiSession;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Frammenti: the level-based schedule applied by /frammenti/answer, the session plan,
 * dealing behind /frammenti/batch, tier III evaluation, the streak, and the pages that lead
 * to it.
 */
class FrammentiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so ISO weeks in the streak test are easy to follow.
        $this->travelTo('2026-10-14 12:00:00');
    }

    private function learner(?string $level = 'B1'): array
    {
        $user = User::factory()->create();
        $swedish = Language::firstOrCreate(['code' => 'sv'], ['name' => 'Swedish', 'native_name' => 'Svenska', 'flag' => '🇸🇪']);
        $english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'flag' => '🇬🇧']);
        $user->languages()->attach($swedish->id, ['users_level' => $level]);
        $user->update(['native_language_id' => $english->id, 'active_language_id' => $swedish->id]);

        return [$user, $swedish];
    }

    private function word(User $user, Language $language, string $lemma, array $attributes = []): BaseWord
    {
        return BaseWord::create($attributes + [
            'user_id' => $user->id,
            'language_id' => $language->id,
            'lemma' => $lemma,
            'part_of_speech' => 'noun',
            'translation' => 'en: '.$lemma,
        ])->fresh();
    }

    private function words(User $user, Language $language, int $count, array $attributes = [], string $prefix = 'ord'): array
    {
        return array_map(fn ($i) => $this->word($user, $language, $prefix.$i, $attributes), range(1, $count));
    }

    private function due(int $level = 0, int $daysAgo = 0): array
    {
        return ['frammenti_level' => $level, 'frammenti_due_on' => now()->subDays($daysAgo)->toDateString()];
    }

    /**
     * The 5 nouns and 5 verbs Frammenti needs to open, due far ahead so they are only ever
     * filler.
     */
    private function unlock(User $user, Language $language, int $nouns = 5, int $verbs = 5): void
    {
        $later = ['frammenti_level' => 1, 'frammenti_due_on' => '2099-01-01'];
        foreach (range(1, $nouns) as $i) {
            $this->word($user, $language, 'substantiv'.$i, $later);
        }
        foreach (range(1, $verbs) as $i) {
            $this->word($user, $language, 'verb'.$i, ['part_of_speech' => 'verb'] + $later);
        }
    }

    private function planned(User $user, Language $language, array $attributes = []): FrammentiSession
    {
        return FrammentiSession::create($attributes + [
            'user_id' => $user->id,
            'language_id' => $language->id,
            'date' => now()->toDateString(),
            'planned_batches' => 10,
        ]);
    }

    private function key(BaseWord|FixedExpression $item): array
    {
        return ['type' => $item instanceof BaseWord ? 'base_word' : 'fixed_expression', 'id' => $item->id];
    }

    private function answer(User $user, BaseWord|FixedExpression $item, bool $result, bool $last = false)
    {
        return $this->actingAs($user)->postJson('/frammenti/answer', $this->key($item) + ['result' => $result, 'last' => $last]);
    }

    /**
     * Answer every slot of the request with a valid fragment of the shape its schema asks,
     * or with $cloze / $sentence for tiers I / III.
     */
    private function fakeAi(
        array $cloze = ['fragment' => 'Jag ser ___ här.', 'translation' => 'I see it here.', 'accepted' => [['det']], 'synonym_answers' => [[]]],
        array $sentence = ['task' => 'Tell your friend [[that]] you see it.', 'answer' => 'Jag ser [[det]].'],
    ): void {
        Http::fake(['api.openai.com/v1/chat/completions' => function ($request) use ($cloze, $sentence) {
            $fragments = array_map(fn ($slot) => match (true) {
                isset($slot['properties']['accepted']) => $cloze,
                isset($slot['properties']['task']) => $sentence,
                default => ['fragment' => 'Jag ser [[det]] här.', 'translation' => 'I see [[it]] here.'],
            }, $request['response_format']['json_schema']['schema']['properties']);

            return Http::response(['choices' => [['message' => ['content' => json_encode($fragments)]]]]);
        }]);
    }

    private function batch(User $user, Language $language, array $onScreen = [])
    {
        return $this->actingAs($user)->postJson('/frammenti/batch', ['language_id' => $language->id, 'on_screen' => array_map($this->key(...), $onScreen)]);
    }

    private function ids($response): array
    {
        return array_column($response->assertOk()->json('fragments'), 'id');
    }

    /** The items a batch response dealt, as models. */
    private function dealt($response): array
    {
        return array_map(fn ($f) => $f['type'] === 'base_word' ? BaseWord::find($f['id']) : FixedExpression::find($f['id']), $response->assertOk()->json('fragments'));
    }

    public function test_a_correct_answer_climbs_a_level_doubling_the_interval_up_to_level_eight(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus');
        $this->assertSame([0, null], [$word->frammenti_level, $word->frammenti_due_on]);
        $this->assertSame(1, Frammenti::plan($user, $language)['due']);

        // 0 → 1 … 8, back in 1, 2, 4 … 128 days; level 8 stays and keeps coming back in 128.
        foreach ([[1, 1], [2, 2], [3, 4], [4, 8], [5, 16], [6, 32], [7, 64], [8, 128], [8, 128]] as [$level, $days]) {
            $this->answer($user, $word, true);
            $word->refresh();
            $this->assertSame($level, $word->frammenti_level);
            $this->assertSame(now()->addDays($days)->toDateString(), $word->frammenti_due_on);
            $this->travelTo(now()->addDays($days));
        }
    }

    public function test_a_miss_resets_to_level_zero_due_today(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus', $this->due(level: 5, daysAgo: 3));

        $this->answer($user, $word, false);
        $word->refresh();
        $this->assertSame([0, '2026-10-14'], [$word->frammenti_level, $word->frammenti_due_on]);

        // Still due, so the next answer counts and it climbs from level 0 again.
        $this->answer($user, $word, true);
        $word->refresh();
        $this->assertSame([1, '2026-10-15'], [$word->frammenti_level, $word->frammenti_due_on]);
    }

    public function test_an_item_not_due_changes_no_schedule_and_no_card_is_touched(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus', ['frammenti_level' => 4, 'frammenti_due_on' => '2026-10-20']);
        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'level' => 3, 'next_study_at' => '2030-01-01 00:00:00']);
        $card->baseWords()->attach($word->id);

        // Early answers: no schedule change, but a correct one is still a recall.
        $this->answer($user, $word, false);
        $this->assertNull($word->fresh()->last_recalled_at);
        $this->answer($user, $word, true);
        $word->refresh();
        $this->assertSame([4, '2026-10-20'], [$word->frammenti_level, $word->frammenti_due_on]);
        $this->assertTrue($word->last_recalled_at->equalTo(now()));

        $this->assertSame(3, $card->fresh()->level);
        $this->assertSame('2030-01-01 00:00:00', $card->fresh()->next_study_at);
    }

    public function test_active_words_are_the_items_at_level_three_and_up(): void
    {
        [$user, $language] = $this->learner();
        foreach ([2, 3, 8] as $level) {
            $this->word($user, $language, 'ord'.$level, ['frammenti_level' => $level]);
        }
        FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'tycka om', 'translation' => 'to like', 'frammenti_level' => 5]);

        $this->assertSame(3, Frammenti::activeWords($user, $language));
    }

    public function test_the_level_sets_the_tier_a_word_is_dealt_at(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $expected = [];
        foreach (['I' => [0, 2], 'II' => [3, 5], 'III' => [8]] as $tier => $levels) {
            foreach ($levels as $level) {
                $expected[$this->word($user, $language, 'nivå'.$level, $this->due($level))->id] = $tier;
            }
        }
        $this->fakeAi();

        $dealt = array_column($this->batch($user, $language)->assertOk()->json('fragments'), 'fragment_type', 'id');

        $this->assertEquals($expected, $dealt);
    }

    public function test_the_plan_is_a_batch_per_five_due_items_and_never_more_than_ten(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->words($user, $language, 12);

        $this->assertSame(['due' => 12, 'batches' => 3, 'minutes' => 7], Frammenti::plan($user, $language));

        [$busy, $language] = $this->learner();
        $this->words($busy, $language, 60, $this->due(level: 4, daysAgo: 2));

        $this->assertSame(['due' => 60, 'batches' => 10, 'minutes' => 21], Frammenti::plan($busy, $language));
    }

    public function test_a_short_batch_is_padded_with_the_items_closest_to_due(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $due = $this->words($user, $language, 2, $this->due(level: 2));
        $soon = array_map(fn ($day) => $this->word($user, $language, 'snart'.$day, ['frammenti_level' => 3, 'frammenti_due_on' => '2026-10-'.$day]), [15, 16, 17]);
        $this->word($user, $language, 'senare', ['frammenti_level' => 3, 'frammenti_due_on' => '2026-10-20']);
        $this->fakeAi();

        $this->assertEqualsCanonicalizing(
            array_map(fn ($w) => $w->id, [...$due, ...$soon]),
            $this->ids($this->batch($user, $language)),
        );
    }

    public function test_a_miss_is_dealt_again_in_a_later_batch_but_never_twice_in_one(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $word = $this->word($user, $language, 'hus', $this->due(level: 3));
        $this->fakeAi();

        $first = $this->dealt($this->batch($user, $language));
        $this->assertSame(1, collect($first)->where('id', $word->id)->count());
        $this->answer($user, $word, false);

        // On screen, it isn't dealt in the prefetched batch; after that it is, once.
        $second = $this->dealt($this->batch($user, $language, $first));
        $this->assertNotContains($word->id, collect($second)->pluck('id'));
        $third = $this->ids($this->batch($user, $language, $second));
        $this->assertSame(1, count(array_keys($third, $word->id)));
        $this->assertSame($third, array_unique($third));
    }

    /**
     * Either of two linked synonyms could answer the other's fragment, so at most one of them
     * is dealt per batch — even when both are due.
     */
    public function test_linked_synonyms_are_never_dealt_in_one_batch(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $glad = $this->word($user, $language, 'glad', $this->due());
        $lycklig = $this->word($user, $language, 'lycklig', $this->due());
        $glad->linkSynonym($lycklig);
        $this->fakeAi();

        $ids = $this->ids($this->batch($user, $language));

        $this->assertCount(5, $ids);
        $this->assertCount(1, array_intersect([$glad->id, $lycklig->id], $ids));
    }

    /**
     * Links are pairs: glad~lycklig and lycklig~nöjd don't make glad and nöjd synonyms.
     */
    public function test_synonyms_of_a_synonym_may_share_a_batch(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $glad = $this->word($user, $language, 'glad', $this->due());
        $nojd = $this->word($user, $language, 'nöjd', $this->due());
        // The closest filler, which would be dealt next if it weren't their synonym.
        $lycklig = $this->word($user, $language, 'lycklig', ['frammenti_level' => 1, 'frammenti_due_on' => '2026-10-20']);
        $glad->linkSynonym($lycklig);
        $lycklig->linkSynonym($nojd);
        $this->fakeAi();

        $ids = $this->ids($this->batch($user, $language));

        $this->assertContains($glad->id, $ids);
        $this->assertContains($nojd->id, $ids);
        $this->assertNotContains($lycklig->id, $ids);
    }

    /**
     * The AI is told an item's synonyms and, at tier I, asked which forms of them would also
     * fill each gap; a slot without synonyms gets empty lists, the same shape.
     */
    public function test_a_slot_names_its_synonyms_and_tier_one_returns_their_answers(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $glad = $this->word($user, $language, 'glad', ['part_of_speech' => 'adjective'] + $this->due());
        $lycklig = $this->word($user, $language, 'lycklig', ['part_of_speech' => 'adjective', 'frammenti_due_on' => '2099-01-01']);
        $glad->linkSynonym($lycklig);
        $this->fakeAi(['fragment' => 'Jag är ___.', 'translation' => 'I am happy.', 'accepted' => [['glad']], 'synonym_answers' => [['lycklig']]]);

        $fragments = collect($this->batch($user, $language)->assertOk()->json('fragments'))->keyBy('id');

        $this->assertSame([['form' => 'lycklig', 'translation' => 'en: lycklig']], $fragments[$glad->id]['synonyms']);
        $this->assertSame([['lycklig']], $fragments[$glad->id]['synonym_answers']);
        $other = $fragments->except($glad->id)->first();
        $this->assertSame([], $other['synonyms']);
        $this->assertSame([[]], $other['synonym_answers']);

        $request = Http::recorded()->last()[0];
        $this->assertStringContainsString('"glad" (adjective) = "en: glad"; linked synonyms: "lycklig" = "en: lycklig"', $request['messages'][1]['content']);
        $schema = collect($request['response_format']['json_schema']['schema']['properties']);
        $this->assertSame(1, $schema->filter(fn ($slot) => isset($slot['properties']['synonym_answers']))->count());
    }

    /**
     * The two most recent cards holding the item whose Term is more than the item itself
     * go to the AI, which is told they lock the meaning; the page never gets them.
     */
    public function test_a_slot_carries_the_terms_of_its_most_recent_cards_that_hold_more_than_the_item(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $skola = $this->word($user, $language, 'skola', $this->due());
        $terms = ['skola' => '2026-10-01', 'en skola' => '2026-10-02', 'gamla skolan' => '2026-10-03', 'skolan öppnar' => '2026-10-04', 'min skola' => '2026-10-05'];
        foreach ($terms as $term => $created) {
            $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'term' => $term, 'created_at' => $created]);
            $skola->cards()->attach($card);
        }
        $this->fakeAi();

        $fragments = collect($this->batch($user, $language)->assertOk()->json('fragments'))->keyBy('id');

        $request = Http::recorded()->last()[0];
        $this->assertStringContainsString('"skola" (noun) = "en: skola"; saved Terms: "min skola", "skolan öppnar"', $request['messages'][1]['content']);
        $this->assertStringContainsString('lock the item\'s meaning', $request['messages'][0]['content']);
        $this->assertArrayNotHasKey('terms', $fragments[$skola->id]);
    }

    public function test_tier_one_synonym_answers_may_be_empty_but_not_the_wrong_length(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $glad = $this->word($user, $language, 'glad', $this->due());
        $glad->linkSynonym($this->word($user, $language, 'lycklig', ['frammenti_due_on' => '2099-01-01']));
        // Every slot is tier I: the filler is at level 1.
        $batch = fn (array $synonymAnswers) => ['choices' => [['message' => ['content' => json_encode(array_fill_keys(
            ['slot_1', 'slot_2', 'slot_3', 'slot_4', 'slot_5'],
            ['fragment' => 'Jag är ___.', 'translation' => 'I am happy.', 'accepted' => [['glad']], 'synonym_answers' => $synonymAnswers],
        ))]]]];
        Http::fakeSequence('api.openai.com/v1/chat/completions')->push($batch([[]]))->push($batch([[], []]));

        $this->batch($user, $language)->assertOk();
        $this->batch($user, $language)->assertStatus(503);
    }

    public function test_a_correctly_answered_word_is_not_dealt_again_that_day(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $right = $this->word($user, $language, 'rätt', $this->due());
        $others = $this->words($user, $language, 5, $this->due());
        $this->fakeAi();

        $this->answer($user, $right, true);

        $this->assertEqualsCanonicalizing(array_map(fn ($w) => $w->id, $others), $this->ids($this->batch($user, $language)));
    }

    public function test_the_first_batch_plans_the_session_and_coming_back_continues_it(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->words($user, $language, 7);
        $this->fakeAi();

        $this->batch($user, $language)->assertOk()->assertJson(['planned_batches' => 2, 'batches_done' => 0]);

        // The planned count never changes once started.
        $this->words($user, $language, 20, $this->due(), 'due');
        $this->batch($user, $language)->assertOk()->assertJson(['planned_batches' => 2]);
        $this->assertSame(1, FrammentiSession::count());
    }

    public function test_after_a_finished_session_another_starts_while_words_are_due(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $missed = $this->word($user, $language, 'miss', $this->due());
        $right = $this->word($user, $language, 'rätt', $this->due(level: 2));
        $this->fakeAi();
        $this->batch($user, $language)->assertOk()->assertJson(['planned_batches' => 1, 'batches_done' => 0]);

        $this->answer($user, $missed, false)->assertJson(['level_before' => 0, 'level_after' => 0, 'batches_done' => 0, 'recap' => null]);
        $this->answer($user, $right, true, last: true)->assertJson([
            'level_before' => 2, 'level_after' => 3, 'batches_done' => 1,
            'recap' => ['streak' => 1, 'active_words' => 1, 'due' => 1],
        ]);

        // The miss is still due, so a second session plans it.
        $this->actingAs($user)->get('/frammenti')->assertSee('1 due → 1 batch')->assertSee('id="startBtn"', false);
        $this->batch($user, $language)->assertOk()->assertJson(['planned_batches' => 1, 'batches_done' => 0]);
        $this->assertSame(2, FrammentiSession::count());
        $this->answer($user, $missed, true, last: true)->assertJson(['recap' => ['due' => 0]]);

        // Nothing due: no session and no batch.
        $this->batch($user, $language)->assertStatus(422);
        $this->assertSame(2, FrammentiSession::count());
        $this->actingAs($user)->get('/frammenti')->assertSee('Nothing due, come back tomorrow')->assertDontSee('id="startBtn"', false);
    }

    public function test_the_streak_forgives_one_missed_day_per_iso_week(): void
    {
        [$user, $language] = $this->learner();
        $finished = fn (string $date) => $this->planned($user, $language, ['date' => $date, 'finished_at' => now()]);

        // Today (Wed) isn't over, so it doesn't break the streak yet.
        $finished('2026-10-13');
        $this->assertSame(1, Frammenti::streak($user));

        // Mon 12 is missed (this week's freeze), Fri 9 too (last week's), Wed 7 breaks it.
        foreach (['2026-10-14', '2026-10-14', '2026-10-11', '2026-10-10', '2026-10-08', '2026-10-06'] as $date) {
            $finished($date);
        }
        $this->planned($user, $language, ['date' => '2026-10-07']);
        $this->assertSame(5, Frammenti::streak($user));
    }

    public function test_context_words_are_a_beginners_items_at_level_two_and_up(): void
    {
        $ownWords = fn () => Http::recorded()->map(fn ($pair) => preg_match('/own words: (.*)$/m', $pair[0]['messages'][1]['content'], $list) ? $list[1] : null)->last();

        foreach (['A1', 'A2', null] as $level) {
            [$user, $language] = $this->learner($level);
            $this->unlock($user, $language);
            $this->word($user, $language, 'känd', ['frammenti_level' => 2, 'last_recalled_at' => now(), 'frammenti_due_on' => '2099-01-01']);
            $this->word($user, $language, 'ny', ['frammenti_level' => 1, 'last_recalled_at' => now()]);
            FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'tycka om', 'translation' => 'to like', 'frammenti_level' => 7, 'last_recalled_at' => now(), 'frammenti_due_on' => '2099-01-01']);
            $this->fakeAi();

            $this->batch($user, $language)->assertOk();

            $this->assertEqualsCanonicalizing(['känd', 'tycka om'], explode(', ', $ownWords()), "level {$level}");
        }

        // From B1 vocabulary is left to the level.
        [$user, $language] = $this->learner('B1');
        $this->unlock($user, $language);
        $this->word($user, $language, 'känd', ['frammenti_level' => 2, 'last_recalled_at' => now()]);
        $this->fakeAi();

        $this->batch($user, $language)->assertOk();

        $this->assertNull($ownWords());
    }

    public function test_a_tier_three_answer_without_its_item_marked_fails_the_batch(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->word($user, $language, 'mål', $this->due(level: 6));
        $this->fakeAi(sentence: ['task' => 'Tell your friend you see it.', 'answer' => 'Jag ser det.']);

        $this->batch($user, $language)->assertStatus(503);
    }

    /**
     * Only a fixed expression whose parts stand apart may take more than one gap; a base
     * word with its article ("en skola") is one.
     */
    public function test_a_base_word_split_into_two_gaps_fails_the_batch(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->word($user, $language, 'skola');
        $this->fakeAi(['fragment' => 'Jag går till ___ ___.', 'translation' => 'I go to a school.', 'accepted' => [['en'], ['skola']]]);

        $this->batch($user, $language)->assertStatus(503);
    }

    public function test_a_tier_three_sentence_is_evaluated_and_a_failure_is_a_503(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus', $this->due(level: 6));
        [$intruder] = $this->learner();
        $evaluation = ['correct' => true, 'corrected' => 'Jag ser huset.', 'note' => 'Use the definite form.'];
        Http::fakeSequence('api.openai.com/v1/chat/completions')
            ->push(['choices' => [['message' => ['content' => json_encode($evaluation)]]]])
            ->pushStatus(500);
        $request = $this->key($word) + ['task' => 'Say you see the house.', 'sentence' => 'Jag ser hus.'];

        $this->actingAs($user)->postJson('/frammenti/evaluate', $request)->assertExactJson($evaluation);
        Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'], 'Sentence (Swedish): Jag ser hus.'));
        $this->actingAs($user)->postJson('/frammenti/evaluate', $request)->assertStatus(503);
        $this->actingAs($intruder)->postJson('/frammenti/evaluate', $request)->assertNotFound();
    }

    /**
     * The evaluation hears the item's synonyms, and says when the sentence used one in its
     * place.
     */
    public function test_a_tier_three_evaluation_is_told_the_synonyms_and_says_when_one_was_used(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'glad', ['part_of_speech' => 'adjective'] + $this->due(level: 6));
        $word->linkSynonym($this->word($user, $language, 'lycklig', ['part_of_speech' => 'adjective']));
        $evaluation = ['correct' => false, 'used_synonym' => true, 'corrected' => 'Jag är lycklig.', 'note' => ''];
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode($evaluation)]]]])]);

        $this->actingAs($user)->postJson('/frammenti/evaluate', $this->key($word) + ['task' => 'Say you are happy.', 'sentence' => 'Jag är lycklig.'])
            ->assertExactJson($evaluation);

        Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'], 'Linked synonyms of the item: "lycklig" = "en: lycklig"')
            && isset($r['response_format']['json_schema']['schema']['properties']['used_synonym']));
    }

    public function test_another_learners_items_are_not_found(): void
    {
        [$owner, $language] = $this->learner();
        [$intruder] = $this->learner();
        $word = $this->word($owner, $language, 'hus');

        $this->answer($intruder, $word, true)->assertNotFound();
        $this->assertSame([0, null], [$word->fresh()->frammenti_level, $word->fresh()->frammenti_due_on]);
    }

    public function test_fewer_than_five_nouns_or_verbs_show_the_info_and_make_no_ai_call(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language, verbs: 4);
        FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'tycka om', 'translation' => 'to like']);
        Http::fake();

        $this->batch($user, $language)->assertStatus(422);
        $this->actingAs($user)->get('/frammenti')->assertOk()
            ->assertSee('at least 5 nouns and 5 verbs')->assertDontSee('id="startBtn"', false);
        Http::assertNothingSent();
        $this->assertSame(0, FrammentiSession::count());

        $this->word($user, $language, 'springa', ['part_of_speech' => 'verb']);
        $this->actingAs($user)->get('/frammenti')->assertSee('id="startBtn"', false);
    }

    public function test_the_native_language_and_another_learners_language_are_rejected(): void
    {
        [$user, $language] = $this->learner();
        $english = Language::where('code', 'en')->sole();
        $user->languages()->attach($english->id);
        $german = Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'native_name' => 'Deutsch', 'flag' => '🇩🇪']);
        Http::fake();

        $this->batch($user, $english)->assertForbidden();
        $this->batch($user, $german)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_an_ai_failure_returns_an_error_not_a_partial_batch(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->word($user, $language, 'hus');
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([])]]]])]);

        $this->batch($user, $language)->assertStatus(503)->assertJsonMissingPath('fragments');
    }

    public function test_the_plan_is_shown_before_start_and_frammenti_is_reachable_everywhere(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->words($user, $language, 2);

        // Nothing is generated until the learner presses Start.
        Http::fake();
        $this->actingAs($user)->get('/frammenti')->assertOk()
            ->assertSee('2 due → 1 batch · ~3 min')->assertSee('id="startBtn"', false);
        Http::assertNothingSent();
        $this->actingAs($user)->get('/')->assertSee("Today's Frammenti", false)->assertSee('Daily active-vocabulary drill')
            ->assertSee(route('frammenti', ['language_id' => $language->id]), false);
        $this->actingAs($user)->withSession(['learning_filter' => ['language_id' => $language->id, 'scope' => 'due']])
            ->get('/completeLearning')->assertSee(route('frammenti', ['language_id' => $language->id]), false);
    }

    public function test_the_browser_timezone_is_recorded_once_and_moves_the_learners_day(): void
    {
        [$user] = $this->learner();
        $this->travelTo('2026-10-14 23:30:00');

        $this->actingAs($user)->postJson('/profile/timezone', ['timezone' => 'Europe/Prague'])->assertNoContent();
        $this->actingAs($user)->postJson('/profile/timezone', ['timezone' => 'America/New_York']);

        $this->assertSame('Europe/Prague', $user->fresh()->timezone);
        $this->assertSame('2026-10-15', $user->fresh()->today());
    }
}
