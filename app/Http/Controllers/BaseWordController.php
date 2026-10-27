<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsBaseEntries;
use App\Models\BaseWord;
use App\Models\FixedExpression;
use App\Models\KnownWord;
use App\Support\LanguageGuideline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * ORGANIZE: the vocabulary base's own page.
 *
 * The base is one entry per lemma and part of speech in one language. It carries no
 * card schedule and no generated content — its jobs are deduplication and coverage, so the page
 * is about what the learner has met and where, not about what is due.
 */
class BaseWordController extends Controller
{
    use ListsBaseEntries;

    /**
     * The vocabulary base and the expression base, one language at a time, as a single list
     * (ListsBaseEntries) — with the wordboxes of that language to add checked words to.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $languageId = $this->resolveLanguage($request);

        return $this->baseTable($request, $user->baseWords(), $user->fixedExpressions(), $languageId, 'base.index', [
            'targetLanguages' => $user->languages()->orderBy('name')->get(),
            'activeLanguageId' => $languageId,
            'wordboxes' => $user->wordboxes()->forLanguage($languageId)->orderBy('position')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Correct an entry's translation and, for a word, its part of speech (from its language's
     * list). The word itself stays. A new part of speech makes it another entry in the base's
     * eyes, so it is refused when the learner already has that one, and the gender / dictionary
     * form of the old part of speech go — they would no longer apply.
     */
    public function update(Request $request, string $kind, int $id)
    {
        $entry = $this->entry($kind, $id);
        $rules = ['translation' => ['required', 'string', 'max:255']];

        if ($kind === 'word') {
            $parts = LanguageGuideline::for($entry->language?->code)?->partsOfSpeech() ?? LanguageGuideline::PARTS_OF_SPEECH;
            $rules['part_of_speech'] = ['required', Rule::in($parts)];
        }

        $data = $request->validate($rules);

        if ($kind === 'word' && $data['part_of_speech'] !== $entry->part_of_speech) {
            $taken = BaseWord::withoutGlobalScope('notDeleted')
                ->where($entry->only('user_id', 'language_id'))
                ->whereLemma($entry->lemma)
                ->where('part_of_speech', $data['part_of_speech'])
                ->exists();

            if ($taken) {
                return response()->json(['message' => 'You already have this word as a '.$data['part_of_speech'].'.'], 422);
            }

            $data += ['grammar_attributes' => null, 'dictionary_form' => null];
        }

        $entry->update($data);

        return response()->noContent();
    }

    /**
     * Delete one entry from the base by flagging it: its card links and Frammenti progress
     * stay, and approving a proposal with it again brings it back (BaseWord::resolve). A word
     * can also be marked `known`, so staging stops proposing it; an expression can't be known.
     */
    public function destroy(Request $request, string $kind, int $id)
    {
        $entry = $this->entry($kind, $id);

        if ($kind === 'word' && $request->boolean('known')) {
            $alreadyKnown = KnownWord::where($entry->only('user_id', 'language_id', 'part_of_speech'))->whereLemma($entry->lemma)->exists();

            if (! $alreadyKnown) {
                KnownWord::create($entry->only('user_id', 'language_id', 'lemma', 'part_of_speech'));
            }
        }

        $entry->update(['deleted' => true]);

        return response()->noContent();
    }

    /**
     * Mark two entries of one language as synonyms: two base words, two fixed expressions or
     * one of each, `first` and `second` given by key ("word:12"). Frammenti then never deals
     * them in one batch, and takes one for the other as "another way", not a miss. Linking a
     * pair again changes nothing.
     */
    public function link(Request $request)
    {
        [$first, $second] = $this->pair($request);

        if ($first->language_id !== $second->language_id) {
            return response()->json(['message' => 'Synonyms have to be in the same language.'], 422);
        }

        $first->linkSynonym($second);

        return response()->noContent();
    }

    public function unlink(Request $request)
    {
        [$first, $second] = $this->pair($request);

        $first->unlinkSynonym($second);

        return response()->noContent();
    }

    /**
     * The two entries a synonym request names, never one entry twice.
     *
     * @return array{0: BaseWord|FixedExpression, 1: BaseWord|FixedExpression}
     */
    private function pair(Request $request): array
    {
        $key = ['required', 'string', 'regex:/^(word|expression):\d+$/'];
        $data = $request->validate(['first' => $key, 'second' => [...$key, 'different:first']]);

        return [$this->entry(...$this->parse($data['first'])), $this->entry(...$this->parse($data['second']))];
    }

    /** One of the learner's own entries; another learner's is a 404. */
    private function entry(string $kind, int $id): BaseWord|FixedExpression
    {
        $user = Auth::user();

        return ($kind === 'word' ? $user->baseWords() : $user->fixedExpressions())->findOrFail($id);
    }

    /**
     * Which language the page is scoped to: an explicit (owned) one, else the learner's
     * default. Null only when they have no languages set up yet.
     */
    private function resolveLanguage(Request $request): ?int
    {
        $user = Auth::user();
        $requested = $request->query('language_id');

        if ($requested && $user->languages()->whereKey($requested)->exists()) {
            return (int) $requested;
        }

        return $user->currentSaveLanguage()?->id;
    }
}
