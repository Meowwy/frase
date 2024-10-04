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
        $user = User::find($this->userId);

        // Retrieve all themes of the authenticated user
        $themes = $user->themes()->select('id', 'name')->get();


        if(count($themes) !== 0){
            $themeStrings = $themes->map(function ($theme) {
                return "\"{$theme->name}\"";
            });
            $themeString = $themeStrings->implode(',');
        }else{
            $themeString = '';
        }

        $content = AI::getContentForCard($this->phrase, $themeString, $user->target_language, $user->native_language);
        if(is_null($content)){
            logger('The model refused to create the card for '.$this->phrase);
            return;
        }
        $cleanedContent = trim($content);
        $output = json_decode($cleanedContent);
        $user->currency_amount = $user->currency_amount - 1;
        if ($user->currency_amount < 0) {
            $user->currency_amount = 0;
        }
        $user->save();

        try {
            $selectedTheme = $themes->firstWhere('name', $output->theme);

            $user->cards()->create([
                'phrase' => $output->phrase,
                'theme_id' => ($selectedTheme ? $selectedTheme->id : null),
                'level' => 1,
                'translation' => $output->translation,
                'example_sentence' => $output->sentence,
                'question' => $output->question,
                'definition' => $output->definition,
                'next_study_at' => now()
            ]);
            logger('Card has been created for '.$this->phrase);
        } catch(\Exception $e) {
            logger($e->getMessage());
        }

    }
}
