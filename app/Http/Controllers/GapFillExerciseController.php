<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateGapFillJob;
use App\Models\BaseWord;
use App\Models\GapFillExercise;
use App\Models\Wordbox;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GapFillExerciseController extends Controller
{
    /**
     * Queue an exercise over the ticked words — the wordbox's own, at most 30, as many as one
     * short story can carry.
     */
    public function store(Request $request, Wordbox $wordbox)
    {
        $this->authorize('update', $wordbox);

        $data = $request->validate([
            'base_word_ids' => ['required', 'array', 'max:30'],
            'base_word_ids.*' => ['integer', 'distinct', Rule::in($wordbox->baseWords()->pluck('base_words.id'))],
            'theme_preference' => ['nullable', 'string', 'max:255'],
        ]);

        $exercise = $wordbox->gapFillExercises()->create([
            'base_word_ids' => array_map('intval', $data['base_word_ids']),
            'theme_preference' => $data['theme_preference'] ?? null,
            'status' => 'pending',
        ]);

        GenerateGapFillJob::dispatch($exercise);

        return redirect()->route('gap-fill.show', $exercise)
            ->with('message', 'Exercise generation started!');
    }

    public function show(GapFillExercise $exercise)
    {
        $this->authorize('view', $exercise);

        $allExercises = $exercise->wordbox->gapFillExercises()
            ->orderBy('created_at', 'asc')
            ->get();

        // The word bank: each gap's base word, unless it has been deleted from the base since.
        $words = BaseWord::with('language')
            ->findMany(collect($exercise->correct_answers)->pluck('base_word_id'))
            ->keyBy('id');

        return view('gap-fill.show', compact('exercise', 'allExercises', 'words'));
    }

    public function status(GapFillExercise $exercise)
    {
        $this->authorize('view', $exercise);

        return response()->json([
            'status' => $exercise->status,
            'url' => route('gap-fill.show', $exercise),
        ]);
    }

    public function destroy(GapFillExercise $exercise)
    {
        $this->authorize('delete', $exercise);

        $wordboxId = $exercise->wordbox_id;
        $exercise->delete();

        return redirect()->route('wordbox.show', $wordboxId)
            ->with('message', 'Exercise deleted.');
    }
}
