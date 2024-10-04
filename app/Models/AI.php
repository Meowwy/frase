<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class AI extends Model
{
    use HasFactory;

    public static function test()
    {
        logger('update1');
        logger('testing AI');
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            "model" => "gpt-4o-2024-08-06",
            "messages" => [
                [
                    "role" => "system",
                    "content" => "Generate a song."
                ],
                [
                    "role" => "user",
                    "content" => "the user is a kid"
                ]
            ],
            "response_format" => [
                "type" => "json_schema",
                "json_schema" => [
                    "name" => "get_song",
                    "strict" => true,
                    "schema" => [
                        "type" => "object",
                        "properties" => [
                            "song" => [
                                "type" => "string",
                                "description" => "Create a short song that I can sing to my kid."
                            ]
                        ],
                        "required" => ["song"],
                        "additionalProperties" => false
                    ]
                ]
            ]
        ]);
        if ($response->json('choices.0.message.refusal') != null) {
            logger('logging ae error message');
            logger($response->json('choices.0.message.refusal'));
            return '';
        }

        return $response->json('choices.0.message.content');
    }

    public static function getContentForCard(string $phrase, string $themes, string $targetLanguage, string $nativeLanguage)
    {
        logger('update 2');
        logger('Obtaining data for ' . $phrase);
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            "model" => "gpt-4o-2024-08-06",
            "messages" => [
                [
                    "role" => "system",
                    "content" => "Generate content for a flashcard based on the term given and the native and target language of the user."
                ],
                [
                    "role" => "user",
                    "content" => "Term: \"{{$phrase}}\" (correct the spelling if necessary) Native language: \"{{$nativeLanguage}}\" Target language: \"{{$targetLanguage}}\""
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
                                "description" => "Create a simple sentence in the target language that includes the term in square brackets. Ensure the context created by the sentence is accurate enough to allow the user to understand the meaning of the term even if the user doesn't know what the term means. Use easy language for non-native speakers."
                            ],
                            "question" => [
                                "type" => "string",
                                "description" => "Create a short question in the target language that should prompt the user to remember the term given. Make it conversational and concise."
                            ],
                            "translation" => [
                                "type" => "string",
                                "description" => "Translate the term into the native language, if possible with 2 different alternatives separated by a semicolon."
                            ],
                            "definition" => [
                                "type" => "string",
                                "description" => "Write a short definition for the term given in the target language."
                            ],
                            "theme" => [
                                "type" => "string",
                                "description" => "Decide if the term fits info any of these categories: \"{{$themes}}\". If so, write the exact category name from the list. If not or there are no categories, write an empty string."
                            ],
                            "phrase" => [
                                "type" => "string",
                                "description" => "Term provided by user, corrected for misspelling."
                            ]
                        ],
                        "required" => ["sentence", "question", "translation", "definition", "theme", "phrase"],
                        "additionalProperties" => false
                    ]
                ]
            ]
        ]);

        return $response->json('choices.0.message.content');
        //return $response;
    }

    public static function generateThemes(string $phrases, string $targetLanguage)
    {
        logger('Generating themes.');
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            "model" => "gpt-4o-2024-08-06",
            "messages" => [
                [
                    "role" => "system",
                    "content" => "Generate themes in the language given to help user group his phrases into meaningful decks.."
                ],
                [
                    "role" => "user",
                    "content" => "Phrases: \"{{$phrases}}\" Language: \"{{$targetLanguage}}\""
                ]
            ],
            "response_format" => [
                "type" => "json_schema",
                "json_schema" => [
                    "name" => "generate_themes",
                    "strict" => true,
                    "schema" => [
                        "type" => "object",
                        "properties" => [
                            "themes" => [
                                "type" => "array",
                                "description" => "Up to 10 themes that we can categorize the phrases so that each phrase belongs to one theme. ",
                                "items" => [
                                    "\$ref" => "#/\$defs/theme"
                                ]
                            ],
                            "required" => ["themes"],
                            "additionalProperties" => false
                        ],
                        "\$defs" => [
                            "theme" => [
                                "type" => "string"
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        if ($response->json('choices.0.message.refusal') != null) {
            //handle this situation
            return '';
        }

        return $response;
    }
}
