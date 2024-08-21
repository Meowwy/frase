<?php

use App\Http\Controllers\AjaxController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\RegisteredUserController;
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
    // Retrieve themes with counts for the authenticated user
    $themes = Auth::user()->themes()
        ->withCount([
            'cards as total_cards_count',
            'cards as due_cards_count' => function ($query) {
                $query->whereDate('next_study_at', '<=', now()->toDateString());
            }
        ])
        ->get();
    // Format the data as an array of theme info
    return view('index',['themes' => $themes]);
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

    Route::get('/cards', [CardController::class, 'index']);

    Route::post('/captureWordAjax', [AjaxController::class, 'index'])->name('captureWordAjax');

    Route::get('cards/{tag:name}', TagController::class);

    Route::get('/themes/manage', [ThemeController::class, 'create']);
    Route::post('/saveThemes', [ThemeController::class, 'store'])->name('saveThemes');
});
Route::delete('/logout', [SessionController::class, 'destroy']);


