<?php

namespace Tests\Feature;

use App\Jobs\GenerateEmbeddingJob;
use App\Models\Card;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Capturing a term the learner already has: nothing is saved, and the dashboard says so
 * in a dialog offering "Regenerate".
 *
 * The duplicate check runs twice — once on the term as typed, once on the term call 1
 * corrects it to — so the OpenAI calls are faked here rather than skipped.
 */
class DuplicateCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function userWithLanguage(): array
    {
        $user = User::factory()->create();
        $language = Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'native_name' => 'Deutsch', 'flag' => '🇩🇪']);
        $user->languages()->attach($language->id, ['users_level' => 'B1']);

        return [$user, $language];
    }

    /**
     * Queue every JSON body the OpenAI chat endpoint should answer with in this test, in
     * call order. The sequence throws once exhausted, so an unexpected extra call fails
     * loudly. Call this ONCE per test: Http::fake() appends stubs rather than replacing
     * them, so a second call would never be reached.
     */
    private function fakeOpenAi(array ...$bodies): void
    {
        $sequence = Http::sequence();

        foreach ($bodies as $body) {
            $sequence->push(['choices' => [['message' => ['content' => json_encode($body)]]]]);
        }

        Http::fake(['api.openai.com/v1/chat/completions' => $sequence]);
    }

    private function analysis(string $phrase, string $kind = 'word', string $word = '', string $submittedForm = ''): array
    {
        return ['card_kind' => $kind, 'phrase' => $phrase, 'word' => $word, 'submitted_form' => $submittedForm];
    }

    private function cardContent(string $translation = 'reliable'): array
    {
        return [
            'translation' => $translation,
            'definition' => 'A freshly generated definition.',
            'sentence' => 'A freshly generated [sentence].',
            'examples' => ['one example', 'another example'],
        ];
    }

    public function test_capturing_a_duplicate_flashes_the_existing_card_and_saves_nothing(): void
    {
        [$user, $language] = $this->userWithLanguage();
        Http::fake(); // Nothing should reach OpenAI: the typed term is caught first.
        $card = Card::factory()->create([
            'user_id' => $user->id,
            'language_id' => $language->id,
            'phrase' => 'zuverlässig',
        ]);

        // The match is case-insensitive, so a differently-cased retype is still a duplicate.
        $response = $this->actingAs($user)->post('/captureWordAjax', [
            'capturedWord' => 'Zuverlässig',
            'context' => '',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('duplicate_capture', [
            'id' => $card->id,
            'phrase' => 'zuverlässig',
            'term' => 'Zuverlässig',
            'context' => null,
        ]);
        $this->assertSame(1, $user->cards()->count());
        Http::assertNothingSent();
    }

    /**
     * `word` holds the form the learner actually met the term in, which is the form they
     * type back into the capture box — so a phrase card built around it is already their
     * card for that word.
     */
    public function test_a_term_matching_an_existing_cards_focus_word_is_a_duplicate(): void
    {
        [$user, $language] = $this->userWithLanguage();
        Http::fake();
        $card = Card::factory()->create([
            'user_id' => $user->id,
            'language_id' => $language->id,
            'phrase' => 'Kandidaten überprüfen',
            'word' => 'überprüfen',
        ]);

        $response = $this->actingAs($user)->post('/captureWordAjax', ['capturedWord' => 'Überprüfen']);

        $response->assertRedirect('/');
        $this->assertSame($card->id, session('duplicate_capture')['id']);
        $this->assertSame(1, $user->cards()->count());
        Http::assertNothingSent();
    }

    /**
     * The point of the second check: a typo or an inflected form is a different string
     * from every card the learner has until call 1 corrects it.
     */
    public function test_a_term_the_ai_corrects_into_an_existing_card_is_a_duplicate(): void
    {
        [$user, $language] = $this->userWithLanguage();
        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'phrase' => 'vet']);
        $this->fakeOpenAi($this->analysis('vet', submittedForm: 'vetting'));

        $response = $this->actingAs($user)->post('/captureWordAjax', ['capturedWord' => 'vettting']);

        $response->assertRedirect('/');
        $response->assertSessionHas('duplicate_capture', [
            'id' => $card->id,
            'phrase' => 'vet',
            'term' => 'vettting',
            'context' => null,
        ]);
        $this->assertSame(1, $user->cards()->count());
        // Call 2 is never spent on a card that isn't going to be written.
        Http::assertSentCount(1);
    }

    public function test_regenerating_reuses_call_one_instead_of_paying_for_it_again(): void
    {
        [$user, $language] = $this->userWithLanguage();
        Queue::fake();
        $card = Card::factory()->create([
            'user_id' => $user->id,
            'language_id' => $language->id,
            'phrase' => 'vet',
            'level' => 7,
            'note' => 'my own note',
            'translation' => 'OLD',
        ]);
        // Exactly two calls are budgeted: call 1 for the capture, call 2 for the
        // regeneration. If the regeneration re-ran call 1 it would be handed call 2's
        // body, which carries no `phrase`, and the endpoint would fail instead.
        $this->fakeOpenAi($this->analysis('vet', submittedForm: 'vetting'), $this->cardContent('prověřit'));

        $this->actingAs($user)->post('/captureWordAjax', ['capturedWord' => 'vettting']);
        $response = $this->actingAs($user)->postJson("/cards/{$card->id}/regenerate");

        $response->assertStatus(200)->assertJson(['redirect' => "/cards/{$card->id}"]);
        Http::assertSentCount(2);

        $card->refresh();
        $this->assertSame('prověřit', $card->translation);
        $this->assertSame('A freshly generated definition.', $card->definition);
        // Nothing the learner built up around the card is touched.
        $this->assertSame(7, $card->level);
        $this->assertSame('my own note', $card->note);
        Queue::assertPushed(GenerateEmbeddingJob::class);
    }

    /**
     * The stashed analysis is consumed on use, so a later regeneration of the same card
     * falls back to the full pipeline rather than silently reusing a stale call 1.
     */
    public function test_the_stashed_analysis_is_only_good_once(): void
    {
        [$user, $language] = $this->userWithLanguage();
        Queue::fake();
        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'phrase' => 'vet']);

        // Capture (call 1), first regeneration (call 2 only — the stash is used), then a
        // second regeneration that has to pay for both calls again.
        $this->fakeOpenAi(
            $this->analysis('vet', submittedForm: 'vetting'),
            $this->cardContent(),
            $this->analysis('vet'),
            $this->cardContent('spolehlivý'),
        );

        $this->actingAs($user)->post('/captureWordAjax', ['capturedWord' => 'vettting']);
        $this->actingAs($user)->postJson("/cards/{$card->id}/regenerate")->assertStatus(200);
        $this->actingAs($user)->postJson("/cards/{$card->id}/regenerate")->assertStatus(200);

        Http::assertSentCount(4);
        $this->assertSame('spolehlivý', $card->refresh()->translation);
    }

    public function test_a_term_that_is_not_a_duplicate_is_saved(): void
    {
        [$user, $language] = $this->userWithLanguage();
        Queue::fake();
        Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'phrase' => 'vet']);
        $this->fakeOpenAi($this->analysis('pünktlich'), $this->cardContent('punctual'));

        $response = $this->actingAs($user)->post('/captureWordAjax', ['capturedWord' => 'pünktlich']);

        $response->assertRedirect('/');
        $response->assertSessionMissing('duplicate_capture');
        $this->assertSame(2, $user->cards()->count());
        $this->assertSame('punctual', $user->cards()->where('phrase', 'pünktlich')->first()->translation);
    }

    public function test_the_same_term_in_another_language_is_not_a_duplicate(): void
    {
        [$user, $language] = $this->userWithLanguage();
        Queue::fake();
        $french = Language::firstOrCreate(['code' => 'fr'], ['name' => 'French', 'native_name' => 'Français', 'flag' => '🇫🇷']);
        Card::factory()->create(['user_id' => $user->id, 'language_id' => $french->id, 'phrase' => 'kritisch']);
        $this->fakeOpenAi($this->analysis('kritisch'), $this->cardContent('critical'));

        $this->actingAs($user)->post('/captureWordAjax', ['capturedWord' => 'kritisch']);

        $this->assertNull(session('duplicate_capture'));
        $this->assertSame(1, $user->cards()->where('language_id', $language->id)->count());
    }

    public function test_the_capture_context_is_carried_into_the_flash(): void
    {
        [$user, $language] = $this->userWithLanguage();
        Http::fake();
        Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'phrase' => 'zuverlässig']);

        $this->actingAs($user)->post('/captureWordAjax', [
            'capturedWord' => 'zuverlässig',
            'context' => 'a reliable colleague',
        ]);

        $this->assertSame('a reliable colleague', session('duplicate_capture')['context']);
    }

    public function test_an_api_style_caller_still_gets_a_409(): void
    {
        [$user, $language] = $this->userWithLanguage();
        Http::fake();
        Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'phrase' => 'zuverlässig']);

        $response = $this->actingAs($user)
            ->postJson('/captureWordAjax', ['capturedWord' => 'zuverlässig']);

        $response->assertStatus(409);
    }

    public function test_the_dashboard_renders_the_duplicate_dialog_from_the_flash(): void
    {
        [$user, $language] = $this->userWithLanguage();
        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'phrase' => 'vet']);

        $response = $this->actingAs($user)
            ->withSession(['duplicate_capture' => [
                'id' => $card->id, 'phrase' => 'vet', 'term' => 'vet', 'context' => null,
            ]])
            ->get('/');

        $response->assertStatus(200);
        $response->assertSee('id="modal-duplicate-term"', false);
        $response->assertSee("openModal('duplicate-term')", false);
        $response->assertSee('/cards/'.$card->id.'/regenerate', false);
        $response->assertSee('Regenerate');
        $response->assertSee('Cancel');
    }

    /**
     * When the AI resolved the typed term to a different one, the dialog has to name both
     * or the learner can't tell why their input came back as a duplicate.
     */
    public function test_the_dialog_names_the_typed_term_when_it_was_corrected(): void
    {
        [$user, $language] = $this->userWithLanguage();
        $card = Card::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'phrase' => 'vet']);

        $response = $this->actingAs($user)
            ->withSession(['duplicate_capture' => [
                'id' => $card->id, 'phrase' => 'vet', 'term' => 'vettting', 'context' => null,
            ]])
            ->get('/');

        $response->assertSeeInOrder(['vettting', 'vet'], false);
    }

    public function test_the_dashboard_has_no_dialog_without_the_flash(): void
    {
        [$user] = $this->userWithLanguage();

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('id="modal-duplicate-term"', false);
    }

    public function test_a_user_cannot_regenerate_someone_elses_card(): void
    {
        [$owner] = $this->userWithLanguage();
        $other = User::factory()->create();
        $card = Card::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->postJson("/cards/{$card->id}/regenerate");

        $response->assertStatus(403);
    }
}
