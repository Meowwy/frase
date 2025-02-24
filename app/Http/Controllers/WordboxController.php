<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WordboxController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($id)
    {
        //
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
    public function store()
    {
        $wordbox = Auth::user()->wordboxes()->create([
            'name'        => 'unnamed',
            'description' => '',
            'exam_text'   => '',
        ]);
        return redirect()->route('wordbox.show', ['id' => $wordbox->id]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $cards = Auth::user()->wordboxes()
            ->where('id', $id)
            ->firstOrFail()
            ->cards()
            ->get();

        $wordbox = Auth::user()->wordboxes()->where('id', $id)->first();

        if(!$wordbox){
            return redirect('/');
        }
        return view('wordbox.index', ['cards' => $cards, 'wordbox' => $wordbox]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $cards = Auth::user()->wordboxes()
            ->where('id', $id)
            ->firstOrFail()
            ->cards()
            ->get();

        $wordbox = Auth::user()->wordboxes()->where('id', $id)->first();

        if(!$wordbox){
            return redirect('/');
        }
        return view('wordbox.edit', ['cards' => $cards, 'wordbox' => $wordbox]);
    }

    /**
     * Update name.
     */
    public function updateName(Request $request, string $id)
    {
        // Validate the input
        $request->validate([
            'name' => 'required|string|max:50',
        ]);

        // Find the wordbox that belongs to the authenticated user
        $wordbox = Auth::user()->wordboxes()->where('id', $id)->firstOrFail();

        // Update the name
        $wordbox->update([
            'name' => $request->input('name'),
        ]);

        // Redirect back with success message
        return redirect()->back()->with('success', 'Wordbox name updated successfully.');
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $updatedCardsString = $request->input('cards');
        $themes = json_decode($updatedCardsString, true);
        //todo upravit to pro kartičky
        $cardsFromDatabase = Theme::where('user_id', Auth::id())
            ->get(['id', 'name']); // Get only the id and name columns

        $themesArray = $cardsFromDatabase->map(function ($theme) {
            return [
                'id' => $theme->id,
                'name' => strtolower($theme->name),
            ];
        })->toArray();

        foreach ($themesArray as $theme) {
            // Extract the IDs from the $themes array
            $themeIds = array_column($themes, 'id');

            // Check if the current theme's ID is in the list of IDs
            if (!in_array($theme['id'], $themeIds)) {
                $themeToDelete = Theme::find($theme['id']);
                $themeToDelete->delete();
            }
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
