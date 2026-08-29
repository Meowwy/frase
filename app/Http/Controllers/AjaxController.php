<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Language;
use App\Models\Learning;
use App\Models\Wordbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AjaxController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            // Long enough to accept a pasted sentence, which the AI reduces to a
            // reusable expression frame ("I would like to go ..." => "I would like to ...").
            'capturedWord' => ['required', 'string', 'min:2', 'max:120'],
            'context' => ['nullable', 'string', 'min:2', 'max:250'],
            'language_id' => ['nullable', 'integer'],
            'wordbox_id' => ['nullable', 'integer'],
        ]);

        // Extract the captured word
        $capturedWord = trim($request->input('capturedWord'));

        // An untouched context input submits "", which is not null — normalise it so the
        // plain generator is used instead of the context one being fed an empty context.
        $context = $request->filled('context') ? trim($request->input('context')) : null;

        $userId = Auth::id();
        $phrase = $capturedWord;
        if (! request()->filled('capturedWord')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'capturedWord is required'], 422);
            }

            return redirect('/');
        }

        $user = Auth::user();

        // Resolve where the word is saved: a target language + an optional wordbox.
        $language = $this->resolveSaveLanguage($request, $user);
        if (! $language) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Please set up a target language in your settings first.'], 422);
            }

            return redirect('/profile/edit');
        }
        $wordbox = $this->resolveSaveWordbox($request, $user, $language);

        if ($user->cards()->where('language_id', $language->id)->whereRaw('LOWER(phrase) = ?', [strtolower($phrase)])->exists()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'This phrase already exists in your cards.'], 409);
            }

            return redirect('/');
        }
        // Two AI calls behind this: one to decide the card's shape and fix the term, one
        // to write that shape's fields. See Card::createFromTerm / docs/ai-integration.md.
        try {
            $newlyInsertedCard = Card::createFromTerm($user, $language, $capturedWord, $context);
        } catch (\Exception $e) {
            logger('Card creation threw for "'.$capturedWord.'": '.$e->getMessage());
            $newlyInsertedCard = null;
        }

        // A model refusal, a failed request or an unparseable answer all arrive as null.
        if (is_null($newlyInsertedCard)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'There was an error while creating the card.'], 500);
            }

            return redirect('/')->with('popup_message', 'There was an error while creating the card. Click OK to continue.');
        }

        if ($wordbox) {
            $wordbox->cards()->attach($newlyInsertedCard->id);
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => 'Card for "'.$phrase.'" has been created successfully.']);
        }

        // Flashed so the dashboard can offer the "learn it in a phrase instead" nudge on
        // a single-word card, right at the moment the learner just captured it.
        return redirect('/')->with('captured_card_id', $newlyInsertedCard->id);
    }

    /**
     * Persist the user's chosen save destination (language + optional wordbox) for new captures.
     */
    public function setCaptureTarget(Request $request)
    {
        $validated = $request->validate([
            'language_id' => ['required', 'integer'],
            'wordbox_id' => ['nullable', 'integer'],
        ]);

        $user = Auth::user();

        // The language must be one the user is learning.
        if (! $user->languages()->whereKey($validated['language_id'])->exists()) {
            return response()->json(['message' => 'Invalid language.'], 422);
        }

        $wordboxId = null;
        if (! empty($validated['wordbox_id'])) {
            $wordbox = $user->wordboxes()
                ->where('id', $validated['wordbox_id'])
                ->where('language_id', $validated['language_id'])
                ->first();
            if (! $wordbox) {
                return response()->json(['message' => 'Invalid wordbox for this language.'], 422);
            }
            $wordboxId = $wordbox->id;
        }

        session([
            'capture_language_id' => $validated['language_id'],
            'capture_wordbox_id' => $wordboxId,
        ]);

        // Remember as the durable default target language.
        $user->update(['active_language_id' => $validated['language_id']]);

        return response()->json(['success' => true]);
    }

    /**
     * Resolve the target language for a capture: request -> session -> user default.
     */
    private function resolveSaveLanguage(Request $request, $user): ?Language
    {
        $id = $request->input('language_id') ?: session('capture_language_id');
        if ($id && $user->languages()->whereKey($id)->exists()) {
            return Language::find($id);
        }

        return $user->currentSaveLanguage();
    }

    /**
     * Resolve the target wordbox for a capture (null = General vocabulary). An explicit
     * (even empty) wordbox_id in the request wins over the remembered session value.
     */
    private function resolveSaveWordbox(Request $request, $user, Language $language): ?Wordbox
    {
        $id = $request->has('wordbox_id') ? $request->input('wordbox_id') : session('capture_wordbox_id');
        if (! $id) {
            return null;
        }

        return $user->wordboxes()
            ->where('id', $id)
            ->where('language_id', $language->id)
            ->first();
    }

    public function saveLearning(Request $request)
    {
        $results = json_decode($request->input('results'), true) ?? [];

        // Scope to the current user's own cards in one query, then skip any id that
        // isn't in that set — silently ignores both a missing id and an id the user
        // doesn't own, rather than mutating another user's SRS state.
        $cards = Auth::user()->cards()
            ->whereIn('id', array_column($results, 'id'))
            ->get()
            ->keyBy('id');

        foreach ($results as $r) {
            $card = $cards->get($r['id']);
            if (! $card) {
                continue;
            }

            $card->next_study_at = Learning::getNextStudyDay($card->level, $r['result']);
            $r['result'] === 1 ? $card->level++ : $card->level = 1;
            $card->last_studied = now();
            $card->save();
        }

        return redirect('/completeLearning');
    }

    public function saveThemes(Request $request) {}
}
