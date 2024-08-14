<?php

use App\Http\Controllers\AjaxController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\RegisteredUserController;
use App\Http\Controllers\SessionController;
use App\Jobs\CreateCardJob;
use App\Models\Card;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('index');
});

Route::get('/test', function (){
   $content = \App\Models\AI::getContentForCard('weird');
   $output = json_decode($content);
   dd($output);
});

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create']);
    Route::post('/register', [RegisteredUserController::class, 'store']);

    Route::get('/login',[SessionController::class, 'create'])->name('login');
    Route::post('/login',[SessionController::class, 'store']);
});

Route::delete('/logout',[SessionController::class, 'destroy']);

Route::get('/setLearning', function () {
    return view('set-learning');
});

Route::get('/learning', function () {
    return view('learning');
});

Route::get('/cards', [CardController::class, 'index'])->middleware('auth');

//Route::post('/captureWordAjax', [CardController::class, 'store'])->name('captureWordAjax');


Route::post('/captureWordAjax', [AjaxController::class, 'index'])->name('captureWordAjax');


