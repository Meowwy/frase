<?php

use App\Http\Controllers\AjaxController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\RegisteredUserController;
use App\Http\Controllers\SeachController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserController;
use App\Jobs\CreateCardJob;
use App\Models\Card;
use App\Models\Learning;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use function Pest\Laravel\get;

Route::get('/', function () {
    If(!Auth::check()) {
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
            }
        ])
        ->orderBy('total_cards_count', 'desc')
        ->get();
    // Format the data as an array of theme info
    return view('index',['themes' => $themes, 'dueCount' => $totalDueCards, 'totalCount' => $totalCards]);
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

    Route::get('/learning', function () {
        return view('learning.index');
    });
    Route::get('/filterCardsForLearning/{filter}', [Learning::class, 'setLearning']);
    Route::get('/setLearning', function () {
        return view('learning.set');
    });
    Route::get('/startLearning/{mode}', [Learning::class, 'startLearning']);
    Route::post('/saveLearning', [AjaxController::class, 'saveLearning'])->name('saveLearning');
    Route::get('/completeLearning', function (){
        return view('learning.complete');
    });

    Route::get('/search', [SeachController::class, 'index']);

    Route::get('/cards', [CardController::class, 'index']);
    Route::post('/cards/themeFilter', [CardController::class, 'themeFilter']);
    Route::get('/cards/{card:id}', [CardController::class, 'show']);
    Route::get('/cards/edit/{card:id}', [CardController::class, 'edit']);
    Route::post('/cards/new', [CardController::class, 'save']);
    Route::post('/cards/{card:id}/delete', function ($id){
        $card = Auth::user()->cards()->find($id);
        if ($card) {
            // Delete the card
            $card->delete();}
        return redirect("/cards");
    });
    Route::post('/cards/new', function (\Illuminate\Http\Request $request){
        $request->validate([
            'phrase' => ['required', 'string'],
            'definition' => ['required', 'string']
        ]);

        $user = Auth::user();

        $user->cards()->create([
            'phrase' => $request->phrase,
            'theme_id' => ($request->theme_id != -1 ? $request->theme_id : null),
            'level' => 1,
            'translation' => $request->translation,
            'example_sentence' => $request->example_sentence,
            'question' => $request->question,
            'definition' => $request->definition,
            'next_study_at' => now()
        ]);
        logger('Card has been created for '.$request->phrase);
        return redirect('/');
    });
    Route::get('/add', [CardController::class, 'create']);

    //Route::post('/cards/{card:id}', [CardController::class, 'update']);
    Route::post('/cards/{card:id}', function (\Illuminate\Http\Request $request, Card $card) {
        $validatedData = $request->validate([
            'phrase' => ['required', 'string'],
            'definition' => ['required', 'string'],
            'translation' => ['required', 'string'],
            'question' => ['required', 'string'],
            'example_sentence' => ['required', 'string'],
            'id' => ['required'],
            'theme_id' => ['required']
        ]);
        if($validatedData['theme_id'] === '-1'){
            $validatedData['theme_id'] = null;
        }
        // Update the card with the validated data
        $card->update($validatedData);

        // Redirect back with a success message
        return redirect('/cards/' . $card->id);
    });

    Route::post('/captureWordAjax', [AjaxController::class, 'index'])->name('captureWordAjax');

    Route::get('cards/{tag:name}', TagController::class);

    Route::get('/themes/manage', [ThemeController::class, 'create']);
    Route::post('/saveThemes', [ThemeController::class, 'store'])->name('saveThemes');
    Route::post('/generateThemes', [ThemeController::class, 'generate'])->name('generate');

    Route::post('/test',[CardController::class, 'show']);
});
Route::delete('/logout', [SessionController::class, 'destroy']);


