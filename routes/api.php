<?php

use App\Http\Controllers\AjaxController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

Route::post('/extension/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (! Auth::attempt($credentials)) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    $token = Auth::user()->createToken('browser-extension')->plainTextToken;

    return response()->json(['token' => $token]);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/addWordAPI', [AjaxController::class, 'index'])->name('captureWordAjax');

    // Save destinations for the browser extension dropdown: one flat list of
    // "{language} - {wordbox|general}" options, grouped by language (alphabetical),
    // with the language's "general" (no wordbox) option first, then its wordboxes A-Z.
    Route::get('/save-options', function (Request $request) {
        $user = $request->user();
        $options = [];

        foreach ($user->languages()->orderBy('name')->get() as $language) {
            $options[] = [
                'value' => $language->id.':',
                'label' => $language->name.' - general',
                'language_id' => $language->id,
                'wordbox_id' => null,
            ];

            $wordboxes = $user->wordboxes()
                ->where('language_id', $language->id)
                ->orderBy('name')
                ->get();

            foreach ($wordboxes as $wordbox) {
                $options[] = [
                    'value' => $language->id.':'.$wordbox->id,
                    'label' => $language->name.' - '.$wordbox->name,
                    'language_id' => $language->id,
                    'wordbox_id' => $wordbox->id,
                ];
            }
        }

        $active = $user->currentSaveLanguage();

        return response()->json([
            'options' => $options,
            'selected' => $active ? $active->id.':' : null,
        ]);
    });
});
