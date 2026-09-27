<?php

use App\Http\Controllers\ProposalController;
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
    // The extension writes into staging exactly like the web form does — same controller,
    // same proposal row. There is no save destination to choose any more: the language is
    // detected by CALL 1 and corrected in staging. See docs/browser-extension.md.
    Route::post('/addWordAPI', [ProposalController::class, 'store'])->name('captureApi');
});
