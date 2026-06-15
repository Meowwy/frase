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
    private const REASONING_EFFORT = 'medium';

    /**
     * Build a strong instruction fragment that constrains the language the model
     * produces to the learner's CEFR proficiency level. Returns an empty string
     * when no (or an unknown) level is given, so prompts stay unchanged.
     */
    private static function levelInstruction(?string $level): string
    {
        if (! $level) {
            return '';
        }

        $description = config("proficiency.levels.{$level}");
        if (! $description) {
            return '';
        }

        return " CRITICAL — the learner's proficiency in the target language is CEFR level {$level}: {$description} You MUST strictly keep all vocabulary, grammar and sentence length at this level and never above it. If a given term is harder than this level you may still use that exact term, but every other word around it must stay at level {$level}.";
    }

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

    public static function getContentForCard(string $phrase, string $themes, string $targetLanguage, string $nativeLanguage, ?string $level = null)
    {
        logger('update 2');
        logger('Obtaining data for '.$phrase);
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a vocabulary tutor turning a learner's Term into one flashcard for learning vocabulary in context. First decide the card's phrase, then write every other field to describe that exact phrase, never the original Term, if it is only one word. Write all content in the target language, except the translation. Follow each field's rules exactly. The examples are in English, but it is meant in general to other languages as well.".self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => "Original term: \"{$phrase}\" (fix the spelling if it is wrong). Target language (write content in this): \"{$targetLanguage}\". Native language (used only for the translation): \"{$nativeLanguage}\".",
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
                                'description' => 'The phrase this card teaches. If the Original term is already a phrase, keep it; if it is a single word, expand it into a natural collocation (max 3 words) by pairing it with at least one extra CONTENT word — a meaningful noun, verb, or adjective it commonly appears with. The added meaning must come from a content word, never only from grammar words (articles, prepositions, conjunctions, auxiliaries such as a, the, to, that, on, be). Examples: book => read a book, frugal => frugal lifestyle, imply => imply guilt. Fix spelling and use the base/dictionary form. Decide this first: every other field must describe THIS phrase, not the original Term.',
                            ],
                            'sentence' => [
                                'type' => 'string',
                                'description' => "One short, natural {$targetLanguage} sentence whose context makes the phrase's meaning clear. Wrap the phrase in square brackets exactly once, e.g. \"She gave me a [warm welcome].\" Use easy language for learners.",
                            ],
                            'question' => [
                                'type' => 'string',
                                'description' => "A short recall cue in {$targetLanguage} that points to the phrase without naming it — may be a brief situation, statement, or question, whatever fits best. Anchor it to a real-life situation or to words closely tied to the phrase so the learner recalls it from context, e.g. \"read a book\" => \"what you do in a library\". You may reuse the phrase's own words, e.g. \"frugal lifestyle\" => \"a way of living that spends only as much money as necessary\". Keep it short, no filler. Never write the original term or an obvious form of it.",
                            ],
                            'translation' => [
                                'type' => 'string',
                                'description' => "The phrase translated into {$nativeLanguage}; at most two common variants separated by a semicolon.",
                            ],
                            'definition' => [
                                'type' => 'string',
                                'description' => "A concise dictionary-style definition of the phrase in {$targetLanguage}. Do not use the phrase itself in the definition.",
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

    public static function getContentForCardWithContext(string $phrase, string $themes, string $targetLanguage, string $nativeLanguage, string $context, ?string $level = null)
    {
        logger('update 2');
        logger('Obtaining data for '.$phrase);
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a vocabulary tutor turning a learner's Term (seen in a specific context) into one flashcard for learning vocabulary in context. First decide the card's phrase, capturing the meaning the Term has in that context but in a general dictionary form; then write every other field to describe that exact phrase. Write all content in the target language, except the translation. Follow each field's rules exactly. The examples are in English, but it is meant in general to other languages as well.".self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => "Original term: \"{$phrase}\" (fix the spelling if it is wrong), used in this context: \"{$context}\". Target language (write content in this): \"{$targetLanguage}\". Native language (used only for the translation): \"{$nativeLanguage}\".",
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
                                'description' => 'The phrase this card teaches. If the Original term is already a phrase, keep it; if it is a single word, expand it into a natural collocation (max 3 words) by pairing it with at least one extra CONTENT word — a meaningful noun, verb, or adjective it commonly appears with. The added meaning must come from a content word, never only from grammar words (articles, prepositions, conjunctions, auxiliaries such as a, the, to, that, on, be). Examples: book => read a book, frugal => frugal lifestyle, imply => imply guilt. Keep the meaning it has in the context but in a general, dictionary-style form (not tied to the specific subject). Fix spelling and use base forms. Decide this first: every other field must describe THIS phrase.',
                            ],
                            'sentence' => [
                                'type' => 'string',
                                'description' => "One short, natural {$targetLanguage} sentence whose context makes the phrase's meaning (as used in the supplied context) clear. Wrap the phrase in square brackets exactly once, e.g. \"She gave me a [warm welcome].\" Use easy language for learners.",
                            ],
                            'question' => [
                                'type' => 'string',
                                'description' => "A short recall cue in {$targetLanguage} that points to the phrase without naming it, matching its meaning in the context — may be a brief situation, statement, or question, whatever fits best. Anchor it to a real-life situation or to words closely tied to the phrase so the learner recalls it from context, e.g. \"read a book\" => \"what you do in a library\". You may reuse the phrase's own words, e.g. \"frugal lifestyle\" => \"a way of living that spends only as much money as necessary\". Keep it short, no filler. Never write the original term or an obvious form of it.",
                            ],
                            'translation' => [
                                'type' => 'string',
                                'description' => "The phrase translated into {$nativeLanguage}, matching its meaning in the context; at most two common variants separated by a semicolon.",
                            ],
                            'definition' => [
                                'type' => 'string',
                                'description' => "A concise dictionary-style definition of the phrase in {$targetLanguage}, based on the context. Do not use the phrase itself in the definition.",
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

    public static function generateTextWithGaps(string $phrases, string $targetLanguage, string $wordboxName, ?string $themePreference = null, ?string $level = null): ?array
    {
        Log::info('Generating text with gaps for wordbox: '.$wordboxName);
        $themePrompt = $themePreference ? " Theme preference: \"{$themePreference}\"." : '';

        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a language learning expert. Write a short, coherent story in the target language that naturally works in every provided phrase. You do not have to use each phrase word-for-word: adapt its form (inflection, conjugation, or a natural variant) so the text reads naturally, but keep the same meaning and context the phrase carries as a vocabulary item. Replace the part of the text that corresponds to each phrase with a numbered placeholder [1], [2], … (numbered in order of appearance) and return, for each placeholder, the exact text that belongs in that gap. Also give the story a short title (max 5 words) in the target language that reflects its content.'.self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => "Wordbox name: \"{$wordboxName}\". Target language: \"{$targetLanguage}\".{$themePrompt} Phrases to use: \"{$phrases}\". Write the story in {$targetLanguage}, replacing each phrase (or its adapted form) with its [n] placeholder.",
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
                            'title' => [
                                'type' => 'string',
                                'description' => 'A short title for the story (max 5 words) in the target language.',
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
                                            'description' => 'The exact text that belongs in this gap (the adapted form actually used, if you changed it).',
                                        ],
                                    ],
                                    'required' => ['index', 'phrase'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['text', 'title', 'answers'],
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
