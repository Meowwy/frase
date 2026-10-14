<?php

namespace App\Http\Controllers;

use App\Models\AI;
use App\Models\BaseWord;
use App\Models\FixedExpression;
use App\Models\Frammenti;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * LEARN: Frammenti, the daily active-vocabulary drill over the vocabulary base and
 * expression base. The page plays batches client-side; this controller plans the day,
 * deals the batches, grades tier III sentences and saves each answer as it's given.
 * See docs/frammenti.md.
 */
class FrammentiController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $languages = Frammenti::languages($user);
        $language = $languages->firstWhere('id', (int) $request->query('language_id'))
            ?? $languages->firstWhere('id', $user->currentSaveLanguage()?->id)
            ?? $languages->first();
        $session = $language ? Frammenti::session($user, $language->id) : null;

        return view('frammenti.index', [
            'targetLanguages' => $languages,
            'language' => $language,
            'enough' => $language && Frammenti::enough($user, $language),
            'session' => $session,
            'plan' => $language && ! $session ? Frammenti::plan($user, $language) : null,
            'activeWords' => $language ? Frammenti::activeWords($user, $language) : 0,
            'streak' => Frammenti::streak($user),
        ]);
    }

    /**
     * Deal one batch. The first request of the day plans and stores today's session;
     * after it is finished (or when there was nothing to plan) batches are Extra drill.
     */
    public function batch(Request $request)
    {
        $data = $request->validate([
            'language_id' => 'required|integer',
            'on_screen' => 'array',
            'on_screen.*.type' => 'required|in:base_word,fixed_expression',
            'on_screen.*.id' => 'required|integer',
        ]);
        $user = Auth::user();
        $language = $this->targetLanguage($data['language_id']);

        if (! Frammenti::enough($user, $language)) {
            return response()->json(['message' => 'Frammenti needs at least 5 nouns and 5 verbs in this language.'], 422);
        }

        $session = Frammenti::startSession($user, $language);
        $onScreen = array_map(fn ($e) => $e['type'].':'.$e['id'], $data['on_screen'] ?? []);
        $fragments = Frammenti::deal($user, $language, $session, $onScreen);

        if (! $fragments) {
            return response()->json(['message' => 'Could not generate a batch.'], 503);
        }

        return response()->json([
            'fragments' => $fragments,
            'drill' => ! $session || $session->finished_at !== null,
            'planned_batches' => $session?->planned_batches,
            'batches_done' => $session?->batches_done,
        ]);
    }

    /**
     * Apply one answer, as it's given. The client is trusted with right/wrong, as on
     * /saveLearning; everything else comes from the item's stored state.
     */
    public function answer(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:base_word,fixed_expression',
            'id' => 'required|integer',
            'result' => 'required|boolean',
            'last' => 'boolean',
        ]);
        $user = Auth::user();
        $item = $this->item($data['type'], $data['id']);
        $before = $item->frammenti_tier;

        $session = Frammenti::answer($user, $item, $data['result'], $data['last'] ?? false);

        return response()->json([
            'tier_before' => $before,
            'tier_after' => $item->frammenti_tier,
            'back_tomorrow' => $item->frammenti_due_on === Carbon::parse($user->today())->addDay()->toDateString(),
            'batches_done' => $session?->batches_done,
            // Only on the answer that finishes today's planned session.
            'recap' => $session?->wasChanged('finished_at') ? [
                'streak' => Frammenti::streak($user),
                'active_words' => Frammenti::activeWords($user),
            ] : null,
        ]);
    }

    /**
     * Grade a tier III sentence. A 503 makes the client fall back to self-grading.
     */
    public function evaluate(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:base_word,fixed_expression',
            'id' => 'required|integer',
            'task' => 'required|string|max:500',
            'sentence' => 'required|string|max:1000',
        ]);
        $user = Auth::user();
        $item = $this->item($data['type'], $data['id']);
        $isWord = $item instanceof BaseWord;

        $evaluation = AI::evaluateSentence(
            $isWord ? $item->displayForm() : $item->form,
            $item->translation,
            $isWord ? $item->part_of_speech : 'fixed expression',
            $data['task'],
            $data['sentence'],
            $item->language->name,
            Frammenti::nativeLanguageName($user),
            $user->levelForLanguage($item->language),
        );

        return $evaluation
            ? response()->json($evaluation)
            : response()->json(['message' => 'Could not evaluate the sentence.'], 503);
    }

    /**
     * One of the learner's own items; another learner's id is a 404.
     */
    private function item(string $type, int $id): BaseWord|FixedExpression
    {
        $relation = $type === 'base_word' ? Auth::user()->baseWords() : Auth::user()->fixedExpressions();

        return $relation->findOrFail($id);
    }

    private function targetLanguage(int $id): Language
    {
        return Frammenti::languages(Auth::user())->firstWhere('id', $id) ?? abort(403, 'Unauthorized action.');
    }
}
