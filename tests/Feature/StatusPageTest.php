<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\LexiconEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The status page under the account settings: OpenAI reachability and what the lexicon holds.
 */
class StatusPageTest extends TestCase
{
    use RefreshDatabase;

    private function learner(): User
    {
        $user = User::factory()->create();
        $swedish = Language::firstOrCreate(['code' => 'sv'], ['name' => 'Swedish', 'native_name' => 'Svenska', 'flag' => '🇸🇪']);
        $german = Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'native_name' => 'Deutsch', 'flag' => '🇩🇪']);
        $user->languages()->attach([$swedish->id => ['users_level' => 'B1'], $german->id => ['users_level' => 'B1']]);

        return $user;
    }

    public function test_the_page_lists_what_the_lexicon_holds_per_language(): void
    {
        $user = $this->learner();
        LexiconEntry::insert([
            ['language_code' => 'sv', 'lemma' => 'hus', 'part_of_speech' => 'noun', 'gender' => 'neuter', 'dictionary_form' => null, 'paradigm' => 'x'],
            ['language_code' => 'sv', 'lemma' => 'plan', 'part_of_speech' => 'noun', 'gender' => null, 'dictionary_form' => null, 'paradigm' => 'x'],
            ['language_code' => 'sv', 'lemma' => 'komma', 'part_of_speech' => 'verb', 'gender' => null, 'dictionary_form' => 'komm|a -er', 'paradigm' => 'x'],
        ]);

        $this->actingAs($user)->get('/profile/status')
            ->assertOk()
            ->assertSee('Ready — 2 nouns (1 with a gender)', false)
            ->assertSee('1 verbs (1 with a dictionary form)', false)
            ->assertSee('Not loaded — the AI answers grammar for this language');
    }

    public function test_an_empty_lexicon_is_reported_as_not_loaded(): void
    {
        $this->actingAs($this->learner())->get('/profile/status')->assertOk()->assertDontSee('Ready');
    }

    public function test_openai_is_ok_when_the_model_record_comes_back(): void
    {
        config(['services.openai.secret' => 'sk-test']);
        Http::fake(['api.openai.com/v1/models/*' => Http::response(['id' => 'm'])]);

        $this->actingAs($this->learner())->getJson('/profile/status/openai')->assertJson(['ok' => true]);

        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_openai_problems_are_named(): void
    {
        config(['services.openai.secret' => 'sk-test']);
        $user = $this->learner();

        Http::fake(['api.openai.com/*' => Http::response([], 401)]);
        $this->actingAs($user)->getJson('/profile/status/openai')->assertJson(['ok' => false, 'message' => 'OpenAI rejected the API key.']);

        Http::fake(['api.openai.com/*' => fn () => throw new ConnectionException('down')]);
        $this->actingAs($user)->getJson('/profile/status/openai')->assertJson(['ok' => false, 'message' => 'OpenAI could not be reached.']);

        config(['services.openai.secret' => null]);
        $this->actingAs($user)->getJson('/profile/status/openai')->assertJson(['ok' => false]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/profile/status')->assertRedirect();
    }
}
