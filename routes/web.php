<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('index');
});

Route::get('/login', function () {
    return view('auth.login');
});

Route::get('/register', function () {
    return view('auth.register');
});

Route::get('/setLearning', function () {
    return view('set-learning');
});

Route::get('/learning', function () {
    return view('learning');
});
