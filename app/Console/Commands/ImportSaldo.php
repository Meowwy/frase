<?php

namespace App\Console\Commands;

use App\Models\LexiconEntry;
use App\Support\Lexicon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use XMLReader;

/**
 * Loads Swedish noun genders and verb dictionary forms from SALDO's morphology
 * (Språkbanken, CC BY 4.0) into `lexicon_entries`. Download saldom.xml from
 * https://svn.spraakbanken.gu.se/sb-arkiv/pub/lmf/saldom/saldom.xml — it is ~250 MB and
 * never committed. Re-running replaces the Swedish rows. See docs/cards.md "The lexicon".
 */
class ImportSaldo extends Command
{
    protected $signature = 'lexicon:import-saldo {path : Path to saldom.xml}';

    protected $description = 'Import Swedish noun genders and verb forms from SALDO into the lexicon';

    private const CHUNK = 1000;

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("No file at {$path}");

            return self::FAILURE;
        }

        $reader = new XMLReader;
        $reader->open($path);

        $rows = [];
        $count = 0;

        DB::transaction(function () use ($reader, &$rows, &$count) {
            LexiconEntry::where('language_code', 'sv')->delete();

            // Skip straight from one entry to the next, so only one entry is ever in memory.
            while ($reader->read() && $reader->name !== 'LexicalEntry');

            while ($reader->name === 'LexicalEntry') {
                $row = $this->rowFor(simplexml_load_string($reader->readOuterXml()));

                if ($row) {
                    $rows[] = $row;
                    $count++;
                }

                if (count($rows) >= self::CHUNK) {
                    LexiconEntry::insert($rows);
                    $rows = [];
                }

                $reader->next('LexicalEntry');
            }

            LexiconEntry::insert($rows);
        });

        $reader->close();

        $this->info("Imported {$count} Swedish lexicon entries.");

        return self::SUCCESS;
    }

    /**
     * One noun or verb entry as a `lexicon_entries` row, or null for anything else —
     * other parts of speech and multiword entries ("komma ihåg") carry nothing we read.
     */
    private function rowFor(\SimpleXMLElement $entry): ?array
    {
        $lemma = [];

        foreach ($entry->Lemma->FormRepresentation->feat as $feat) {
            $lemma[(string) $feat['att']] = (string) $feat['val'];
        }

        $word = $lemma['writtenForm'] ?? '';
        $paradigm = $lemma['paradigm'] ?? '';

        if ($word === '' || str_contains($word, ' ')) {
            return null;
        }

        return match ($lemma['partOfSpeech'] ?? null) {
            'nn' => $this->row($word, 'noun', $paradigm, gender: $this->genderFor($paradigm)),
            'vb' => $this->row($word, 'verb', $paradigm, dictionaryForm: $this->dictionaryFormFor($word, $entry)),
            default => null,
        };
    }

    private function row(string $lemma, string $partOfSpeech, string $paradigm, ?string $gender = null, ?string $dictionaryForm = null): array
    {
        return [
            'language_code' => 'sv',
            'lemma' => $lemma,
            'part_of_speech' => $partOfSpeech,
            'gender' => $gender,
            'dictionary_form' => $dictionaryForm,
            'paradigm' => $paradigm,
        ];
    }

    /**
     * SALDO writes a noun's gender as the last letter of its inflection class: `nn_6n_hus`
     * is neuter, `nn_2u_bil` and `nn_iu_…` common ("utrum"). `v` (either article is
     * fine) and `p` (plural only) have no single gender, so they stay null.
     */
    private function genderFor(string $paradigm): ?string
    {
        $class = explode('_', $paradigm)[1] ?? '';

        return match (substr($class, -1)) {
            'u' => 'common',
            'n' => 'neuter',
            default => null,
        };
    }

    /**
     * Built from the first active present-tense form SALDO lists ("kommer"). A verb with
     * none — the s-verbs like "hoppas" — stays null and CALL 1's answer is kept instead.
     */
    private function dictionaryFormFor(string $infinitive, \SimpleXMLElement $entry): ?string
    {
        foreach ($entry->WordForm as $form) {
            $features = [];

            foreach ($form->feat as $feat) {
                $features[(string) $feat['att']] = (string) $feat['val'];
            }

            if (($features['msd'] ?? null) === 'pres ind aktiv') {
                return Lexicon::swedishDictionaryForm($infinitive, $features['writtenForm']);
            }
        }

        return null;
    }
}
