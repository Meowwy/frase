<?php

namespace App\Http\Controllers;

use App\Models\Frammenti;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * LEARN: Frammenti, unscheduled practice over the vocabulary base and expression base.
 * The page plays batches client-side; this controller deals them and saves their answers.
 * See docs/frammenti.md.
 */
class FrammentiController extends Controller
{
    public function index(Request $request)
    {
        $languages = $this->targetLanguages();
        $language = $languages->firstWhere('id', (int) $request->query('language_id'))
            ?? $languages->firstWhere('id', Auth::user()->currentSaveLanguage()?->id)
            ?? $languages->first();

        return view('frammenti.index', [
            'targetLanguages' => $languages,
            'language' => $language,
            'enough' => $language && Frammenti::enough(Auth::user(), $language),
        ]);
    }

    public function batch(Request $request)
    {
        $data = $request->validate([
            'language_id' => 'required|integer',
            'exclude' => 'array',
            'exclude.*.type' => 'required|in:base_word,fixed_expression',
            'exclude.*.id' => 'required|integer',
        ]);
        $language = $this->targetLanguage($data['language_id']);

        if (! Frammenti::enough(Auth::user(), $language)) {
            return response()->json(['message' => 'Frammenti needs at least 5 nouns and 5 verbs in this language.'], 422);
        }

        $exclude = array_map(fn ($e) => $e['type'].':'.$e['id'], $data['exclude'] ?? []);
        $fragments = Frammenti::deal(Auth::user(), $language, $exclude);

        if (! $fragments) {
            return response()->json(['message' => 'Could not generate a batch.'], 503);
        }

        return response()->json(['fragments' => $fragments]);
    }

    /**
     * Apply a batch's answers, once per batch. Items load through the learner's own
     * relations, so an id they don't own is silently skipped, as on /saveLearning.
     */
    public function results(Request $request)
    {
        $data = $request->validate([
            'results' => 'required|array',
            'results.*.type' => 'required|in:base_word,fixed_expression',
            'results.*.id' => 'required|integer',
            'results.*.result' => 'required|boolean',
        ]);
        $results = collect($data['results'])->unique(fn ($r) => $r['type'].':'.$r['id']);
        $ids = fn ($type) => $results->where('type', $type)->pluck('id');
        $items = [
            'base_word' => Auth::user()->baseWords()->whereIn('id', $ids('base_word'))->get()->keyBy('id'),
            'fixed_expression' => Auth::user()->fixedExpressions()->whereIn('id', $ids('fixed_expression'))->get()->keyBy('id'),
        ];

        $changes = [];
        foreach ($results as $r) {
            if (! $item = $items[$r['type']]->get($r['id'])) {
                continue;
            }

            $before = $item->frammenti_tier;
            Frammenti::answer($item, (bool) $r['result']);
            $changes[] = ['type' => $r['type'], 'id' => $item->id, 'tier_before' => $before, 'tier_after' => $item->frammenti_tier];
        }

        return response()->json($changes);
    }

    /**
     * The learner's target languages — never their native one, even when they collect
     * native-language vocabulary.
     */
    private function targetLanguages(): Collection
    {
        $user = Auth::user();

        return $user->languages()->orderBy('name')
            ->when($user->native_language_id, fn ($q, $native) => $q->whereKeyNot($native))
            ->get();
    }

    private function targetLanguage(int $id): Language
    {
        return $this->targetLanguages()->firstWhere('id', $id) ?? abort(403, 'Unauthorized action.');
    }
}
