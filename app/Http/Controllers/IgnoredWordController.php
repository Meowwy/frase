<?php

namespace App\Http\Controllers;

use App\Models\IgnoredWord;
use App\Models\Language;
use App\Models\LexiconEntry;
use App\Support\LanguageGuideline;
use App\Support\Lexicon;
use App\Support\WordDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * ORGANIZE: the ignored-words page, one language at a time. Lists what the learner struck in
 * staging or marked as ignored when deleting from /base, lets them take a word off the list
 * (it becomes proposable again) and, for a language with a lexicon, ignore a word up front.
 * See docs/cards.md "Ignored words".
 */
class IgnoredWordController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $requested = $request->query('language_id');
        $language = ($requested ? $user->languages()->whereKey($requested)->first() : null) ?? $user->currentSaveLanguage();

        $words = IgnoredWord::where('user_id', $user->id)
            ->where('language_id', $language?->id)
            ->orderBy('lemma')
            ->orderBy('part_of_speech')
            ->paginate(50)
            ->appends($request->query());

        return view('base.ignored', [
            'words' => $words,
            'displayForms' => $this->displayForms($language, $words->getCollection()),
            'targetLanguages' => $user->languages()->orderBy('name')->get(),
            'activeLanguageId' => $language?->id,
            'hasLexicon' => Lexicon::has($language?->code),
        ]);
    }

    /**
     * Ignore a word picked from the lexicon — only the lexicon's words can be added by hand,
     * so the lemma and part of speech are exactly what CALL 1 would name. Ignoring one twice
     * changes nothing.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'language_id' => ['required', 'integer'],
            'lemma' => ['required', 'string', 'max:100'],
            'part_of_speech' => ['required', 'string', Rule::in(LanguageGuideline::PARTS_OF_SPEECH)],
        ]);

        $language = $user->languages()->whereKey($data['language_id'])->firstOrFail();

        abort_unless(
            LexiconEntry::where(['language_code' => $language->code, 'lemma' => $data['lemma'], 'part_of_speech' => $data['part_of_speech']])->exists(),
            422,
            'That word is not in the lexicon.'
        );

        $attributes = ['user_id' => $user->id, 'language_id' => $language->id, 'part_of_speech' => $data['part_of_speech']];

        if (! IgnoredWord::where($attributes)->whereLemma($data['lemma'])->exists()) {
            IgnoredWord::create($attributes + ['lemma' => $data['lemma']]);
        }

        return response()->noContent();
    }

    /**
     * Take a word off the list: staging proposes it again.
     */
    public function destroy(IgnoredWord $ignoredWord)
    {
        abort_unless($ignoredWord->user_id === Auth::id(), 404);

        $ignoredWord->delete();

        return response()->noContent();
    }

    /**
     * Each listed word as the base would show it: an ignored word stores no grammar, so the
     * article or dictionary form comes from what the lexicon is sure of, else the bare lemma.
     *
     * @return array<int, string> keyed by ignored word id
     */
    private function displayForms(?Language $language, $words): array
    {
        $guideline = LanguageGuideline::for($language?->code);

        if (! $guideline) {
            return $words->mapWithKeys(fn (IgnoredWord $word) => [$word->id => $word->lemma])->all();
        }

        $lexicon = Lexicon::lookup($language->code, $words->map(fn (IgnoredWord $word) => $word->only('lemma', 'part_of_speech'))->all());

        return $words->mapWithKeys(function (IgnoredWord $word) use ($guideline, $lexicon) {
            $sure = array_map(fn (array $options) => count($options) === 1 ? $options : [], $lexicon[WordDetails::lexiconKey($word->lemma, $word->part_of_speech)] ?? []);
            $details = WordDetails::grammar($guideline, $word->part_of_speech, [], $sure);

            return [$word->id => $guideline->displayForm($word->lemma, $word->part_of_speech, $details['grammar_attributes'], $details['dictionary_form'])];
        })->all();
    }
}
