<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\FixedExpression;
use App\Models\Language;
use App\Models\User;
use App\Models\Wordbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A wordbox groups base words of its own language: added from /base or the edit page, taken
 * out on its own page, listed in the vocabulary base's table.
 */
class WordboxWordsTest extends TestCase
{
    use RefreshDatabase;

    private function learner(): array
    {
        $user = User::factory()->create();
        $language = Language::firstOrCreate(['code' => 'sv'], ['name' => 'Swedish', 'native_name' => 'Svenska', 'flag' => '🇸🇪']);
        $user->languages()->attach($language->id, ['users_level' => 'B1']);
        $user->update(['active_language_id' => $language->id]);
        $wordbox = Wordbox::factory()->create(['user_id' => $user->id, 'language_id' => $language->id, 'name' => 'Resor']);

        return [$user, $language, $wordbox];
    }

    private function word(User $user, Language $language, string $lemma): BaseWord
    {
        return BaseWord::create(['user_id' => $user->id, 'language_id' => $language->id, 'lemma' => $lemma, 'part_of_speech' => 'noun', 'translation' => 'en: '.$lemma]);
    }

    public function test_checked_words_are_added_from_the_base_once(): void
    {
        [$user, $language, $wordbox] = $this->learner();
        $hus = $this->word($user, $language, 'hus');
        $bil = $this->word($user, $language, 'bil');

        $this->actingAs($user)->postJson("/wordbox/{$wordbox->id}/words/add", ['ids' => [$hus->id]])
            ->assertOk()->assertJson(['added' => 1, 'wordbox' => 'Resor']);
        $this->actingAs($user)->postJson("/wordbox/{$wordbox->id}/words/add", ['ids' => [$hus->id, $bil->id]])->assertOk();

        $this->assertEqualsCanonicalizing([$hus->id, $bil->id], $wordbox->baseWords()->pluck('base_words.id')->all());
        $this->assertDatabaseCount('base_word_wordbox', 2);
    }

    /**
     * Only the learner's own words of the wordbox's language go in.
     */
    public function test_words_of_another_language_or_learner_are_rejected(): void
    {
        [$user, $language, $wordbox] = $this->learner();
        $english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'flag' => '🇬🇧']);
        $user->languages()->attach($english->id);
        $house = $this->word($user, $english, 'house');
        [$other] = $this->learner();
        $theirs = $this->word($other, $language, 'katt');

        $this->actingAs($user)->postJson("/wordbox/{$wordbox->id}/words/add", ['ids' => [$house->id]])->assertStatus(422);
        $this->actingAs($user)->postJson("/wordbox/{$wordbox->id}/words/add", ['ids' => [$theirs->id]])->assertStatus(422);
        $this->actingAs($other)->postJson("/wordbox/{$wordbox->id}/words/add", ['ids' => [$theirs->id]])->assertNotFound();
        $this->assertDatabaseCount('base_word_wordbox', 0);
    }

    public function test_the_edit_page_saves_the_whole_list_and_the_show_page_removes_words(): void
    {
        [$user, $language, $wordbox] = $this->learner();
        $hus = $this->word($user, $language, 'hus');
        $bil = $this->word($user, $language, 'bil');
        $wordbox->baseWords()->attach($hus->id);

        $this->actingAs($user)->post(route('wordbox.words', $wordbox->id), ['ids' => [$bil->id]])
            ->assertRedirect(route('wordbox.show', ['id' => $wordbox->id]));
        $this->assertSame([$bil->id], $wordbox->baseWords()->pluck('base_words.id')->all());

        $this->actingAs($user)->postJson("/wordbox/{$wordbox->id}/words/remove", ['ids' => [$bil->id]])->assertNoContent();
        $this->assertSame(0, $wordbox->baseWords()->count());
        $this->assertSame(2, $user->baseWords()->count());
    }

    public function test_the_edit_search_finds_only_words_of_the_wordboxs_language(): void
    {
        [$user, $language, $wordbox] = $this->learner();
        $this->word($user, $language, 'hus');
        $english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'flag' => '🇬🇧']);
        $this->word($user, $english, 'husband');

        $this->actingAs($user)->getJson(route('wordbox.search', $wordbox->id).'?searchTerm=hus')
            ->assertOk()->assertJsonCount(1, 'words')->assertJsonPath('words.0.form', 'hus');
    }

    /**
     * The wordbox page lists the box's words in the base's table, a deleted one left out, and
     * offers them to the gap-fill checklist.
     */
    public function test_the_wordbox_page_lists_its_words_in_the_base_table(): void
    {
        [$user, $language, $wordbox] = $this->learner();
        $hus = $this->word($user, $language, 'hus');
        $gone = $this->word($user, $language, 'borta');
        $this->word($user, $language, 'bil');
        FixedExpression::create(['user_id' => $user->id, 'language_id' => $language->id, 'form' => 'tycka om', 'translation' => 'to like']);
        $wordbox->baseWords()->attach([$hus->id, $gone->id]);
        $gone->update(['deleted' => true]);

        $html = $this->actingAs($user)->get(route('wordbox.show', ['id' => $wordbox->id]))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="js-base-checkbox'));
        $this->assertStringContainsString('data-key="word:'.$hus->id.'"', $html);
        $this->assertStringContainsString('name="base_word_ids[]" value="'.$hus->id.'"', $html);
        $this->assertStringNotContainsString('tycka om', $html);

        $rows = $this->actingAs($user)->getJson(route('wordbox.show', ['id' => $wordbox->id]).'?search=bi', ['X-Requested-With' => 'XMLHttpRequest'])->json('rows');
        $this->assertStringContainsString('Nothing matches.', $rows);
    }

    public function test_the_base_offers_the_wordboxes_of_its_language(): void
    {
        [$user, $language] = $this->learner();
        $this->word($user, $language, 'hus');
        $english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'flag' => '🇬🇧']);
        Wordbox::factory()->create(['user_id' => $user->id, 'language_id' => $english->id, 'name' => 'Travel']);

        $this->actingAs($user)->get('/base')->assertOk()->assertSee('Resor')->assertDontSee('Travel');
    }
}
