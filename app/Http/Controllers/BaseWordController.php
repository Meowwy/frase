<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsBaseEntries;
use App\Models\BaseWord;
use App\Models\FixedExpression;
use App\Models\KnownWord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
     * Delete one entry from the base by flagging it: its card links and Frammenti progress
     * stay, and approving a proposal with it again brings it back (BaseWord::resolve). A word
     * can also be marked `known`, so staging stops proposing it; an expression can't be known.
     */
    public function destroy(Request $request, string $kind, int $id)
    {
        $entry = $this->entry($kind, $id);

        if ($kind === 'word' && $request->boolean('known')) {
            KnownWord::firstOrCreate($entry->only('user_id', 'language_id', 'lemma', 'part_of_speech'));
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
