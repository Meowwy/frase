<?php

namespace Tests\Feature;

use App\Models\BaseWord;
use App\Models\Card;
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
        $paths = ['/', '/staging', '/cards', '/base', '/refresher', '/add', '/setLearning'];

        foreach ($paths as $path) {
            $this->actingAs($this->user)->get($path)->assertStatus(200, $path.' did not render');
        }
    }

    public function test_the_card_detail_page_renders_a_word_card_with_its_anchor_and_base_words(): void
    {
        $card = Card::factory()->create([
            'user_id' => $this->user->id,
            'language_id' => $this->language->id,
            'term' => 'hus',
            'card_shape' => Card::SHAPE_WORD,
            'anchor' => 'ett stort [hus]',
            'anchor_translation' => 'a big house',
        ]);

        $baseWord = BaseWord::create([
            'user_id' => $this->user->id,
            'language_id' => $this->language->id,
            'lemma' => 'hus',
            'part_of_speech' => 'noun',
            'grammar_attributes' => ['gender' => 'neuter'],
            'translation' => 'house',
        ]);
        $card->baseWords()->attach($baseWord->id, ['surface_form' => 'hus']);

        $response = $this->actingAs($this->user)->get('/cards/'.$card->id);

        $response->assertStatus(200);
        $response->assertSee('a big house');
        // The base-word chip shows the display form, not the bare lemma.
        $response->assertSee('ett hus');
        $response->assertSee(Card::TYPE_LEXICAL);

        $this->actingAs($this->user)->get('/cards/edit/'.$card->id)->assertStatus(200);
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

    public function test_staging_renders_a_resolved_word_proposal_with_its_chip_and_anchor(): void
    {
        $proposal = $this->user->proposals()->create([
            'language_id' => $this->language->id,
            'raw_input' => 'hus',
            'term' => 'hus',
            'card_shape' => Card::SHAPE_WORD,
            'anchor' => 'ett stort [hus]',
            'status' => Proposal::STATUS_COMPLETED,
        ]);

        $proposal->baseWords()->create([
            'lemma' => 'hus',
            'part_of_speech' => 'noun',
            'grammar_attributes' => ['gender' => 'neuter'],
            'surface_form' => 'hus',
            'translation' => 'house',
        ]);

        $response = $this->actingAs($this->user)->get('/staging');

        $response->assertStatus(200);
        // The chip reads "ett hus", not bare "hus", and carries its part of speech.
        $response->assertSee('ett hus');
        $response->assertSee('noun');
        $response->assertSee('ett stort [hus]');
        $response->assertSee('Approve');
        $response->assertSee('js-strike', false);
    }

    /**
     * The already-present notice and the cards it expands to, which are resolved for the
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
        $card->baseWords()->attach($existing->id, ['surface_form' => 'kostar']);

        $proposal = $this->user->proposals()->create([
            'language_id' => $this->language->id,
            'raw_input' => 'hur mycket kostar det',
            'term' => 'hur mycket kostar det',
            'card_shape' => Card::SHAPE_PHRASE,
            'status' => Proposal::STATUS_COMPLETED,
        ]);

        // Same lemma, different part of speech — a different vocabulary item, not a match.
        foreach ([['kosta', 'verb'], ['kosta', 'noun']] as [$lemma, $partOfSpeech]) {
            $proposal->baseWords()->create([
                'lemma' => $lemma,
                'part_of_speech' => $partOfSpeech,
                'surface_form' => 'kostar',
                'translation' => 'to cost',
            ]);
        }

        $response = $this->actingAs($this->user)->get('/staging');

        $response->assertStatus(200);
        $response->assertSeeText('already in base');
        // The notice expands to the cards that word is already used in.
        $response->assertSee('vad kostar det');
        // Exactly one of the two chips matched.
        $this->assertSame(1, substr_count($response->getContent(), 'already in base'));
    }
}
