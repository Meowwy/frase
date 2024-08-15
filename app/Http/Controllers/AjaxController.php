<?php

namespace App\Http\Controllers;

use App\Jobs\CreateCardJob;
use Illuminate\Http\Request;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class AjaxController extends Controller {

    public function index(Request $request) {
        $request->validate([
            'capturedWord' => 'required|string'
        ]);

        // Extract the captured word
        $capturedWord = $request->input('capturedWord');

        $userId = Auth::id();
        $phrase = request('capturedWord');
        if(!request()->filled('capturedWord')){
            return redirect('/');
        }

        CreateCardJob::dispatch($userId, $phrase);

        return redirect('/');
        /*return response()->json([
            'success' => 'Word "' . $phrase . '" has been submitted successfully.',
            'capturedWord' => $phrase
        ], 200);*/

        //return response(200);
    }
}
