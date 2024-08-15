<?php

namespace App\Jobs;

use App\Models\AI;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateCardJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $userId, public string $phrase)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $content = AI::getContentForCard($this->phrase);
        $output = json_decode($content);

        $user = User::find($this->userId);

        $user->cards()->create([
            'phrase' => $this->phrase,
            'translation' => $output->translation,
            'example_sentence' => $output->sentence,
            'question' => $output->question,
            'definition' => $output->definition,
            'next_study_at' => now()
        ]);
        logger('Card has been created');
    }
}
