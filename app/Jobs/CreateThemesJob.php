<?php

namespace App\Jobs;

use App\Models\AI;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class CreateThemesJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $userId, public string $phrases)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);

        $phrases = Auth::user()->phrases()
            ->orderBy('created_at', 'desc')  // Sort by creation date, latest first
            ->limit(100)                      // Limit to the latest 100 entries
            ->pluck('phrase');                // Assuming the column name is 'phrase'

// Convert the collection of phrases into a semicolon-separated string
        $phraseString = $phrases->implode('; ');
        $themes = AI::generateThemes($phraseString, $user->target_language);

        dd($themes);
    }
}
