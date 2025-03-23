<?php

namespace App\Http\Controllers;

use App\Models\Card;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SeachController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $request->validate([
            'searchTerm' => ['required', 'string', 'min:2'],
        ]);

        $searchTerm = $request->input('searchTerm');

        $cards = Card::where('user_id', Auth::id())
            ->where('phrase', 'LIKE', '%' . $searchTerm . '%')
            ->orderByRaw("CASE WHEN phrase LIKE ? THEN 0 ELSE 1 END", ["$searchTerm%"])
            ->limit(15)
            ->get();

        foreach ($cards as $card) {
            $card->example_sentence = preg_replace('/\[(.*?)\]/', '<span class="font-bold">$1</span>', $card->example_sentence);
        }

        return view('search.index', ['cards' => $cards, 'searchTerm' => $searchTerm]);
    }

    public function searchWordbox(Request $request, $wbid){
        $request->validate([
            'searchTerm' => ['required', 'string', 'min:2'],
        ]);

        $searchTerm = $request->input('searchTerm');
        /*
        $cards = Card::where('user_id', Auth::id())
            ->where('phrase', 'LIKE', '%' . $searchTerm . '%')
            ->orderByRaw("CASE WHEN phrase LIKE ? THEN 0 ELSE 1 END", ["$searchTerm%"])
            ->limit(15)
            ->get();
        */
        $foundCards = Card::where('phrase', 'like', "%$searchTerm%")->limit(5)->get();

        foreach ($foundCards as $card) {
            $card->example_sentence = preg_replace('/\[(.*?)\]/', '<span class="font-bold">$1</span>', $card->example_sentence);
        }

        $cards = Auth::user()->wordboxes()
            ->where('id', $wbid)
            ->firstOrFail()
            ->cards()
            ->get();

        $wordbox = Auth::user()->wordboxes()->where('id', $wbid)->first();

        return view('wordbox.edit', ['foundCards' => $foundCards, 'searchTermWb' => $searchTerm, 'cards' => $cards, 'wordbox' => $wordbox]);
    }


    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
