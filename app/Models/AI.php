<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AI extends Model
{
    use HasFactory;

    public static function test()
    {
        logger('update1');
        logger('testing AI');
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            'model' => 'gpt-4o-2024-08-06',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Generate a song.',
                ],
                [
                    'role' => 'user',
                    'content' => 'the user is a kid',
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'get_song',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'song' => [
                                'type' => 'string',
                                'description' => 'Create a short song that I can sing to my kid.',
                            ],
                        ],
                        'required' => ['song'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
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
        logger('Obtaining data for '.$phrase);
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            'model' => 'gpt-4o-2024-08-06',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Generate content for a flashcard based on the term given and the native and target language of the user.',
                ],
                [
                    'role' => 'user',
                    'content' => "Term: \"{{$phrase}}\" (correct the spelling if necessary) Native language: \"{{$nativeLanguage}}\" Target language: \"{{$targetLanguage}}\"",
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'get_information_for_card',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'sentence' => [
                                'type' => 'string',
                                'description' => "Make up a simple sentence in {{$targetLanguage}} language using the term {{$phrase}}. Enclose the term in square brackets []. Keep the language easy for non-native speakers.",
                            ],
                            'question' => [
                                'type' => 'string',
                                'description' => "In {{$targetLanguage}}, create a short, conversational question that imitate a situation that should prompt the user to recall the term. It must have a simple answer - the term.",
                            ],
                            'translation' => [
                                'type' => 'string',
                                'description' => "Translate the term into {{$nativeLanguage}} language, providing two alternatives if applicable, separated by a semicolon.",
                            ],
                            'definition' => [
                                'type' => 'string',
                                'description' => "Provide a concise dictionary definition in {{$targetLanguage}} language for the term. Do not include the term in the definition.",
                            ],
                            'theme' => [
                                'type' => 'string',
                                'description' => "Pick a broad category for the term, here is inspiration: \"{{$themes}}\". Pick one category (write the exact thing from the list) or create a new one if all of them aren't applicable.",
                            ],
                            'phrase' => [
                                'type' => 'string',
                                'description' => 'Term provided by user, corrected for misspelling.',
                            ],
                        ],
                        'required' => ['sentence', 'question', 'translation', 'definition', 'theme', 'phrase'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);

        return $response->json('choices.0.message.content');
        // return $response;
    }

    public static function getContentForCardWithContext(string $phrase, string $themes, string $targetLanguage, string $nativeLanguage, string $context)
    {
        logger('update 2');
        logger('Obtaining data for '.$phrase);
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            'model' => 'gpt-4o-2024-08-06',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Generate content for a flashcard based on the term given, the native and target language of the user and the context of the term.',
                ],
                [
                    'role' => 'user',
                    'content' => "Term: \"{{$phrase}}\" (correct the spelling if necessary) in this context: \"{{$context}}\"  Native language: \"{{$nativeLanguage}}\" Target language: \"{{$targetLanguage}}\"",
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'get_information_for_card_with_context',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'sentence' => [
                                'type' => 'string',
                                'description' => "Make up a simple sentence in {{$targetLanguage}} language using the term {{$phrase}}. Enclose the term in square brackets []. Keep the meaning of the term the same as in the context. Keep the language easy for non-native speakers.",
                            ],
                            'question' => [
                                'type' => 'string',
                                'description' => "In {{$targetLanguage}}, create a short, conversational question that imitate a situation that should prompt the user to recall the term. It must have a simple answer - the term.",
                            ],
                            'translation' => [
                                'type' => 'string',
                                'description' => "Translate the term into {{$nativeLanguage}} language, providing two alternatives if applicable, separated by a semicolon. Ensure the translation is also a phrase that aligns with the meaning of the term in the context.",
                            ],
                            'definition' => [
                                'type' => 'string',
                                'description' => "Provide a concise dictionary definition in {{$targetLanguage}} language for the term based on the context. Do not include the term in the definition.",
                            ],
                            'theme' => [
                                'type' => 'string',
                                'description' => "Pick a broad category for the term, here is inspiration: \"{{$themes}}\". Pick one category (write the exact thing from the list) or create a new one if all of them aren't applicable.",
                            ],
                            'phrase' => [
                                'type' => 'string',
                                'description' => 'Create a 2-4 word expression that must include the term and capture a general meaning relevant to the context. The phrase should convey an abstract, broader meaning, like in a dictionary, rather than a specific subject. Use base forms of the words.',
                            ],
                        ],
                        'required' => ['sentence', 'question', 'translation', 'definition', 'theme', 'phrase'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);

        return $response->json('choices.0.message.content');
        // return $response;
    }

    public static function generateThemes(string $phrases, string $targetLanguage)
    {
        logger('Generating themes.');
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            'model' => 'gpt-4o-2024-08-06',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Generate themes in the language given to help user group his phrases into meaningful decks..',
                ],
                [
                    'role' => 'user',
                    'content' => "Phrases: \"{{$phrases}}\" Language: \"{{$targetLanguage}}\"",
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'generate_themes',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'themes' => [
                                'type' => 'array',
                                'description' => 'Up to 10 themes that we can categorize the phrases so that each phrase belongs to one theme. ',
                                'items' => [
                                    '$ref' => '#/$defs/theme',
                                ],
                            ],
                            'required' => ['themes'],
                            'additionalProperties' => false,
                        ],
                        '$defs' => [
                            'theme' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        if ($response->json('choices.0.message.refusal') != null) {
            // handle this situation
            return '';
        }

        return $response;
    }

    public static function generateTextWithGaps(string $phrases, string $targetLanguage, string $wordboxName, ?string $themePreference = null): ?array
    {
        Log::info('Generating text with gaps for wordbox: '.$wordboxName);
        $themePrompt = $themePreference ? " Theme preference: \"{$themePreference}\"." : '';

        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-2024-08-06',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a language learning expert. Create a coherent story using the provided phrases. Replace each phrase with a numbered placeholder like [1], [2]. Return a JSON object with the full text and a mapping of placeholders to the correct phrases.',
                ],
                [
                    'role' => 'user',
                    'content' => "Wordbox name: \"{$wordboxName}\". Target language: \"{$targetLanguage}\".{$themePrompt} Words/phrases to use: \"{$phrases}\". Create a short text in {$targetLanguage} with gaps using the phrases provided. Each gap should be replaced with [n] where n is the index starting from 1.",
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'generate_text_with_gaps',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => [
                                'type' => 'string',
                                'description' => 'The story with numbered placeholders [1], [2], etc.',
                            ],
                            'answers' => [
                                'type' => 'array',
                                'description' => 'A list of objects, each with an index and the correct phrase.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'index' => [
                                            'type' => 'integer',
                                            'description' => 'The placeholder index, e.g., 1 for [1].',
                                        ],
                                        'phrase' => [
                                            'type' => 'string',
                                            'description' => 'The correct phrase for this gap.',
                                        ],
                                    ],
                                    'required' => ['index', 'phrase'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['text', 'answers'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);

        if ($response->json('choices.0.message.refusal') != null) {
            Log::error('AI refused to generate text with gaps: '.$response->json('choices.0.message.refusal'));

            return null;
        }

        if (! $response->successful()) {
            Log::error('AI request failed: '.$response->status().' - '.$response->body());

            return null;
        }

        $data = json_decode($response->json('choices.0.message.content'), true);

        // Convert array of answers back to the expected key-value format if necessary
        if (isset($data['answers']) && is_array($data['answers']) && ! empty($data['answers']) && isset($data['answers'][0]['index'])) {
            $formattedAnswers = [];
            foreach ($data['answers'] as $answer) {
                $formattedAnswers[$answer['index']] = $answer['phrase'];
            }
            $data['answers'] = $formattedAnswers;
        }

        return $data;
    }
}
