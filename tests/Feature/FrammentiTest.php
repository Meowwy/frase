<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\Card;
use App\Models\FixedExpression;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Frammenti: the progress rules applied by /frammenti/results, batch selection behind
 * /frammenti/batch, and the pages that lead to it.
 */
class FrammentiTest extends TestCase
{
    use RefreshDatabase;

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

    private function words(User $user, Language $language, int $count, array $attributes = []): array
    {
        return array_map(fn ($i) => $this->word($user, $language, 'ord'.$i, $attributes), range(1, $count));
    }

    /**
     * The 5 nouns and 5 verbs Frammenti needs to open, resting a year so a test's own
     * items are dealt first.
     */
    private function unlock(User $user, Language $language, int $nouns = 5, int $verbs = 5): void
    {
        $resting = ['frammenti_rest_until' => now()->addYear()];
        foreach (range(1, $nouns) as $i) {
            $this->word($user, $language, 'substantiv'.$i, $resting);
        }
        foreach (range(1, $verbs) as $i) {
            $this->word($user, $language, 'verb'.$i, ['part_of_speech' => 'verb'] + $resting);
        }
    }

    private function answer(User $user, BaseWord|FixedExpression $item, bool $result)
    {
        return $this->actingAs($user)->postJson('/frammenti/results', ['results' => [
            ['type' => $item instanceof BaseWord ? 'base_word' : 'fixed_expression', 'id' => $item->id, 'result' => $result],
        ]]);
    }

    /**
     * Answer every slot of the request with a valid fragment of its type.
     */
    private function fakeAi(): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => function ($request) {
            preg_match_all('/^\d+\. (Ia|Ib|II|III) .*?(?:write (\d)|no distractors)/m', $request['messages'][1]['content'], $slots, PREG_SET_ORDER);

            $fragments = array_map(fn ($slot) => [
                'fragment' => in_array($slot[1], ['Ia', 'II'], true) ? 'Jag ser ___ här.' : 'Jag ser [[det]] här.',
                'translation' => 'I see it here.',
                'distractors' => array_fill(0, (int) ($slot[2] ?? 0), 'fel'),
                'accepted' => $slot[1] === 'II' ? [['det']] : [],
            ], $slots);

            return Http::response(['choices' => [['message' => ['content' => json_encode(['fragments' => $fragments])]]]]);
        }]);
    }

    private function batch(User $user, Language $language, array $exclude = [])
    {
        return $this->actingAs($user)->postJson('/frammenti/batch', ['language_id' => $language->id, 'exclude' => $exclude]);
    }

    public function test_two_correct_in_a_row_promote_and_a_wrong_in_between_resets_the_streak(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus');

        $this->answer($user, $word, true);
        $this->answer($user, $word, false);
        $this->answer($user, $word, true);
        $this->assertSame(2, $word->fresh()->frammenti_tier);

        $this->answer($user, $word, true)
            ->assertExactJson([['type' => 'base_word', 'id' => $word->id, 'tier_before' => 2, 'tier_after' => 3]]);
        $this->assertSame(0, $word->fresh()->frammenti_correct_streak);
    }

    /**
     * Tier I is disabled: every item starts at II and is never demoted below it.
     */
    public function test_two_wrong_in_a_row_demote_but_never_below_tier_two(): void
    {
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus', ['frammenti_tier' => 3]);

        $this->answer($user, $word, false);
        $this->answer($user, $word, false);
        $this->assertSame(2, $word->fresh()->frammenti_tier);

        $this->answer($user, $word, false);
        $this->answer($user, $word, false);
        $this->assertSame(2, $word->fresh()->frammenti_tier);
    }

    public function test_two_correct_at_tier_three_master_and_one_wrong_drops_back_to_three(): void
    {
        [$user, $language] = $this->learner();
        $expression = FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'tycka om', 'translation' => 'to like', 'frammenti_tier' => 3]);

        $this->answer($user, $expression, true);
        $this->answer($user, $expression, true);
        $this->assertSame(4, $expression->fresh()->frammenti_tier);

        $this->answer($user, $expression, false);
        $expression->refresh();
        $this->assertSame(3, $expression->frammenti_tier);
        $this->assertSame(0, $expression->frammenti_correct_streak);
        $this->assertNull($expression->frammenti_rest_until);
    }

    public function test_a_correct_answer_rests_the_item_by_tier_and_a_wrong_one_clears_the_rest(): void
    {
        $this->freezeSecond();
        [$user, $language] = $this->learner();

        foreach ([1 => 4, 2 => 24, 3 => 72] as $tier => $hours) {
            $word = $this->word($user, $language, 'ord'.$tier, ['frammenti_tier' => $tier]);
            $this->answer($user, $word, true);
            $this->assertTrue(now()->addHours($hours)->equalTo($word->fresh()->frammenti_rest_until));
        }

        $this->answer($user, $word, false);
        $this->assertNull($word->fresh()->frammenti_rest_until);
    }

    public function test_a_mastered_item_rests_longer_each_time_ending_at_sixty_days(): void
    {
        $this->freezeSecond();
        [$user, $language] = $this->learner();
        $word = $this->word($user, $language, 'hus', ['frammenti_tier' => 4]);

        foreach ([7, 14, 30, 60, 60] as $days) {
            $this->answer($user, $word, true);
            $this->assertTrue(now()->addDays($days)->equalTo($word->fresh()->frammenti_rest_until));
        }
        $this->assertSame(4, $word->fresh()->frammenti_tier);
    }

    public function test_only_tiers_two_and_three_stamp_last_recall_and_no_card_is_touched(): void
    {
        [$user, $language] = $this->learner();
        // Tier I is unreachable for now, but its rule is kept.
        $recognised = $this->word($user, $language, 'hus', ['frammenti_tier' => 1]);
        $produced = $this->word($user, $language, 'bil', ['frammenti_tier' => 2]);
        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'level' => 3, 'next_study_at' => '2030-01-01 00:00:00']);
        $card->baseWords()->attach([$recognised->id, $produced->id]);

        $this->answer($user, $recognised, true);
        $this->answer($user, $produced, true);

        $this->assertNull($recognised->fresh()->last_recalled_at);
        $this->assertNotNull($produced->fresh()->last_recalled_at);
        $this->assertSame(3, $card->fresh()->level);
        $this->assertSame('2030-01-01 00:00:00', $card->fresh()->next_study_at);
    }

    public function test_another_learners_items_are_ignored(): void
    {
        [$owner, $language] = $this->learner();
        [$intruder] = $this->learner();
        $word = $this->word($owner, $language, 'hus');

        $this->answer($intruder, $word, true)->assertExactJson([]);
        $this->assertSame(0, $word->fresh()->frammenti_correct_streak);
    }

    public function test_a_batch_deals_five_fragments_for_five_distinct_items(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->words($user, $language, 4);
        FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'tycka om', 'translation' => 'to like']);
        $this->fakeAi();

        $fragments = $this->batch($user, $language)->assertOk()->json('fragments');

        $this->assertCount(5, $fragments);
        $this->assertCount(5, collect($fragments)->map(fn ($f) => $f['type'].$f['id'])->unique());
        $this->assertContains('fixed_expression', array_column($fragments, 'type'));
        // Every item starts at tier II, so a fresh base deals only cloze fragments.
        $this->assertSame(['II'], array_values(array_unique(array_column($fragments, 'fragment_type'))));
        $this->assertSame([['det']], $fragments[0]['accepted']);
    }

    public function test_ready_items_come_before_resting_ones_and_excluded_ones_stay_out(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $ready = $this->words($user, $language, 5);
        $excluded = $this->word($user, $language, 'utesluten');
        $this->word($user, $language, 'vilar', ['frammenti_rest_until' => now()->addDay()]);
        $this->fakeAi();

        $ids = array_column($this->batch($user, $language, [['type' => 'base_word', 'id' => $excluded->id]])->json('fragments'), 'id');

        $this->assertEqualsCanonicalizing(array_map(fn ($w) => $w->id, $ready), $ids);
    }

    public function test_too_few_ready_items_are_topped_up_from_the_soonest_waking(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $ready = $this->words($user, $language, 3);
        $soon = $this->word($user, $language, 'snart', ['frammenti_rest_until' => now()->addHour(), 'frammenti_tier' => 4]);
        $later = $this->word($user, $language, 'senare', ['frammenti_rest_until' => now()->addHours(2)]);
        $this->word($user, $language, 'sist', ['frammenti_rest_until' => now()->addDays(3)]);
        $this->fakeAi();

        $fragments = collect($this->batch($user, $language)->json('fragments'))->keyBy('id');

        $this->assertEqualsCanonicalizing([...array_map(fn ($w) => $w->id, $ready), $soon->id, $later->id], $fragments->keys()->all());
        // Mastered is dealt as tier III.
        $this->assertSame('III', $fragments[$soon->id]['fragment_type']);
    }

    /**
     * Tier I is unreachable for now; this keeps its dormant dealing code honest.
     */
    public function test_below_b1_tier_one_options_come_from_the_base_with_the_same_part_of_speech(): void
    {
        [$user, $language] = $this->learner('A2');
        $this->unlock($user, $language);
        $tierOne = ['frammenti_tier' => 1];
        $this->words($user, $language, 3, $tierOne);
        $this->word($user, $language, 'springa', ['part_of_speech' => 'verb'] + $tierOne);
        $this->word($user, $language, 'läsa', ['part_of_speech' => 'verb'] + $tierOne);
        $this->word($user, $language, 'stor', ['part_of_speech' => 'adjective'] + $tierOne);
        $this->fakeAi();

        $fragments = $this->batch($user, $language)->json('fragments');

        foreach ($fragments as $fragment) {
            $this->assertContains($fragment['fragment_type'], ['Ia', 'Ib']);
            $this->assertCount(3, $fragment['options']);
            $this->assertContains($fragment['answer'], $fragment['options']);
            $item = BaseWord::find($fragment['id']);
            $others = collect($fragment['options'])->reject(fn ($o) => $o === $fragment['answer'] || $o === 'fel');
            $sameKind = BaseWord::where('part_of_speech', $item->part_of_speech)->whereKeyNot($item->id)->get()
                ->flatMap(fn ($w) => [$w->displayForm(), $w->translation]);

            $this->assertTrue($others->every(fn ($o) => $sameKind->contains($o)), $item->lemma);
        }
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

    public function test_a_tier_three_fragment_may_only_use_tier_three_words(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->words($user, $language, 4);
        $this->word($user, $language, 'hus', ['frammenti_tier' => 3]);
        $this->word($user, $language, 'bil', ['frammenti_tier' => 4, 'frammenti_rest_until' => now()->addYear()]);
        $this->fakeAi();

        $this->batch($user, $language)->assertOk();

        Http::assertSent(function ($request) {
            preg_match('/tier III words: (.*)$/m', $request['messages'][1]['content'], $list);
            $words = explode(', ', $list[1] ?? '');

            return str_contains($request['messages'][0]['content'], 'A III fragment is stricter')
                && count($words) === 2 && ! array_diff(['hus', 'bil'], $words);
        });
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
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['fragments' => []])]]]])]);

        $this->batch($user, $language)->assertStatus(503)->assertJsonMissingPath('fragments');
    }

    public function test_frammenti_replaces_refresher_and_words_mode_everywhere(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);

        // Nothing is generated until the learner presses Start.
        Http::fake();
        $this->actingAs($user)->get('/frammenti')->assertOk()->assertSee('id="startBtn"', false);
        Http::assertNothingSent();
        $this->actingAs($user)->get('/refresher')->assertNotFound();
        $this->actingAs($user)->get('/setLearning')->assertOk()->assertDontSee('data-mode="words"', false);
        $this->actingAs($user)->get('/base')->assertSee(route('frammenti', ['language_id' => $language->id]), false);
        $this->actingAs($user)->withSession(['learning_filter' => ['language_id' => $language->id, 'wordbox' => 'all', 'scope' => 'due']])
            ->get('/completeLearning')->assertSee(route('frammenti', ['language_id' => $language->id]), false);
    }
}
