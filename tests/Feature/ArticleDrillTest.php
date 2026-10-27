<?php

namespace Tests\Feature;

use App\Models\ArticleDrill;
use App\Models\BaseWord;
use App\Models\Card;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The article drill on /frammenti: its schedule, which nouns a session serves, saving a
 * session, and the panel that starts it.
 */
class ArticleDrillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-14 12:00:00');
    }

    private function learner(string $code = 'sv'): array
    {
        $user = User::factory()->create();
        $language = Language::firstOrCreate(['code' => $code], ['name' => $code === 'sv' ? 'Swedish' : 'Italian', 'native_name' => $code, 'flag' => '🏳️']);
        $english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'flag' => '🇬🇧']);
        $user->languages()->attach($language->id, ['users_level' => 'B1']);
        $user->update(['native_language_id' => $english->id, 'active_language_id' => $language->id]);

        return [$user, $language];
    }

    private function noun(User $user, Language $language, string $lemma, array $attributes = []): BaseWord
    {
        return BaseWord::create($attributes + [
            'user_id' => $user->id,
            'language_id' => $language->id,
            'lemma' => $lemma,
            'part_of_speech' => 'noun',
            'grammar_attributes' => ['gender' => 'neuter'],
            'translation' => 'en: '.$lemma,
        ])->fresh();
    }

    /** The 5 nouns and 5 verbs Frammenti needs to open, without a gender so the drill skips them. */
    private function unlock(User $user, Language $language): void
    {
        foreach (range(1, 5) as $i) {
            $this->noun($user, $language, 'substantiv'.$i, ['grammar_attributes' => null]);
            $this->noun($user, $language, 'verb'.$i, ['part_of_speech' => 'verb', 'grammar_attributes' => null]);
        }
    }

    private function save(User $user, Language $language, array $results)
    {
        return $this->actingAs($user)->post('/frammenti/articles?language_id='.$language->id, ['results' => json_encode($results)]);
    }

    public function test_right_answers_come_back_in_three_then_six_days_then_never_and_a_wrong_one_resets(): void
    {
        [$user, $language] = $this->learner();
        $hus = $this->noun($user, $language, 'hus');

        $this->save($user, $language, [['id' => $hus->id, 'result' => 1]])->assertRedirect(route('frammenti', ['language_id' => $language->id]));
        $this->assertSame([1, '2026-10-17'], [$hus->fresh()->article_level, $hus->fresh()->article_due_on]);

        $this->save($user, $language, [['id' => $hus->id, 'result' => 1]]);
        $this->assertSame([2, '2026-10-20'], [$hus->fresh()->article_level, $hus->fresh()->article_due_on]);

        $this->save($user, $language, [['id' => $hus->id, 'result' => 1]]);
        $this->assertSame([3, null], [$hus->fresh()->article_level, $hus->fresh()->article_due_on]);
        $this->assertSame(0, ArticleDrill::due($user, $language)->count());

        $bil = $this->noun($user, $language, 'bil', ['article_level' => 2, 'article_due_on' => '2026-10-14']);
        $this->save($user, $language, [['id' => $bil->id, 'result' => 0]]);
        $this->assertSame([0, '2026-10-14'], [$bil->fresh()->article_level, $bil->fresh()->article_due_on]);
        $this->assertSame(1, ArticleDrill::due($user, $language)->count());
    }

    public function test_saving_never_touches_the_frammenti_schedule_or_a_card(): void
    {
        [$user, $language] = $this->learner();
        $hus = $this->noun($user, $language, 'hus', ['frammenti_level' => 4, 'frammenti_due_on' => '2026-11-01']);
        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'level' => 3, 'next_study_at' => '2030-01-01 00:00:00']);
        $card->baseWords()->attach($hus->id);

        $this->save($user, $language, [['id' => $hus->id, 'result' => 1]]);

        $this->assertSame([4, '2026-11-01'], [$hus->fresh()->frammenti_level, $hus->fresh()->frammenti_due_on]);
        $this->assertSame(3, $card->fresh()->level);
    }

    public function test_another_learners_noun_is_ignored(): void
    {
        [$user, $language] = $this->learner();
        [$other] = $this->learner();
        $theirs = $this->noun($other, $language, 'hus');

        $this->save($user, $language, [['id' => $theirs->id, 'result' => 1]])->assertRedirect();

        $this->assertSame(0, $theirs->fresh()->article_level);
    }

    public function test_a_session_serves_new_and_due_gendered_nouns_in_most_cards_first_at_most_twenty(): void
    {
        [$user, $language] = $this->learner();
        $nouns = array_map(fn ($i) => $this->noun($user, $language, 'ord'.$i), range(1, 22));
        $popular = $this->noun($user, $language, 'skola', ['article_level' => 1, 'article_due_on' => '2026-10-13', 'grammar_attributes' => ['gender' => 'common']]);
        foreach (range(1, 2) as $i) {
            Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id])->baseWords()->attach($popular->id);
        }
        Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id])->baseWords()->attach($nouns[21]->id);

        $this->noun($user, $language, 'utan', ['grammar_attributes' => null]);
        $this->noun($user, $language, 'stor', ['part_of_speech' => 'adjective']);
        $this->noun($user, $language, 'klar', ['article_level' => 3]);
        $this->noun($user, $language, 'senare', ['article_level' => 1, 'article_due_on' => '2026-10-15']);

        $session = ArticleDrill::session($user, $language);

        $this->assertCount(ArticleDrill::SESSION, $session);
        $this->assertSame(['id' => $popular->id, 'front' => 'skola', 'back' => 'en skola', 'hint' => '', 'wordbox' => ''], $session[0]);
        $this->assertSame([$nouns[21]->id, $nouns[0]->id], [$session[1]['id'], $session[2]['id']]);
        $this->assertSame('ett ord1', $session[2]['back']);
        $this->assertSame(23, ArticleDrill::due($user, $language)->count());
    }

    public function test_the_panel_shows_for_a_language_with_articles_and_starts_the_flashcard_view(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->noun($user, $language, 'hus');

        $this->actingAs($user)->get('/frammenti')->assertOk()
            ->assertSee('id="articles"', false)->assertSee('<p>1 due</p>', false)
            ->assertSee(route('frammenti.articles', ['language_id' => $language->id]), false);

        $this->actingAs($user)->get(route('frammenti.articles', ['language_id' => $language->id]))->assertOk()
            ->assertSee('"front":"hus","back":"ett hus"', false)
            ->assertSee('action="'.route('frammenti.articles', ['language_id' => $language->id]).'"', false);
    }

    public function test_the_panel_has_no_start_with_nothing_due_and_hides_for_a_language_without_articles(): void
    {
        [$user, $language] = $this->learner();
        $this->unlock($user, $language);
        $this->noun($user, $language, 'hus', ['article_level' => 3]);

        $this->actingAs($user)->get('/frammenti')->assertSee('<p>0 due</p>', false)->assertDontSee('id="articlesBtn"', false);

        [$user, $italian] = $this->learner('it');
        $this->unlock($user, $italian);

        $this->actingAs($user)->get('/frammenti')->assertOk()->assertSee('id="intro"', false)->assertDontSee('id="articles"', false);
    }

    public function test_a_language_the_learner_does_not_learn_is_forbidden(): void
    {
        [$user] = $this->learner();
        [, $italian] = $this->learner('it');

        $this->actingAs($user)->get(route('frammenti.articles', ['language_id' => $italian->id]))->assertForbidden();
        $this->save($user, $italian, [])->assertForbidden();
    }

    public function test_card_review_still_posts_to_save_learning(): void
    {
        [$user, $language] = $this->learner();
        Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'next_study_at' => now()->subDay()]);

        $this->actingAs($user)->get('/startLearningSet/translation?language_id='.$language->id)
            ->assertOk()->assertSee('action="/saveLearning"', false);
    }
}
