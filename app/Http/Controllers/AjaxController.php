<?php

namespace App\Http\Controllers;

use App\Jobs\CreateCardJob;
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
            'capturedWord' => ['required', 'string', 'min:2', 'max:50'],
            'context' => ['nullable', 'string', 'min:2', 'max:250'],
            'language_id' => ['nullable', 'integer'],
            'wordbox_id' => ['nullable', 'integer'],
        ]);

        // Extract the captured word
        $capturedWord = $request->input('capturedWord');
        $context = $request->input('context');

        $userId = Auth::id();
        $phrase = request('capturedWord');
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
        // Retrieve the user's themes for this language
        $themes = $user->themes()->where('language_id', $language->id)->select('id', 'name')->get();

        if (count($themes) !== 0) {
            $themeStrings = $themes->take(20)->map(function ($theme) {
                return "\"{$theme->name}\"";
            });
            $themeString = $themeStrings->implode(',');
        } else {
            $themeString = 'no categories defined';
        }

        $targetLanguage = $language->name;
        $nativeLanguage = optional($user->nativeLanguage)->name ?? $user->native_language;
        $level = $user->levelForLanguage($language);

        if (is_null($context)) {
            $content = AI::getContentForCard($capturedWord, $themeString, $targetLanguage, $nativeLanguage, $level);
        } else {
            $content = AI::getContentForCardWithContext($capturedWord, $themeString, $targetLanguage, $nativeLanguage, $context, $level);
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

            $selectedTheme = $themes->firstWhere('name', strtolower($output->theme));

            $recentThemeId = null;

            if (is_null($selectedTheme)) {
                $themeCount = $user->themes()->where('language_id', $language->id)->count();
                if ($themeCount < 20) {
                    $user->themes()->create([
                        'name' => strtolower($output->theme),
                        'language_id' => $language->id,
                    ]);
                    $recentThemeId = $user->themes()
                        ->where('language_id', $language->id)
                        ->orderBy('created_at', 'desc')
                        ->value('id');
                }
            }

            $newlyInsertedCard = $user->cards()->create([
                'phrase' => $output->phrase,
                'theme_id' => ($selectedTheme ? $selectedTheme->id : $recentThemeId),
                'language_id' => $language->id,
                'level' => 1,
                'translation' => $output->translation,
                'example_sentence' => $output->sentence,
                'question' => $output->question,
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

        // konec obsahu CreateCardJob
        if ($request->expectsJson()) {
            return response()->json(['success' => 'Card for "'.$phrase.'" has been created successfully.']);
        }

        if ($wordbox) {
            return redirect()->route('wordbox.show', ['id' => $wordbox->id]);
        } else {
            return redirect('/');
        }

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
        // Validate the incoming request
        $results = json_decode($request->input('results'), true);

        foreach ($results as $r) {
            $card = Card::find($r['id']);
            $card->next_study_at = Learning::getNextStudyDay($card->level, $r['result']);
            $r['result'] === 1 ? $card->level++ : $card->level = 1;
            $card->last_studied = now();
            $card->save();
        }

        return redirect('/completeLearning');
    }

    public function saveThemes(Request $request) {}
}
