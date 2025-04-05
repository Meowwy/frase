<?php

namespace App\Http\Controllers;

use App\Models\Card;
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
        $updatedCardIds = collect(json_decode($updatedCardsString, true))
            ->pluck('id')
            ->unique()
            ->values()
            ->toArray();

        $user = Auth::user();

        // Ensure the wordbox belongs to the authenticated user
        $wordbox = $user->wordboxes()->where('id', $id)->firstOrFail();

        // Get currently mapped card IDs in the wordbox
        $existingCardIds = $wordbox->cards()->pluck('cards.id')->toArray();

        // Determine cards to remove (exist in DB but not in updated list)
        $cardsToRemove = array_diff($existingCardIds, $updatedCardIds);
        foreach ($cardsToRemove as $cardId) {
            $wordbox->cards()->detach($cardId);
        }

        // Determine new cards to add (exist in updated list but not in DB)
        $cardsToAdd = array_diff($updatedCardIds, $existingCardIds);
        foreach ($cardsToAdd as $cardId) {
            $wordbox->cards()->attach($cardId);
        }

        return redirect()->route('wordbox.show', ['id' => $id]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    // WordboxController.php

    public function generateGapFill(Wordbox $wordbox)
    {
        $cards = $wordbox->cards;
        if ($cards->count() < 3) {
            return back()->with('error', 'You need at least 3 cards in the wordbox to generate a gap-fill exercise.');
        }

        $phrases = implode(', ', $cards->pluck('phrase')->toArray());
        $targetLanguage = auth()->user()->target_language;

        $result = AI::generateTextWithGaps($phrases, $targetLanguage, $wordbox->name);

        if ($result) {
            // Directly return the generated data to the view
            return view('gapfill.show', [
                'textWithGaps' => $result['text_with_gaps'],
                'usedWords' => $result['used_words'],
                'wordbox' => $wordbox,
            ]);
        } else {
            return back()->with('error', 'Failed to generate gap-fill exercise.');
        }
    }


}
