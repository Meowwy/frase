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

        return " CRITICAL — the learner's proficiency is CEFR level {$level}: {$description} Keep all vocabulary and grammar at this level. If a given term is harder than this level you may still use it, but every other word around it must stay at level {$level}.";
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
                    'content' => "You are a vocabulary tutor turning a learner's Term into one flashcard for learning vocabulary in context. Keep the term itself as the word the learner submitted — only fix spelling and reduce it to its base/dictionary form; do not expand a single word into a phrase. Describe that exact term in every field. You MUST always fill the 'examples' array with exactly 3 short usage phrases that each show a different common way the term is used — never leave it empty. Each example is a 2-4 word fragment with NO subject pronoun and NO final period (e.g. \"run a business\", \"run out of time\" — NOT \"I run a business.\").".self::levelInstruction($level),
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
                                'description' => 'The term this card teaches, taken from the Original term. Fix any spelling mistakes and reduce it to its base/dictionary form (e.g. broken => break, running => run, mice => mouse). If the Original term is already a multi-word phrase, keep it as the learner wrote it (spelling/base-form fixes only). Do NOT expand a single word into a collocation — a one-word term stays one word. Every other field must describe THIS term.',
                            ],
                            'sentence' => [
                                'type' => 'string',
                                'description' => "One short, natural {$targetLanguage} sentence whose context makes the term's meaning clear. Wrap the term — in whatever form it appears in the sentence — in square brackets exactly once, e.g. \"She [broke] her promise.\" Use easy language for learners.",
                            ],
                            'examples' => [
                                'type' => 'array',
                                'description' => "An array of exactly 3 short usage fragments in {$targetLanguage} (2-4 words each) — NOT full sentences (no subject like \"I/she\", no final period) and NOT bracketed. You MUST always return 3. Each shows a DIFFERENT common way the term is used: (1) a different inflected form, (2) a preposition the term typically pairs with, (3) a frequent collocation or set phrase. For example, for \"run\": \"run a business\", \"run out of time\", \"go for a run\". Never use square brackets.",
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                            'translation' => [
                                'type' => 'string',
                                'description' => "The term translated into {$nativeLanguage}; at most two common variants separated by a semicolon.",
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
                        'required' => ['phrase', 'sentence', 'examples', 'translation', 'definition', 'theme'],
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
                    'content' => "You are a vocabulary tutor turning a learner's Term (seen in a specific context) into one flashcard for learning vocabulary in context. Keep the term itself as the word the learner submitted — only fix spelling and reduce it to its base/dictionary form; do not expand a single word into a phrase. The context fixes WHICH sense of the term this card is about, so every field — including all example fragments — must reflect ONLY that sense/domain and never a different meaning of the word. You MUST always fill the 'examples' array with exactly 3 short usage phrases that each show a different common way the term is used — never leave it empty. Each example is a 2-4 word fragment with NO subject pronoun and NO final period (e.g. \"run a business\", \"run out of time\" — NOT \"I run a business.\").".self::levelInstruction($level),
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
                                'description' => 'The term this card teaches, taken from the Original term. Fix any spelling mistakes and reduce it to its base/dictionary form (e.g. broken => break, running => run, mice => mouse), keeping the meaning it has in the supplied context but in a general, dictionary-style form (not tied to the specific subject). If the Original term is already a multi-word phrase, keep it as the learner wrote it (spelling/base-form fixes only). Do NOT expand a single word into a collocation — a one-word term stays one word. Every other field must describe THIS term.',
                            ],
                            'sentence' => [
                                'type' => 'string',
                                'description' => "One short, natural {$targetLanguage} sentence whose context makes the term's meaning (as used in the supplied context) clear. Wrap the term — in whatever form it appears in the sentence — in square brackets exactly once, e.g. \"She [broke] her promise.\" Use easy language for learners.",
                            ],
                            'examples' => [
                                'type' => 'array',
                                'description' => "An array of exactly 3 short usage fragments in {$targetLanguage} (2-4 words each). ALL 3 must fit the term's meaning in the supplied context/domain ONLY — never a different sense of the word (e.g. for \"tree\" in a graph-theory context: \"binary tree\", \"spanning tree\", \"root of the tree\" — NOT \"climb a tree\"). NOT full sentences (no subject like \"I/she\", no final period) and NOT bracketed. You MUST always return 3. Each shows a DIFFERENT common way the term is used within that meaning: a different inflected form, a preposition it typically pairs with, or a frequent collocation/set phrase. Never use square brackets.",
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                            'translation' => [
                                'type' => 'string',
                                'description' => "The term translated into {$nativeLanguage}, matching its meaning in the context; at most two common variants separated by a semicolon.",
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
                        'required' => ['phrase', 'sentence', 'examples', 'translation', 'definition', 'theme'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);

        return $response->json('choices.0.message.content');
        // return $response;
    }

    /**
     * Build a monolingual flashcard entirely in the learner's own native language.
     *
     * Used when the save destination is the user's native language: the learner is
     * building vocabulary in their own language, so every field is written in that
     * language and there is no translation. Handles both the plain and the
     * context-supplied cases via the optional $context argument.
     */
    public static function getContentForCardNative(string $phrase, string $themes, string $nativeLanguage, ?string $context = null)
    {
        logger('Obtaining native-language data for '.$phrase);

        $systemContent = "You are a vocabulary tutor helping a native speaker of {$nativeLanguage} build their vocabulary by learning words and phrases in context. Write every field in {$nativeLanguage}. Keep the term itself as the word the learner submitted — only fix spelling and reduce it to its base/dictionary form; do not expand a single word into a phrase. Describe that exact term in every field.";
        if (! is_null($context)) {
            $systemContent .= ' The term was seen in a specific context; capture the meaning it has there but in a general, dictionary-style form.';
        }
        $systemContent .= " You MUST always fill the 'examples' array with exactly 3 short usage phrases that each show a different common way the term is used — never leave it empty. Each example is a 2-4 word fragment with NO subject pronoun and NO final period (e.g. \"run a business\", \"run out of time\" — NOT \"I run a business.\"). Follow each field's rules exactly.";

        $userContent = "Original term: \"{$phrase}\" (fix the spelling if it is wrong). Write everything in {$nativeLanguage}.";
        if (! is_null($context)) {
            $userContent .= " Used in this context: \"{$context}\".";
        }

        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemContent,
                ],
                [
                    'role' => 'user',
                    'content' => $userContent,
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'get_information_for_card_native',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'phrase' => [
                                'type' => 'string',
                                'description' => 'The term this card teaches, taken from the Original term. Fix any spelling mistakes and reduce it to its base/dictionary form (e.g. broken => break, running => run, mice => mouse). If the Original term is already a multi-word phrase, keep it as the learner wrote it (spelling/base-form fixes only). Do NOT expand a single word into a collocation — a one-word term stays one word. Every other field must describe THIS term.',
                            ],
                            'sentence' => [
                                'type' => 'string',
                                'description' => "One short, natural {$nativeLanguage} sentence whose context makes the term's meaning clear. Wrap the term — in whatever form it appears in the sentence — in square brackets exactly once, e.g. \"She [broke] her promise.\" Keep it clear and easy to read.",
                            ],
                            'examples' => [
                                'type' => 'array',
                                'description' => "An array of exactly 3 short usage fragments in {$nativeLanguage} (2-4 words each) — NOT full sentences (no subject, no final period) and NOT bracketed. You MUST always return 3. Each shows a DIFFERENT common way the term is used: (1) a different inflected form, (2) a preposition the term typically pairs with, (3) a frequent collocation or set phrase. Give just the phrase fragment (like a verb+object or preposition+noun), never a whole sentence. Never use square brackets.",
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                            'definition' => [
                                'type' => 'string',
                                'description' => "A concise dictionary-style definition of the term in {$nativeLanguage}. Do not use the term itself in the definition.",
                            ],
                            'theme' => [
                                'type' => 'string',
                                'description' => "Pick the single best-fitting category from this list: \"{$themes}\" (copy it exactly). If none fit, create a short new category.",
                            ],
                        ],
                        'required' => ['phrase', 'sentence', 'examples', 'definition', 'theme'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);

        return $response->json('choices.0.message.content');
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
