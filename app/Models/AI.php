<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AI extends Model
{
    use HasFactory;

    /**
     * Chat model used for generating flashcard content.
     * GPT-5.4 nano: fast and cheap, with reliable structured outputs.
     */
    private const MODEL = 'gpt-5.4-nano';

    /**
     * Reasoning effort for the chat model. "low" keeps latency/cost down
     * while still letting the model craft good recall questions.
     */
    private const REASONING_EFFORT = 'low';

    public static function getEmbedding(string $text): ?array
    {
        $response = Http::withToken(config('services.openai.secret'))
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => 'text-embedding-3-small',
                'input' => $text,
            ]);

        if (! $response->successful()) {
            Log::error('Embedding request failed: '.$response->status().' - '.$response->body());

            return null;
        }

        return $response->json('data.0.embedding');
    }

    public static function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        for ($i = 0, $n = count($a); $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot;
    }

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

            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a vocabulary tutor building flashcard content for a language learner. Follow every field rule exactly, especially the square-bracket formatting.',
                ],
                [
                    'role' => 'user',
                    'content' => "Term: \"{$phrase}\" (fix the spelling if it is wrong). Target language (write content in this): \"{$targetLanguage}\". Native language (used only for the translation): \"{$nativeLanguage}\".",
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
                            'phrase' => [
                                'type' => 'string',
                                'description' => 'The term provided by the user, corrected for spelling and in its base/dictionary form.',
                            ],
                            'sentence' => [
                                'type' => 'string',
                                'description' => "One short, natural sentence in {$targetLanguage} whose context makes the term's meaning clear. Wrap the term in square brackets exactly once, e.g. \"She gave me a [warm welcome].\" Use easy language for learners.",
                            ],
                            'question' => [
                                'type' => 'string',
                                'description' => "A short question in {$targetLanguage}, like a teacher testing vocabulary, whose single correct answer is the term itself. Unlike the definition, point to the typical usage of the term or a situation so it can be recalled. Never write the term (or an obvious form of it) in the question.",
                            ],
                            'translation' => [
                                'type' => 'string',
                                'description' => "The term translated into {$nativeLanguage}. Give up to two common alternatives separated by a semicolon.",
                            ],
                            'definition' => [
                                'type' => 'string',
                                'description' => "A concise dictionary-style definition of the term in {$targetLanguage}. Do not use the term itself in the definition.",
                            ],
                            'theme' => [
                                'type' => 'string',
                                'description' => "Pick the single best-fitting category from this list: \"{$themes}\" (copy it exactly). If none fit, create a short new category.",
                            ],
                        ],
                        'required' => ['phrase', 'sentence', 'question', 'translation', 'definition', 'theme'],
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

            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a vocabulary tutor building flashcard content for a language learner. Keep the meaning of the term consistent with the supplied context. Follow every field rule exactly, especially the square-bracket formatting.',
                ],
                [
                    'role' => 'user',
                    'content' => "Term: \"{$phrase}\" (fix the spelling if it is wrong), used in this context: \"{$context}\". Target language (write content in this): \"{$targetLanguage}\". Native language (used only for the translation): \"{$nativeLanguage}\".",
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
                            'phrase' => [
                                'type' => 'string',
                                'description' => 'A 2-4 word expression that includes the term and captures the broader, dictionary-like meaning it carries in the context (not the specific subject). Use base forms of the words.',
                            ],
                            'sentence' => [
                                'type' => 'string',
                                'description' => "One short, natural sentence in {$targetLanguage} whose context makes the term's meaning (as used in the supplied context) clear. Wrap the term in square brackets exactly once, e.g. \"She gave me a [warm welcome].\" Use easy language for learners.",
                            ],
                            'question' => [
                                'type' => 'string',
                                'description' => "A short question in {$targetLanguage}, like a teacher testing vocabulary, whose single correct answer is the term itself. Unlike the definition, point to the typical usage of the term or a situation so it can be recalled. Never write the term (or an obvious form of it) in the question.",
                            ],
                            'translation' => [
                                'type' => 'string',
                                'description' => "The term translated into {$nativeLanguage}, matching its meaning in the context. Give up to two common alternatives separated by a semicolon.",
                            ],
                            'definition' => [
                                'type' => 'string',
                                'description' => "A concise dictionary-style definition of the term in {$targetLanguage}, based on the context. Do not use the term itself in the definition.",
                            ],
                            'theme' => [
                                'type' => 'string',
                                'description' => "Pick the single best-fitting category from this list: \"{$themes}\" (copy it exactly). If none fit, create a short new category.",
                            ],
                        ],
                        'required' => ['phrase', 'sentence', 'question', 'translation', 'definition', 'theme'],
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

            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You group a learner\'s vocabulary into a small set of meaningful theme decks. Write the theme names in the given language.',
                ],
                [
                    'role' => 'user',
                    'content' => "Phrases: \"{$phrases}\". Language: \"{$targetLanguage}\".",
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
                                'description' => 'Up to 10 broad themes that cover the phrases so each phrase fits into one theme.',
                                'items' => [
                                    '$ref' => '#/$defs/theme',
                                ],
                            ],
                        ],
                        'required' => ['themes'],
                        'additionalProperties' => false,
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
            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a language learning expert. Write a short, coherent story in the target language that naturally uses every provided phrase. Replace each used phrase in the text with a numbered placeholder [1], [2], … (numbered in order of appearance) and return the mapping of each placeholder to the exact phrase it replaced.',
                ],
                [
                    'role' => 'user',
                    'content' => "Wordbox name: \"{$wordboxName}\". Target language: \"{$targetLanguage}\".{$themePrompt} Phrases to use: \"{$phrases}\". Write the story in {$targetLanguage}, replacing each phrase with its [n] placeholder.",
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
