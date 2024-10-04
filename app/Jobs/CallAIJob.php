<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class CallAIJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $userId, public string $phrase, public string $nativeLanguage, public string $targetLanguage, public string $themes)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            "model" => "gpt-4o-2024-08-06",
            "messages" => [
                [
                    "role" => "system",
                    "content" => "Generate content for a flashcard based on the phrase given and the native and target language of the user."
                ],
                [
                    "role" => "user",
                    "content" => "Phrase: \"{{$this->phrase}}\" (correct the spelling if necessary) Native language: \"{{$this->nativeLanguage}}\" Target language: \"{{$this->targetLanguage}}\""
                ]
            ],
            "response_format" => [
                "type" => "json_schema",
                "json_schema" => [
                    "name" => "get_information_for_card",
                    "strict" => true,
                    "schema" => [
                        "type" => "object",
                        "properties" => [
                            "sentence" => [
                                "type" => "string",
                                "description" => "Create a simple sentence in the target language that includes the phrase in square brackets. Ensure the context created by the sentence allows the user to understand the meaning of the phrase even if the user doesn't know what the phrase means. Use easy language for non-native speakers."
                            ],
                            "question" => [
                                "type" => "string",
                                "description" => "Create a short question in the target language that should prompt the user to remember the phrase given. Make it conversational and concise."
                            ],
                            "translation" => [
                                "type" => "string",
                                "description" => "Translate the phrase into the native language, if possible with 2 different alternatives separated by a semicolon."
                            ],
                            "definition" => [
                                "type" => "string",
                                "description" => "Write a short definition for the phrase given in the target language."
                            ],
                            "theme" => [
                                "type" => "string",
                                "description" => "Choose the most suitable theme for the phrase given from these themes: \"{{$this->themes}}\". Write the exact term from the list. If the expression doesn't fit into any theme or there are no themes, write an empty string."
                            ],
                            "phrase" => [
                                "type" => "string",
                                "description" => "Phrase provided by user, corrected for misspelling."
                            ]
                        ],
                        "required" => ["sentence", "question", "translation", "definition", "theme", "phrase"],
                        "additionalProperties" => false
                    ]
                ]
            ]
        ]);
        $content = $response->json('choices.0.message.content');

        $cleanedContent = trim($content);
        $output = json_decode($cleanedContent);

        $user = User::find($this->userId);

        $user->currency_amount = $user->currency_amount - 1;
        if ($user->currency_amount < 0) {
            $user->currency_amount = 0;
        }
        $user->save();

        try {
            $selectedTheme = $this->themes->firstWhere('name', $output->theme);

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
