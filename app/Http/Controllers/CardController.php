<?php

namespace App\Http\Controllers;

use App\Jobs\CreateCardJob;
use App\Models\AI;
use App\Models\Card;
use App\Http\Requests\StoreCardRequest;
use App\Http\Requests\UpdateCardRequest;
use App\Models\Theme;
use Carbon\Carbon;
use http\Env\Request;
use Illuminate\Support\Facades\Auth;

class CardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $cards = Auth::user()->cards()
            ->with('theme:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $cards->transform(function ($card) {
            $card->next_study_at = Carbon::parse($card->next_study_at)->format('d-m-Y');
            return $card;
        });

        $themes = Theme::where('user_id', Auth::id())
            ->get(['id', 'name']); // Get only the id and name columns

// Format the result as an array of associative arrays
        $themesArray = $themes->map(function ($theme) {
            return [
                'id' => $theme->id,
                'name' => $theme->name,
            ];
        })->toArray();

        return view('cards.index', ['cards' => $cards, 'themes' => $themesArray]);
    }

    public function themeFilter(\Illuminate\Http\Request $request)
    {
        $themeName = $request->get('themeSelect');
        if($themeName === 'All themes'){
            $cards = Auth::user()->cards()
                ->with('theme:id,name')
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        }else{
            $cards = Auth::user()->cards()
                ->with('theme:id,name')
                ->whereHas('theme', function ($query) use ($themeName) {
                    $query->where('name', $themeName);
                })
                ->orderBy('created_at', 'desc')
                ->paginate(20);
        }


        $cards->transform(function ($card) {
            $card->next_study_at = Carbon::parse($card->next_study_at)->format('d-m-Y');
            return $card;
        });

        $themes = Theme::where('user_id', Auth::id())
            ->get(['id', 'name']); // Get only the id and name columns

        $themesArray = $themes->map(function ($theme) {
            return [
                'id' => $theme->id,
                'name' => $theme->name,
            ];
        })->toArray();

        return view('cards.index', ['cards' => $cards, 'themes' => $themesArray, 'selectedTheme' => $themeName]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCardRequest $request)
    {
        if(Auth::user()->currency_amount <= 0){
            return redirect('/');
        }

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
        $themes = Theme::where('user_id', Auth::id())
            ->get(['id', 'name']); // Get only the id and name columns

        $themesArray = $themes->map(function ($theme) {
            return [
                'id' => $theme->id,
                'name' => $theme->name,
            ];
        })->toArray();
        return view('cards.edit', ['card' => $card, 'themes' => $themesArray]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCardRequest $request, Card $card)
    {
        // Ensure that the card belongs to the authenticated user
        if ($card->user_id !== Auth::id()) {
            return redirect()->back()->with('error', 'You do not have permission to update this card.');
        }

        // Validate and get the validated data from the request
        $validatedData = $request->validated([
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
        return redirect('/');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Card $card)
    {
        //
    }
}
