<?php

namespace Tests\Feature;

use App\Models\LexiconEntry;
use App\Support\Lexicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SALDO import and the Swedish dictionary-form rule it applies — see docs/cards.md
 * "The lexicon".
 */
class LexiconImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_swedish_dictionary_forms_follow_the_guideline_rule(): void
    {
        $cases = [
            ['tala', 'talar', 'tal|a -ar'],
            ['komma', 'kommer', 'komm|a -er'],
            ['köpa', 'köper', 'köp|a -er'],
            ['bo', 'bor', 'bo -r'],
            ['gå', 'går', 'gå -r'],
            ['ha', 'har', 'ha -r'],
            ['vara', 'är', 'var|a är'],
            ['kunna', 'kan', 'kunn|a kan'],
            ['höra', 'hör', 'hör|a hör'],
        ];

        foreach ($cases as [$infinitive, $present, $expected]) {
            $this->assertSame($expected, Lexicon::swedishDictionaryForm($infinitive, $present), $infinitive);
        }
    }

    public function test_the_import_keeps_noun_genders_and_verb_forms_and_can_be_rerun(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'saldo');
        file_put_contents($path, <<<'XML'
            <LexicalResource><Lexicon>
            <LexicalEntry><Lemma><FormRepresentation>
              <feat att="writtenForm" val="hus"/><feat att="partOfSpeech" val="nn"/><feat att="paradigm" val="nn_6n_system"/>
            </FormRepresentation></Lemma></LexicalEntry>
            <LexicalEntry><Lemma><FormRepresentation>
              <feat att="writtenForm" val="bil"/><feat att="partOfSpeech" val="nn"/><feat att="paradigm" val="nn_2u_sten"/>
            </FormRepresentation></Lemma></LexicalEntry>
            <LexicalEntry><Lemma><FormRepresentation>
              <feat att="writtenForm" val="komma"/><feat att="partOfSpeech" val="vb"/><feat att="paradigm" val="vb_4a_komma"/>
            </FormRepresentation></Lemma>
              <WordForm><feat att="writtenForm" val="koms"/><feat att="msd" val="pres ind s-form"/></WordForm>
              <WordForm><feat att="writtenForm" val="kommer"/><feat att="msd" val="pres ind aktiv"/></WordForm>
            </LexicalEntry>
            <LexicalEntry><Lemma><FormRepresentation>
              <feat att="writtenForm" val="stor"/><feat att="partOfSpeech" val="av"/><feat att="paradigm" val="av_1_gul"/>
            </FormRepresentation></Lemma></LexicalEntry>
            <LexicalEntry><Lemma><FormRepresentation>
              <feat att="writtenForm" val="komma ihåg"/><feat att="partOfSpeech" val="vbm"/><feat att="paradigm" val="vbm_x"/>
            </FormRepresentation></Lemma></LexicalEntry>
            </Lexicon></LexicalResource>
            XML);

        $this->artisan('lexicon:import-saldo', ['path' => $path])->assertSuccessful();
        $this->artisan('lexicon:import-saldo', ['path' => $path])->assertSuccessful();

        $this->assertSame(3, LexiconEntry::count());
        $this->assertSame('neuter', LexiconEntry::where('lemma', 'hus')->sole()->gender);
        $this->assertSame('common', LexiconEntry::where('lemma', 'bil')->sole()->gender);
        $this->assertSame('komm|a -er', LexiconEntry::where('lemma', 'komma')->sole()->dictionary_form);

        unlink($path);
    }
}
