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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Frammenti: the word-level schedule applied by /frammenti/answer, the daily session plan,
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

    private function learner(string $level = 'B1'): array
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
        ]);
    }

    private function words(User $user, Language $language, int $count, array $attributes = [], string $prefix = 'ord'): array
    {
        return array_map(fn ($i) => $this->word($user, $language, $prefix.$i, $attributes), range(1, $count));
    }

    private function due(int $daysAgo = 0, int $interval = 1, int $tier = 1): array
    {
        return ['frammenti_tier' => $tier, 'frammenti_interval' => $interval, 'frammenti_due_on' => now()->subDays($daysAgo)->toDateString()];
    }

    /**
     * The 5 nouns and 5 verbs Frammenti needs to open, due far ahead so they are only ever
     * filler.
     */
    private function unlock(User $user, Language $language, int $nouns = 5, int $verbs = 5): void
    {
        $later = ['frammenti_interval' => 1, 'frammenti_due_on' => '2099-01-01'];
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
            'owed' => [],
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
        array $cloze = ['fragment' => 'Jag ser ___ här.', 'translation' => 'I see it here.', 'accepted' => [['det']]],
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

    public function test_a_new_item_is_introduced_due_tomorrow_whether_right_or_wrong(): void
    {
        [$user, $language] = $this->learner();
        $right = $this->word($user, $language, 'hus');
        $wrong = $this->word($user, $language, 'bil');

        $this->answer($user, $right, true);
        $this->answer($user, $wrong, false);

        foreach ([[$right->fresh(), 0], [$wrong->fresh(), 1]] as [$item, $misses]) {
            $this->assertSame(1, $item->frammenti_tier);
            $this->assertSame(1, $item->frammenti_interval);
            $this->assertSame('2026-10-15', $item->frammenti_due_on);
            $this->assertSame($misses, $item->frammenti_miss_streak);
        }
    }

    public function test_correct_answers_double_the_interval_and_promote_at_eight_days_restarting_at_two(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus');

        // Introduced (1) → 2 → 4 → would be 8: tier II at 2.
        foreach ([[1, 1], [2, 1], [4, 1], [2, 2], [4, 2], [2, 3]] as [$interval, $tier]) {
            $this->answer($user, $word, true);
            $word->refresh();
            $this->assertSame([$interval, $tier], [$word->frammenti_interval, $word->frammenti_tier]);
            $this->travelTo(now()->addDays($interval));
        }
    }

    public function test_tier_three_keeps_doubling_up_to_a_hundred_and_eighty_days(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus', $this->due(interval: 120, tier: 3));

        $this->answer($user, $word, true);

        $word->refresh();
        $this->assertSame([3, 180], [$word->frammenti_tier, $word->frammenti_interval]);
        $this->assertSame(now()->addDays(180)->toDateString(), $word->frammenti_due_on);
    }

    public function test_two_misses_in_a_row_demote_but_never_below_tier_one(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus', $this->due(interval: 4, tier: 2));

        $this->answer($user, $word, false);
        $word->refresh();
        $this->assertSame([2, 1, 1, '2026-10-15'], [$word->frammenti_tier, $word->frammenti_miss_streak, $word->frammenti_interval, $word->frammenti_due_on]);

        $this->travelTo(now()->addDay());
        $this->answer($user, $word, false);
        $word->refresh();
        $this->assertSame([1, 0, 1], [$word->frammenti_tier, $word->frammenti_miss_streak, $word->frammenti_interval]);

        foreach (range(1, 2) as $day) {
            $this->travelTo(now()->addDay());
            $this->answer($user, $word, false);
        }
        $this->assertSame(1, $word->fresh()->frammenti_tier);
    }

    public function test_only_the_first_attempt_of_the_day_moves_the_schedule_and_no_card_is_touched(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus', $this->due(interval: 2));
        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'level' => 3, 'next_study_at' => '2030-01-01 00:00:00']);
        $card->baseWords()->attach($word->id);

        $this->answer($user, $word, true);
        $this->assertSame(4, $word->fresh()->frammenti_interval);

        // Early answers: no schedule change, but a correct one is still a recall.
        $this->travelTo(now()->addHour());
        $this->answer($user, $word, false);
        $this->answer($user, $word, true);
        $word->refresh();
        $this->assertSame([4, 0, '2026-10-18'], [$word->frammenti_interval, $word->frammenti_miss_streak, $word->frammenti_due_on]);
        $this->assertTrue($word->last_recalled_at->equalTo(now()));

        $this->assertSame(3, $card->fresh()->level);
        $this->assertSame('2030-01-01 00:00:00', $card->fresh()->next_study_at);
    }

    public function test_repetitions_owed_follow_the_level_and_a_wrong_one_does_not_count(): void
    {
        [$user, $language] = $this->learner('A1');
        $session = $this->planned($user, $language);
        $new = $this->word($user, $language, 'hus');
        $key = 'base_word:'.$new->id;

        $this->answer($user, $new, false);
        $this->assertSame([$key => 3], $session->fresh()->owed);
        $this->assertSame(1, $session->fresh()->introduced);

        // A wrong repetition changes nothing, a correct one pays one off.
        $this->answer($user, $new, false);
        $this->assertSame([$key => 3], $session->fresh()->owed);
        $this->assertSame(1, $new->fresh()->frammenti_miss_streak);
        $this->answer($user, $new, true);
        $this->assertSame([$key => 2], $session->fresh()->owed);

        // C1: a correct first attempt already was the one repetition.
        [$advanced, $language] = $this->learner('C1');
        $session = $this->planned($advanced, $language);
        $this->answer($advanced, $this->word($advanced, $language, 'bil'), true);
        $this->assertSame([], $session->fresh()->owed);

        // B1: a due item owes 2 when missed and nothing when right.
        [$intermediate, $language] = $this->learner('B1');
        $session = $this->planned($intermediate, $language);
        $missed = $this->word($intermediate, $language, 'båt', $this->due());
        $this->answer($intermediate, $missed, false);
        $this->answer($intermediate, $this->word($intermediate, $language, 'tåg', $this->due()), true);
        $this->assertSame(['base_word:'.$missed->id => 2], $session->fresh()->owed);
    }

    public function test_the_plan_needs_n_batches_for_new_items_and_never_more_than_ten(): void
    {
        [$user, $language] = $this->learner('A1');
        $this->unlock($user, $language);
        $this->word($user, $language, 'hus', $this->due());
        $this->word($user, $language, 'bil');

        // 1 due + 1 new × 3 fits one batch, but the new item needs 3 for its repetitions.
        $this->assertSame(['due' => 1, 'new' => 1, 'batches' => 3, 'minutes' => 7], Frammenti::plan($user, $language));

        [$busy, $language] = $this->learner();
        $this->words($busy, $language, 60, $this->due());
        $this->words($busy, $language, 5, prefix: 'ny');

        // New items are cut first.
        $this->assertSame(['due' => 50, 'new' => 0, 'batches' => 10, 'minutes' => 21], Frammenti::plan($busy, $language));
    }

    public function test_the_daily_intake_comes_from_the_frammenti_settings(): void
    {
        [$user, $language] = $this->learner();
        $this->words($user, $language, 5);

        $this->assertSame(5, Frammenti::plan($user, $language)['new']);

        $this->actingAs($user)->post('/profile/frammenti', ['new_per_day' => [$language->id => 2]])->assertRedirect('/profile/frammenti');
        $this->assertSame(2, Frammenti::plan($user, $language)['new']);

        $this->actingAs($user)->post('/profile/frammenti', ['new_per_day' => [$language->id => 51]])->assertSessionHasErrors();
        $this->actingAs($user)->get('/profile/frammenti')->assertOk()->assertSee('value="2"', false);
    }

    public function test_the_first_batch_plans_the_session_and_coming_back_continues_it(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->words($user, $language, 2);
        $this->fakeAi();

        $this->batch($user, $language)->assertOk()->assertJson(['drill' => false, 'planned_batches' => 2, 'batches_done' => 0]);

        $this->words($user, $language, 20, $this->due(), 'due');
        $this->batch($user, $language)->assertOk()->assertJson(['planned_batches' => 2]);
        $this->assertSame(1, FrammentiSession::count());
    }

    public function test_a_batch_deals_owed_then_most_overdue_then_newest_nouns_and_verbs(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $owed = $this->word($user, $language, 'skuld', ['frammenti_interval' => 1, 'frammenti_due_on' => '2026-10-15']);
        $this->planned($user, $language, ['owed' => ['base_word:'.$owed->id => 1]]);
        $overdue = $this->word($user, $language, 'gammal', $this->due(daysAgo: 10));
        $dueToday = $this->word($user, $language, 'idag', $this->due());
        $newestNoun = $this->word($user, $language, 'nyast', ['created_at' => now()->subDay()]);
        $olderNoun = $this->word($user, $language, 'äldre', ['created_at' => now()->subDays(2)]);
        $this->word($user, $language, 'snabb', ['part_of_speech' => 'adjective']);
        $this->fakeAi();

        $this->assertEqualsCanonicalizing(
            [$owed->id, $overdue->id, $dueToday->id, $newestNoun->id, $olderNoun->id],
            $this->ids($this->batch($user, $language)),
        );
    }

    public function test_a_repetition_is_spaced_from_its_slot_and_on_screen_items_are_not_dealt_again(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->planned($user, $language);
        // Two unanswered new items at the end of the batch on screen each owe a repetition.
        $onScreen = [...$this->words($user, $language, 3, $this->due(), 'skärm'), ...$this->words($user, $language, 2, prefix: 'ny')];
        $this->words($user, $language, 3, $this->due(daysAgo: 1), 'due');
        $this->fakeAi();

        $ids = $this->ids($this->batch($user, $language, $onScreen));

        $this->assertEmpty(array_intersect(array_map(fn ($w) => $w->id, array_slice($onScreen, 0, 3)), $ids));
        // Slot 4 of the last batch comes back no sooner than slot 2, slot 5 no sooner than slot 3.
        $this->assertGreaterThanOrEqual(1, array_search($onScreen[3]->id, $ids));
        $this->assertGreaterThanOrEqual(2, array_search($onScreen[4]->id, $ids));
    }

    public function test_new_items_wait_when_fewer_than_n_planned_batches_remain(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->planned($user, $language, ['planned_batches' => 3, 'batches_done' => 2]);
        $new = $this->word($user, $language, 'ny');
        $this->fakeAi();

        $this->assertNotContains($new->id, $this->ids($this->batch($user, $language)));
    }

    public function test_the_last_batch_finishes_the_session_and_then_comes_extra_drill(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $session = $this->planned($user, $language, ['planned_batches' => 2, 'batches_done' => 1]);
        $word = $this->word($user, $language, 'hus', $this->due(tier: 2));

        $this->answer($user, $word, true)->assertJson(['batches_done' => 1, 'recap' => null]);
        $this->answer($user, $word, true, last: true)
            ->assertJson(['batches_done' => 2, 'recap' => ['streak' => 1, 'active_words' => 1]]);
        $this->assertNotNull($session->fresh()->finished_at);

        $this->fakeAi();
        $this->batch($user, $language)->assertOk()->assertJson(['drill' => true]);
        // Extra drill is not part of the plan.
        $this->answer($user, $word, true, last: true)->assertJson(['batches_done' => 2, 'recap' => null]);
    }

    public function test_the_streak_forgives_one_missed_day_per_iso_week(): void
    {
        [$user, $language] = $this->learner();
        $finished = fn (string $date) => $this->planned($user, $language, ['date' => $date, 'finished_at' => now()]);

        // Today (Wed) isn't over, so it doesn't break the streak yet.
        $finished('2026-10-13');
        $this->assertSame(1, Frammenti::streak($user));

        // Mon 12 is missed (this week's freeze), Fri 9 too (last week's), Wed 7 breaks it.
        foreach (['2026-10-14', '2026-10-11', '2026-10-10', '2026-10-08', '2026-10-06'] as $date) {
            $finished($date);
        }
        $this->planned($user, $language, ['date' => '2026-10-07']);
        $this->assertSame(5, Frammenti::streak($user));
    }

    public function test_the_migration_keeps_the_learners_words_where_they_were(): void
    {
        [$user, $language] = $this->learner();
        $migration = require database_path('migrations/2026_10_11_000001_schedule_frammenti.php');
        $migration->down();

        $old = fn (string $lemma, array $progress) => DB::table('base_words')->insertGetId($progress + [
            'user_id' => $user->id, 'language_id' => $language->id, 'lemma' => $lemma, 'part_of_speech' => 'noun', 'translation' => $lemma,
        ]);
        $never = $old('aldrig', ['frammenti_tier' => 2]);
        $rested = $old('vilad', ['frammenti_tier' => 2, 'frammenti_rest_until' => now()->addDay()]);
        $missed = $old('miss', ['frammenti_tier' => 2, 'frammenti_wrong_streak' => 1]);
        $mastered = $old('klar', ['frammenti_tier' => 4, 'frammenti_correct_streak' => 3, 'frammenti_rest_until' => now()->addDays(30)]);

        $migration->up();

        $row = fn ($id) => array_values((array) DB::table('base_words')->where('id', $id)
            ->first(['frammenti_tier', 'frammenti_interval', 'frammenti_due_on', 'frammenti_miss_streak']));
        $this->assertSame([1, 0, null, 0], $row($never));
        $this->assertSame([1, 1, '2026-10-14', 0], $row($rested));
        $this->assertSame([1, 1, '2026-10-14', 1], $row($missed));
        $this->assertSame([2, 1, '2026-10-14', 0], $row($mastered));
    }

    public function test_beginner_context_words_are_proven_ones_only(): void
    {
        [$user, $language] = $this->learner('A1');
        $this->unlock($user, $language);
        $this->word($user, $language, 'känd', ['last_recalled_at' => now(), 'frammenti_interval' => 1, 'frammenti_due_on' => '2099-01-01']);
        $this->words($user, $language, 5);
        $this->fakeAi();

        $this->batch($user, $language)->assertOk();

        Http::assertSent(fn ($request) => preg_match('/own words: (.*)$/m', $request['messages'][1]['content'], $list) && $list[1] === 'känd');
    }

    public function test_a_beginners_tier_three_task_may_use_fifteen_proven_nouns_and_fifteen_proven_verbs(): void
    {
        [$user, $language] = $this->learner('A1');
        $proven = ['last_recalled_at' => now(), 'frammenti_tier' => 2, 'frammenti_interval' => 4, 'frammenti_due_on' => '2099-01-01'];
        $this->words($user, $language, 20, $proven, 'substantiv');
        $this->words($user, $language, 20, ['part_of_speech' => 'verb'] + $proven, 'verb');
        $goal = $this->word($user, $language, 'mål', $this->due(interval: 8, tier: 3));
        $this->fakeAi();

        $fragment = collect($this->batch($user, $language)->assertOk()->json('fragments'))->firstWhere('id', $goal->id);

        $this->assertSame('III', $fragment['fragment_type']);
        $this->assertSame('Tell your friend that you see it.', $fragment['task']);
        $this->assertSame('Jag ser [[det]].', $fragment['answer']);
        Http::assertSent(function ($request) {
            preg_match('/own words: (.*)$/m', $request['messages'][1]['content'], $list);
            $words = explode(', ', $list[1] ?? '');

            return count(preg_grep('/^substantiv/', $words)) === 15 && count(preg_grep('/^verb/', $words)) === 15;
        });
    }

    public function test_a_tier_three_answer_without_its_item_marked_fails_the_batch(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->word($user, $language, 'mål', $this->due(tier: 3));
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
        $this->fakeAi(['fragment' => 'Jag går till ___ ___.', 'translation' => 'I go to a school.', 'accepted' => [['en'], ['skola']]]);

        $this->batch($user, $language)->assertStatus(503);
    }

    public function test_a_tier_three_sentence_is_evaluated_and_a_failure_is_a_503(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus', $this->due(tier: 3));
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

    public function test_another_learners_items_are_not_found(): void
    {
        [$owner, $language] = $this->learner();
        [$intruder] = $this->learner();
        $word = $this->word($owner, $language, 'hus');

        $this->answer($intruder, $word, true)->assertNotFound();
        $this->assertNull($word->fresh()->frammenti_due_on);
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
            ->assertSee('Today: 0 due · 2 new → 2 batches · ~5 min')->assertSee('id="startBtn"', false);
        Http::assertNothingSent();
        $this->actingAs($user)->get('/')->assertSee("Today's Frammenti", false)->assertSee('Daily active-vocabulary drill')
            ->assertSee(route('frammenti', ['language_id' => $language->id]), false);
        $this->actingAs($user)->get('/base')->assertSee(route('frammenti', ['language_id' => $language->id]), false);
        $this->actingAs($user)->withSession(['learning_filter' => ['language_id' => $language->id, 'wordbox' => 'all', 'scope' => 'due']])
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
