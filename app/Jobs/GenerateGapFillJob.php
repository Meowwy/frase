<?php

namespace App\Jobs;

use App\Models\AI;
use App\Models\BaseWord;
use App\Models\GapFillExercise;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateGapFillJob implements ShouldQueue
{
    use Queueable;

    // A local `queue:work` kills a job after 60 seconds by default; the AI call may take longer.
    public int $timeout = AI::GAP_FILL_TIMEOUT + 5;

    public function __construct(
        public GapFillExercise $exercise
    ) {}

    public function handle(): void
    {
        try {
            $this->exercise->update(['status' => 'processing']);

            $wordbox = $this->exercise->wordbox;
            $user = $wordbox->user;

            // The words ticked for this exercise that are still in the box and the base.
            $words = $wordbox->baseWords()->with('language')->whereKey($this->exercise->base_word_ids)->get()
                ->map(fn (BaseWord $word) => [
                    'base_word_id' => $word->id,
                    'lemma' => $word->lemma,
                    'display_form' => $word->displayForm(),
                    'part_of_speech' => $word->part_of_speech,
                    'grammar' => $word->grammar_attributes ?? [],
                    'translation' => $word->translation,
                ])
                ->all();

            if (empty($words)) {
                $this->exercise->update(['status' => 'failed']);

                return;
            }

            // Match the exercise difficulty to the user's proficiency in this language.
            $level = $user->levelForLanguage($wordbox->language) ?? config('proficiency.default');

            // A beginner's story is built from words they know: up to 30 other words of theirs.
            $familiarWords = in_array($level, AI::GAP_FILL_BASIC_LEVELS, true)
                ? $user->baseWords()->where('language_id', $wordbox->language_id)
                    ->whereNotIn('id', array_column($words, 'base_word_id'))
                    ->inRandomOrder()->limit(30)->get()
                    ->map(fn (BaseWord $word) => "{$word->lemma} ({$word->translation})")->all()
                : [];

            $result = AI::generateTextWithGaps($words, $wordbox->language->name, $this->exercise->theme_preference, $level, $familiarWords);

            if ($result) {
                $this->exercise->update([
                    'title' => $result['title'],
                    'text_with_gaps' => $result['text'],
                    'correct_answers' => $result['answers'],
                    'status' => 'completed',
                ]);
            } else {
                $this->exercise->update(['status' => 'failed']);
            }
        } catch (\Throwable $e) {
            Log::error('Gap fill generation failed: '.$e->getMessage());
            $this->exercise->update(['status' => 'failed']);
        }
    }
}
