<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class AI extends Model
{
    use HasFactory;

    public static function getContentForCard(string $phrase): string
    {
        //dispatch(function () use ($phrase) {
        logger('Obtaining data for ' . $phrase);
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            "model" => "gpt-4o-2024-08-06",
            "messages" => [
                [
                    "role" => "system",
                    "content" => "You will generate content based on the phrase given and the native and target language of the user."
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
                                    "description" => "Create a short question in the target language that should prompt the user to remember the phrase given. Make it conversational and concise. Use simple language so even non-native speaker will understand it."
                                ],
                                "translation" => [
                                    "type" => "string",
                                    "description" => "Translate the phrase into the native language, if possible with 2 different alternatives separated by a semicolon"
                                ],
                                "definition" => [
                                    "type" => "string",
                                    "description" => "Write a short definition for the phrase given in the target language."
                                ],
                            ],
                            "required" => ["sentence", "question", "translation", "definition"],
                            "additionalProperties" => false
                        ]
                    ]
            ]
        ]);

        if($response->json('choices.0.message.refusal') != null) {
            //handle this situation
        }

        return $response->json('choices.0.message.content');
        //});
    }
}
