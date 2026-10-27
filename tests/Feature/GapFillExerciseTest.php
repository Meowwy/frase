<?php

namespace Tests\Feature;

use App\Jobs\GenerateGapFillJob;
use App\Models\BaseWord;
use App\Models\GapFillExercise;
use App\Models\Language;
use App\Models\User;
use App\Models\Wordbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GapFillExerciseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A Swedish wordbox holding the given nouns (lemma => gender).
     *
     * @return array{0: User, 1: Wordbox, 2: array<int, BaseWord>}
     */
    private function wordbox(array $nouns = ['hus' => 'neuter', 'bil' => 'common']): array
    {
        $user = User::factory()->create();
        $language = Language::firstOrCreate(['code' => 'sv'], ['name' => 'Swedish', 'native_name' => 'Svenska', 'flag' => '🇸🇪']);
        $user->languages()->attach($language->id, ['users_level' => 'B1']);
        $wordbox = Wordbox::factory()->create(['user_id' => $user->id, 'language_id' => $language->id]);

        $words = [];
        foreach ($nouns as $lemma => $gender) {
            $words[] = $word = BaseWord::create([
                'user_id' => $user->id, 'language_id' => $language->id, 'lemma' => $lemma,
                'part_of_speech' => 'noun', 'grammar_attributes' => ['gender' => $gender], 'translation' => 'en: '.$lemma,
            ]);
            $wordbox->baseWords()->attach($word->id);
        }

        return [$user, $wordbox, $words];
    }

    public function test_user_can_trigger_gap_fill_generation_over_ticked_words(): void
    {
        Queue::fake();
        [$user, $wordbox, [$hus]] = $this->wordbox();

        $response = $this->actingAs($user)->get(route('gapfill.generate', $wordbox->id).'?'.http_build_query([
            'base_word_ids' => [$hus->id],
            'theme_preference' => 'Travel',
        ]));

        $response->assertStatus(302);
        $exercise = GapFillExercise::sole();
        $this->assertSame([$hus->id], $exercise->base_word_ids);
        $this->assertSame('Travel', $exercise->theme_preference);
        $this->assertSame('pending', $exercise->status);

        Queue::assertPushed(GenerateGapFillJob::class);
    }

    /**
     * Only the wordbox's own words can be ticked, and at most 25 of them.
     */
    public function test_generation_rejects_words_outside_the_wordbox_and_more_than_twenty_five(): void
    {
        Queue::fake();
        [$user, $wordbox] = $this->wordbox();
        $outside = BaseWord::create(['user_id' => $user->id, 'language_id' => $wordbox->language_id, 'lemma' => 'katt', 'part_of_speech' => 'noun', 'translation' => 'cat']);

        $generate = fn (array $ids) => $this->actingAs($user)->get(route('gapfill.generate', $wordbox->id).'?'.http_build_query(['base_word_ids' => $ids]));

        $generate([$outside->id])->assertSessionHasErrors('base_word_ids.0');
        $generate(range(1, 26))->assertSessionHasErrors('base_word_ids');
        $generate([])->assertSessionHasErrors('base_word_ids');
        Queue::assertNotPushed(GenerateGapFillJob::class);
    }

    /**
     * The words go to the AI as lemmas with their data, and the answers come back keyed by
     * placeholder, each with the form used, every accepted form and its word.
     */
    public function test_the_job_sends_lemmas_and_stores_each_gaps_forms(): void
    {
        [, $wordbox, [$hus, $bil]] = $this->wordbox();
        $exercise = $wordbox->gapFillExercises()->create(['base_word_ids' => [$hus->id, $bil->id], 'status' => 'pending']);

        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'title' => 'En resa',
            'text' => 'Vi såg [1] och körde [2].',
            'answers' => [
                ['index' => 1, 'base_word_id' => $hus->id, 'phrase' => 'husen', 'accepted' => ['husen']],
                ['index' => 2, 'base_word_id' => $bil->id, 'phrase' => 'bilen', 'accepted' => ['bilen', 'bilarna']],
            ],
        ])]]]])]);

        (new GenerateGapFillJob($exercise))->handle();

        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'], '"hus" (noun, gender: neuter, dictionary entry "ett hus") = "en: hus"'));
        $exercise->refresh();
        $this->assertSame('completed', $exercise->status);
        $this->assertSame('En resa', $exercise->title);
        $this->assertSame([
            1 => ['phrase' => 'husen', 'accepted' => ['husen'], 'base_word_id' => $hus->id],
            2 => ['phrase' => 'bilen', 'accepted' => ['bilen', 'bilarna'], 'base_word_id' => $bil->id],
        ], $exercise->correct_answers);
    }

    public function test_the_job_fails_an_exercise_whose_answers_dont_match_its_placeholders(): void
    {
        [, $wordbox, [$hus]] = $this->wordbox();
        $exercise = $wordbox->gapFillExercises()->create(['base_word_ids' => [$hus->id], 'status' => 'pending']);

        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'title' => 'Ett hus',
            'text' => 'Vi såg [1] och [2].',
            'answers' => [['index' => 1, 'base_word_id' => $hus->id, 'phrase' => 'huset', 'accepted' => []]],
        ])]]]])]);

        (new GenerateGapFillJob($exercise))->handle();

        $this->assertSame('failed', $exercise->fresh()->status);
    }

    public function test_user_can_view_pending_exercise(): void
    {
        // gap-fill.show renders the pending/processing state in-page (with client-side
        // polling) rather than a separate view — see docs/gap-fill.md.
        [$user, $wordbox] = $this->wordbox();
        $exercise = GapFillExercise::create([
            'wordbox_id' => $wordbox->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('gap-fill.show', $exercise));

        $response->assertStatus(200);
        $response->assertViewIs('gap-fill.show');
    }

    /**
     * The bank shows each gap's word as it was learnt, not the form the gap needs.
     */
    public function test_user_can_view_completed_exercise_with_its_words_in_the_bank(): void
    {
        [$user, $wordbox, [$hus]] = $this->wordbox();
        $exercise = GapFillExercise::create([
            'wordbox_id' => $wordbox->id,
            'status' => 'completed',
            'text_with_gaps' => 'Hello [1]',
            'correct_answers' => [1 => ['phrase' => 'husen', 'accepted' => ['husen'], 'base_word_id' => $hus->id]],
        ]);

        $response = $this->actingAs($user)->get(route('gap-fill.show', $exercise));

        $response->assertStatus(200);
        $response->assertViewIs('gap-fill.show');
        $response->assertSee('Hello');
        $response->assertSee('ett hus');
        $response->assertSee('title="noun · ett hus"', false);
    }

    public function test_status_endpoint_returns_json(): void
    {
        [$user, $wordbox] = $this->wordbox();
        $exercise = GapFillExercise::create([
            'wordbox_id' => $wordbox->id,
            'status' => 'processing',
        ]);

        $response = $this->actingAs($user)->get(route('gap-fill.status', $exercise));

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'processing',
            'url' => route('gap-fill.show', $exercise),
        ]);
    }
}
