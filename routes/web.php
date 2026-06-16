<?php

use App\Http\Controllers\AjaxController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\GapFillExerciseController;
use App\Http\Controllers\RegisteredUserController;
use App\Http\Controllers\SeachController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WordboxController;
use App\Jobs\GenerateEmbeddingJob;
use App\Models\Card;
use App\Models\Learning;
use App\Models\User;
use App\Models\Wordbox;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;

Route::get('/', function () {
    if (! Auth::check()) {
        return view('index');
    }

    $totalDueCards = Auth::user()->cards()
        ->whereDate('next_study_at', '<=', now()->toDateString())
        ->count();

    $totalCards = Auth::user()->cards()
        ->count();

    // Retrieve themes with counts for the authenticated user
    $themes = Auth::user()->themes()
        ->withCount([
            'cards as total_cards_count',
            'cards as due_cards_count' => function ($query) {
                $query->whereDate('next_study_at', '<=', now()->toDateString());
            },
        ])
        ->orderBy('total_cards_count', 'desc')
        ->get();

    $wordboxes = Auth::user()->wordboxes()
        ->select('id', 'name', 'description')
        ->withCount('cards')
        ->get();

    // Save-destination picker data: target languages + this user's wordboxes grouped by language.
    $targetLanguages = Auth::user()->languages()->orderBy('name')->get();
    $wordboxesByLanguage = Auth::user()->wordboxes()
        ->select('id', 'name', 'language_id', 'position')
        ->orderBy('position')
        ->orderBy('name')
        ->get()
        ->groupBy('language_id');

    // Due-card counts per target language → drives the "Learning" review cards on the dashboard.
    $dueByLanguage = Auth::user()->cards()
        ->whereDate('next_study_at', '<=', now()->toDateString())
        ->selectRaw('language_id, COUNT(*) as aggregate')
        ->groupBy('language_id')
        ->pluck('aggregate', 'language_id');

    $dueLanguages = $targetLanguages
        ->filter(fn ($lang) => ($dueByLanguage[$lang->id] ?? 0) > 0)
        ->map(function ($lang) use ($dueByLanguage) {
            $lang->due_count = $dueByLanguage[$lang->id];

            return $lang;
        })
        ->values();

    $saveLanguage = Auth::user()->currentSaveLanguage();
    $saveLanguageId = $saveLanguage?->id;
    $saveLanguageName = $saveLanguage?->name;
    $saveWordboxId = session('capture_wordbox_id');
    $saveTargetName = 'General vocabulary';
    if ($saveWordboxId) {
        $selectedBox = ($wordboxesByLanguage[$saveLanguageId] ?? collect())->firstWhere('id', $saveWordboxId);
        $saveTargetName = $selectedBox->name ?? 'General vocabulary';
        if (! $selectedBox) {
            $saveWordboxId = null;
        }
    }

    return view('index', [
        'themes' => $themes,
        'dueCount' => $totalDueCards,
        'totalCount' => $totalCards,
        'wordboxes' => $wordboxes,
        'targetLanguages' => $targetLanguages,
        'wordboxesByLanguage' => $wordboxesByLanguage,
        'dueLanguages' => $dueLanguages,
        'saveLanguageId' => $saveLanguageId,
        'saveLanguageName' => $saveLanguageName,
        'saveWordboxId' => $saveWordboxId,
        'saveTargetName' => $saveTargetName,
    ]);
});

/*Route::get('/test', function () {
    $content = \App\Models\AI::getContentForCard('weird');
    $output = json_decode($content);
    dd($output);
});*/

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create']);
    Route::post('/register', [RegisteredUserController::class, 'store']);

    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [UserController::class, 'index']);
    Route::get('/profile/edit', [UserController::class, 'edit']);
    Route::post('/profile/edit', [UserController::class, 'update']);
    Route::get('/profile/wordboxes', [UserController::class, 'wordboxesOrder'])->name('wordboxes.order');
    Route::post('/profile/wordboxes', [UserController::class, 'updateWordboxesOrder'])->name('wordboxes.order.update');

    Route::get('/learning', function () {
        return view('learning.index');
    });
    Route::get('/filterCardsForLearning/{filter}', [Learning::class, 'setLearning']);
    Route::get('/setLearning', function () {
        $user = Auth::user();
        $filter = session('learning_filter');

        // Legacy theme-based flow keeps the simple mode picker.
        $themeName = null;
        if (is_string($filter) && $filter !== '' && $filter !== 'due' && ! is_numeric($filter)
            && $user->themes()->where('name', $filter)->exists()) {
            $themeName = $filter;
        }

        $targetLanguages = $user->languages()->orderBy('name')->get();
        $wordboxesByLanguage = $user->wordboxes()
            ->select('id', 'name', 'language_id', 'position')
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->groupBy('language_id');
        // Allow preselecting a language via ?language_id (e.g. the dashboard "Review due cards" card).
        $requestedLanguageId = request()->query('language_id');
        if ($requestedLanguageId && $user->languages()->where('languages.id', $requestedLanguageId)->exists()) {
            $activeLanguageId = $requestedLanguageId;
        } else {
            $activeLanguageId = $user->currentSaveLanguage()?->id ?? optional($targetLanguages->first())->id;
        }

        return view('learning.set', [
            'themeName' => $themeName,
            'targetLanguages' => $targetLanguages,
            'wordboxesByLanguage' => $wordboxesByLanguage,
            'activeLanguageId' => $activeLanguageId,
        ]);
    });
    Route::get('/startLearning/{wbid}/{mode}', [Learning::class, 'startLearning']);
    Route::get('/startLearningSet/{mode}', [Learning::class, 'startLearningSet']);
    Route::post('/saveLearning', [AjaxController::class, 'saveLearning'])->name('saveLearning');
    Route::get('/completeLearning', function () {
        return view('learning.complete');
    });

    Route::get('/search', [SeachController::class, 'index']);
    Route::get('/searchWordbox/{wbid}', [SeachController::class, 'searchWordbox'])->name('seachWordbox');

    Route::get('/cards', [CardController::class, 'index']);
    Route::get('/cards/{card:id}', [CardController::class, 'show']);
    Route::get('/cards/edit/{card:id}', [CardController::class, 'edit']);
    Route::post('/cards/new', [CardController::class, 'save']);
    Route::post('/cards/{card:id}/delete', function ($id) {
        $card = Auth::user()->cards()->find($id);
        if ($card) {
            // Delete the card
            $card->delete();
        }

        return redirect('/cards');
    });
    Route::post('/cards/new', function (\Illuminate\Http\Request $request) {
        $request->validate([
            'phrase' => ['required', 'string', 'max:40', 'min:2'],
            'definition' => ['required', 'string'],
        ]);

        $user = Auth::user();

        $language = $user->currentSaveLanguage();
        if (! $language) {
            return redirect('/profile/edit');
        }

        $card = $user->cards()->create([
            'phrase' => $request->phrase,
            'theme_id' => ($request->theme_id != -1 ? $request->theme_id : null),
            'language_id' => $language->id,
            'level' => 1,
            'translation' => $request->translation,
            'example_sentence' => $request->example_sentence,
            'question' => $request->question,
            'definition' => $request->definition,
            'next_study_at' => now(),
        ]);
        GenerateEmbeddingJob::dispatch($card);
        logger('Card has been created for '.$request->phrase);

        return redirect('/');
    });
    Route::get('/add', [CardController::class, 'create']);

    // Route::post('/cards/{card:id}', [CardController::class, 'update']);
    Route::post('/cards/{card:id}', function (\Illuminate\Http\Request $request, Card $card) {
        $validatedData = $request->validate([
            'phrase' => ['required', 'string'],
            'definition' => ['required', 'string'],
            'translation' => ['required', 'string'],
            'question' => ['required', 'string'],
            'example_sentence' => ['required', 'string'],
            'id' => ['required'],
            'theme_id' => ['required'],
        ]);
        if ($validatedData['theme_id'] === '-1') {
            $validatedData['theme_id'] = null;
        }
        // Update the card with the validated data
        $card->update($validatedData);

        // Redirect back with a success message
        return redirect('/cards/'.$card->id);
    });

    Route::post('/captureWordAjax', [AjaxController::class, 'index'])->name('captureWordAjax');
    Route::post('/capture-target', [AjaxController::class, 'setCaptureTarget'])->name('capture-target');

    Route::get('/cards/{card}/synonyms', function (Card $card) {
        return response()->json([
            'synonyms' => $card->synonyms()->with('synonymCard:id,phrase,translation')->get(),
            'related_terms' => $card->relatedTerms()->with('relatedCard:id,phrase,translation')->get(),
        ]);
    });

    Route::get('cards/{tag:name}', TagController::class);

    Route::get('/themes/manage', [ThemeController::class, 'create']);
    Route::post('/saveThemes', [ThemeController::class, 'store'])->name('saveThemes');
    Route::post('/generateThemes', [ThemeController::class, 'generate'])->name('generate');

    Route::post('/test', [CardController::class, 'show']);

    /*Route::get('/wordbox', function (){
        return view('wordbox.index');
    });*/
    Route::post('wordbox/new', [WordboxController::class, 'store']);
    Route::get('wordbox/{id}', [WordboxController::class, 'show'])->name('wordbox.show');
    Route::get('wordbox/{id}/edit', [WordboxController::class, 'edit'])->name('wordbox.edit');
    Route::post('/saveCards/{id}', [WordboxController::class, 'update'])->name('saveCards');
    Route::patch('/wordbox/{id}', [WordboxController::class, 'updateName']);
    Route::get('/wordbox/{wordbox}/gapfill/generate', [GapFillExerciseController::class, 'store'])->name('gapfill.generate');
    Route::get('/gap-fill/{exercise}', [GapFillExerciseController::class, 'show'])->name('gap-fill.show');
    Route::get('/gap-fill/{exercise}/status', [GapFillExerciseController::class, 'status'])->name('gap-fill.status');
    Route::delete('/gap-fill/{exercise}', [GapFillExerciseController::class, 'destroy'])->name('gap-fill.destroy');
    Route::get('/test/createdGapFill', function () {
        $exercise = \App\Models\GapFillExercise::latest()->first();
        if (! $exercise) {
            return 'No exercise found';
        }

        return response()->json($exercise);
    });
});
Route::delete('/logout', [SessionController::class, 'destroy']);

Route::post('/addWordAPI', function (Request $request) {
    /*return response('', 204)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With');*/
    dd('worked');

});

// BONUSY
Route::get('/kresleni', function () {
    return view('kresleni.index');
});

Route::get('/kresleni2', function () {
    return view('kresleni.index2');
});

Route::get('/kresleni3', function () {
    return view('kresleni.index3');
});
