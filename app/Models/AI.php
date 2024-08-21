<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class AI extends Model
{
    use HasFactory;

    public static function getContentForCard(string $phrase, string $themes): string
    {
        logger('Obtaining data for ' . $phrase);
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            "model" => "gpt-4o-2024-08-06",
            "messages" => [
                [
                    "role" => "system",
                    "content" => "Generate content for a flashcard based on the phrase given and the native and target language of the user."
                ],
                [
                    "role" => "user",
                    "content" => "Phrase: \"{{$phrase}}\" (correct the spelling if necessary) Native language: \"Czech\" Target language: \"English\""
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
                                    "description" => "Create a simple sentence in the target language using the phrase given and enclose the phrase in square brackets. Use simple language so even non-native speaker will understand it."
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
                                    "description" => "Choose the most suitable theme for the phrase given from these themes: {{$themes}}. If the expression doesn't fit into any theme or there are no themes, write miscellaneous. Write the exact word from the list."
                                ]
                            ],
                            "required" => ["sentence", "question", "translation", "definition", "theme"],
                            "additionalProperties" => false
                        ]
                    ]
            ]
        ]);

        if($response->json('choices.0.message.refusal') != null) {
            //handle this situation
            return '';
        }

        return $response->json('choices.0.message.content');
    }
}
