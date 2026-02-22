<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateGapFillJob;
use App\Models\GapFillExercise;
use App\Models\Wordbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GapFillExerciseController extends Controller
{
    public function store(Request $request, Wordbox $wordbox)
    {
        // Gate check if needed - assuming standard Laravel setup
        // Gate::authorize('update', $wordbox);

        $exercise = $wordbox->gapFillExercises()->create([
            'theme_preference' => $request->theme_preference,
            'status' => 'pending',
        ]);

        GenerateGapFillJob::dispatch($exercise);

        return back()->with('message', 'Exercise generation started!');
    }

    public function show(GapFillExercise $exercise)
    {
        // Gate::authorize('view', $exercise);

        if ($exercise->status !== 'completed') {
            return view('gap-fill.processing', compact('exercise'));
        }

        $allExercises = $exercise->wordbox->gapFillExercises()
            ->orderBy('created_at', 'asc')
            ->get();

        return view('gap-fill.show', compact('exercise', 'allExercises'));
    }

    public function status(GapFillExercise $exercise)
    {
        return response()->json([
            'status' => $exercise->status,
            'url' => route('gap-fill.show', $exercise),
        ]);
    }
}
