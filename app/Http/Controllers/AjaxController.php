<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateEmbeddingJob;
use App\Models\AI;
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
        $targetLanguage = $language->name;
        $nativeLanguage = optional($user->nativeLanguage)->name ?? $user->native_language;
        $level = $user->levelForLanguage($language);

        // Saving into the native language builds monolingual vocabulary: every field is
        // generated in the native language and the card has no translation.
        $isNative = $user->native_language_id && (int) $language->id === (int) $user->native_language_id;

        if ($isNative) {
            $content = AI::getContentForCardNative($capturedWord, $language->name, $context);
        } elseif (is_null($context)) {
            $content = AI::getContentForCard($capturedWord, $targetLanguage, $nativeLanguage, $level);
        } else {
            $content = AI::getContentForCardWithContext($capturedWord, $targetLanguage, $nativeLanguage, $context, $level);
        }
        if (is_null($content)) {
            logger('The model refused to create the card for '.$request->capturedWord);
            // return;
        }
        try {
            $cleanedContent = trim($content);
            $output = json_decode($cleanedContent);
            /*$user->currency_amount = $user->currency_amount - 1;
            if ($user->currency_amount < 0) {
                $user->currency_amount = 0;
            }
            $user->save();*/

            // Empty for an expression card (it is illustrated by its example sentence
            // alone). Drop blanks so a stray [""] from the model doesn't become an
            // empty example box on the card.
            $examples = array_values(array_filter(
                array_map('trim', (array) ($output->examples ?? [])),
                fn ($example) => $example !== ''
            ));

            $newlyInsertedCard = $user->cards()->create([
                'phrase' => $output->phrase,
                'term_type' => in_array($output->term_type ?? null, Card::TERM_TYPES, true)
                    ? $output->term_type
                    : Card::TYPE_LEXICAL,
                'language_id' => $language->id,
                'level' => 1,
                'translation' => $output->translation ?? '',
                // The column is NOT NULL, so coalesce to an empty string.
                'example_sentence' => $output->sentence ?? '',
                'example_1' => $examples[0] ?? null,
                'example_2' => $examples[1] ?? null,
                'example_3' => $examples[2] ?? null,
                'definition' => $output->definition,
                'next_study_at' => now(),
            ]);
            GenerateEmbeddingJob::dispatch($newlyInsertedCard);
            logger('Card has been created for '.$output->phrase);
        } catch (\Exception) {
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

        return redirect('/');

        /*return response()->json([
            'success' => 'Word "' . $phrase . '" has been submitted successfully.',
            'capturedWord' => $phrase
        ], 200);*/

        // return response(200);
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
