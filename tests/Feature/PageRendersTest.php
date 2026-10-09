<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\Card;
use App\Models\FixedExpression;
use App\Models\Language;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pages the redesign touched, rendered once each. The app is Blade-heavy and most of
 * these have no other test, so a broken template would otherwise only show up in a browser.
 */
class PageRendersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Language $language;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->language = Language::firstOrCreate(['code' => 'sv'], ['name' => 'Swedish', 'native_name' => 'Svenska', 'flag' => '🇸🇪']);
        $this->user->languages()->attach($this->language->id, ['users_level' => 'B1']);
        $this->user->update(['active_language_id' => $this->language->id]);
    }

    public function test_every_page_the_redesign_touched_renders(): void
    {
        $paths = ['/', '/staging', '/cards', '/base', '/frammenti', '/add', '/setLearning'];

        foreach ($paths as $path) {
            $this->actingAs($this->user)->get($path)->assertStatus(200, $path.' did not render');
        }
    }

    public function test_the_card_detail_page_renders_a_card_with_its_base_words(): void
    {
        $card = Card::factory()->create([
            'user_id' => $this->user->id,
            'language_id' => $this->language->id,
            'term' => 'hus',
            'translation' => 'a house',
        ]);

        $baseWord = BaseWord::create([
            'user_id' => $this->user->id,
            'language_id' => $this->language->id,
            'lemma' => 'hus',
            'part_of_speech' => 'noun',
            'grammar_attributes' => ['gender' => 'neuter'],
            'translation' => 'house',
        ]);
        $card->baseWords()->attach($baseWord->id);

        $response = $this->actingAs($this->user)->get('/cards/'.$card->id);

        $response->assertStatus(200);
        $response->assertSee('a house');
        // The base-word chip shows the display form, not the bare lemma.
        $response->assertSee('ett hus');

        $this->actingAs($this->user)->get('/cards/edit/'.$card->id)->assertStatus(200);
    }

    public function test_the_base_list_and_card_detail_show_fixed_expressions(): void
    {
        $card = Card::factory()->create(['user_id' => $this->user->id, 'language_id' => $this->language->id, 'term' => 'jag tycker om dig']);
        $expression = FixedExpression::create(['user_id' => $this->user->id, 'language_id' => $this->language->id, 'form' => 'tycka om', 'translation' => 'to like']);
        $card->fixedExpressions()->attach($expression->id);

        $this->actingAs($this->user)->get('/base')
            ->assertStatus(200)
            ->assertSee('tycka om')
            ->assertSee('to like')
            ->assertSee('jag tycker om dig');

        $this->actingAs($this->user)->get('/cards/'.$card->id)->assertSee('tycka om');
    }

    public function test_staging_renders_a_skeleton_for_a_proposal_call_one_has_not_reached_yet(): void
    {
        $this->user->proposals()->create([
            'raw_input' => 'kostar',
            'status' => Proposal::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->user)->get('/staging');

        $response->assertStatus(200);
        $response->assertSee('reading "kostar"', false);
    }

    public function test_staging_renders_a_resolved_proposal_with_its_chip(): void
    {
        $proposal = $this->user->proposals()->create([
            'language_id' => $this->language->id,
            'raw_input' => 'hus',
            'term' => 'hus',
            'status' => Proposal::STATUS_COMPLETED,
        ]);

        $proposal->baseWords()->create([
            'lemma' => 'hus',
            'part_of_speech' => 'noun',
            'grammar_attributes' => ['gender' => 'neuter'],
            'translation' => 'house',
        ]);

        $response = $this->actingAs($this->user)->get('/staging');

        $response->assertStatus(200);
        // The chip reads "ett hus", not bare "hus", and carries its part of speech.
        $response->assertSee('ett hus');
        $response->assertSee('noun');
        $response->assertSee('Approve');
        $response->assertSee('js-known', false);
    }

    /**
     * The already-present chip and the related cards read off it, which are resolved for the
     * whole list in one query (Proposal::presenceIndex) rather than per chip.
     */
    public function test_staging_flags_a_candidate_the_learner_already_has_in_the_base(): void
    {
        $existing = BaseWord::create([
            'user_id' => $this->user->id,
            'language_id' => $this->language->id,
            'lemma' => 'kosta',
            'part_of_speech' => 'verb',
            'translation' => 'to cost',
        ]);

        $card = Card::factory()->create([
            'user_id' => $this->user->id,
            'language_id' => $this->language->id,
            'term' => 'vad kostar det',
        ]);
        // Two shared words make it a related card; one shared verb alone would not.
        $det = BaseWord::create([
            'user_id' => $this->user->id,
            'language_id' => $this->language->id,
            'lemma' => 'det',
            'part_of_speech' => 'pronoun',
            'translation' => 'it',
        ]);
        $card->baseWords()->attach([$existing->id, $det->id]);

        $proposal = $this->user->proposals()->create([
            'language_id' => $this->language->id,
            'raw_input' => 'hur mycket kostar det',
            'term' => 'hur mycket kostar det',
            'status' => Proposal::STATUS_COMPLETED,
        ]);

        // Same lemma, different part of speech — a different vocabulary item, not a match.
        foreach ([['kosta', 'verb'], ['kosta', 'noun'], ['det', 'pronoun']] as [$lemma, $partOfSpeech]) {
            $proposal->baseWords()->create([
                'lemma' => $lemma,
                'part_of_speech' => $partOfSpeech,
                'translation' => 'to cost',
            ]);
        }

        $response = $this->actingAs($this->user)->get('/staging');

        $response->assertStatus(200);
        // The card that word is already used in is listed as a related card.
        $response->assertSee('vad kostar det');
        // kosta the noun did not match; kosta the verb and det did.
        $this->assertSame(2, substr_count($response->getContent(), 'js-in-base'));
    }
}
