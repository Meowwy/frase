<?php

namespace App\Jobs;

use App\Models\AI;
use App\Models\GapFillExercise;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateGapFillJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public GapFillExercise $exercise
    ) {}

    public function handle(): void
    {
        try {
            $this->exercise->update(['status' => 'processing']);

            $wordbox = $this->exercise->wordbox;
            $user = $wordbox->user;

            // Limit to max 30 random words
            $phrases = $wordbox->cards()
                ->inRandomOrder()
                ->limit(30)
                ->pluck('phrase')
                ->implode(', ');

            if (empty($phrases)) {
                $this->exercise->update(['status' => 'failed']);

                return;
            }

            $targetLanguage = $user->target_language ?? 'English';

            $result = AI::generateTextWithGaps(
                $phrases,
                $targetLanguage,
                $wordbox->name,
                $this->exercise->theme_preference
            );

            if ($result) {
                $this->exercise->update([
                    'text_with_gaps' => $result['text'],
                    'correct_answers' => $result['answers'],
                    'status' => 'completed',
                ]);
            } else {
                $this->exercise->update(['status' => 'failed']);
            }
        } catch (\Exception $e) {
            Log::error('Gap fill generation failed: '.$e->getMessage());
            $this->exercise->update(['status' => 'failed']);
        }
    }
}
