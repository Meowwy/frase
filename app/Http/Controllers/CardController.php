<?php

namespace App\Http\Controllers;

use App\Jobs\CreateCardJob;
use App\Models\AI;
use App\Models\Card;
use App\Http\Requests\StoreCardRequest;
use App\Http\Requests\UpdateCardRequest;
use Illuminate\Support\Facades\Auth;

class CardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $cards = Auth::user()->cards;

        return view('cards.index', ['cards' => $cards]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCardRequest $request)
    {
        $request->validate([
            'capturedWord' => ['required', 'string', 'regex:/^[^0-9]*$/']
        ]);

        $userId = Auth::id();
        $phrase = request('capturedWord');
        if(!request()->filled('capturedWord')){
            return redirect('/');
        }

        CreateCardJob::dispatch($userId, $phrase);

        //return redirect('/');
        /*return response()->json([
            'success' => 'Word "' . $phrase . '" has been submitted successfully.',
            'capturedWord' => $phrase
        ], 200);*/

        return response(200);

    }

    /**
     * Display the specified resource.
     */
    public function show(Card $card)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Card $card)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCardRequest $request, Card $card)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Card $card)
    {
        //
    }
}
