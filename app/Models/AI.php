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

    /**
     * Open a roleplay practice chat. The model invents a believable everyday scene
     * in the target language (at the learner's CEFR level) and asks an opening
     * question that nudges the learner toward one of the target words — WITHOUT ever
     * writing any target word itself. Returns the opening line, or null on failure.
     *
     * @param  array<int, array{id:int, term:string}>  $targetWords
     */
    public static function startConversation(array $targetWords, string $targetLanguage, ?string $level = null, ?string $cacheKey = null): ?string
    {
        $terms = collect($targetWords)->pluck('term')->implode(', ');

        $payload = [
            'model' => self::MODEL,
            'reasoning_effort' => 'low',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a kind conversation partner / teacher in a language-practice. Ask user about a situation that could naturally lead the learner to use these target words: {$terms}. Keep is short and simple. Write ONLY in {$targetLanguage}. Start the chat with only one short message (1-2 short senteces max) and end with a question. Absolute rule: NEVER write, translate, hint at, or spell any of the target words yourself — your job is to make the LEARNER say them.".self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => 'Begin the chat with opening message.',
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'conversation_opening',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'reply' => [
                                'type' => 'string',
                                'description' => "Your opening message in {$targetLanguage}. Must never contain any target word.",
                            ],
                        ],
                        'required' => ['reply'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        if ($cacheKey) {
            $payload['prompt_cache_key'] = $cacheKey;
        }

        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', $payload);

        if ($response->json('choices.0.message.refusal') != null) {
            Log::error('AI refused to start conversation: '.$response->json('choices.0.message.refusal'));

            return null;
        }

        if (! $response->successful()) {
            Log::error('AI conversation start failed: '.$response->status().' - '.$response->body());

            return null;
        }

        $data = json_decode($response->json('choices.0.message.content'), true);

        return $data['reply'] ?? null;
    }

    /**
     * Produce the next in-character chat turn AND detect which still-unused target
     * words the learner used in their latest message (accepting misspellings and any
     * inflected form). Returns ['reply' => string, 'used_word_ids' => int[]] or null.
     *
     * The message array is built for prompt caching: an invariant system prefix
     * (role/rules/avoid-list/level — identical for every turn of a chat) sits first so
     * the growing transcript below it is served from cache, while the only per-turn
     * changing piece (the shrinking remaining-words list) is a small trailing message
     * after the prefix. $cacheKey (a per-chat id) is passed as prompt_cache_key to keep
     * a chat's turns routed to the same cache.
     *
     * @param  array<int, array{role:string, content:string}>  $messages  running transcript
     * @param  array<int, array{id:int, term:string}>  $remainingWords  words still to elicit
     * @param  array<int, array{id:int, term:string}>  $allWords  every target word (never say these)
     */
    public static function conversationReply(array $messages, array $remainingWords, array $allWords, string $targetLanguage, ?string $level = null, ?string $cacheKey = null): ?array
    {
        $remainingList = collect($remainingWords)->map(fn ($w) => "{$w['id']}: {$w['term']}")->implode('; ');
        $avoidList = collect($allWords)->pluck('term')->implode(', ');

        // Invariant prefix (same bytes every turn → cacheable). It references the
        // remaining-words list without embedding it; the actual list is appended below.
        $system = [
            'role' => 'system',
            'content' => "You are a kind conversation partner / teacher that asks a user conversational questions about words he tries to learn, write ONLY in {$targetLanguage}. Rules:\n"
                ."1. NEVER write, translate, spell, or clearly hint at any of these words yourself: {$avoidList}. Your whole goal is to make the LEARNER say them.\n"
                ."3. A follow-up message lists the words still left to elicit (id: term). Talk about a situation when he could use it. Make is concise and simple, also keep the topic positive. If the learner seems stuck on one, you can help them slightly or change the topic to draw out a DIFFERENT remaining word.\n"
                ."4. Keep replies short (1-3 short, message-like sentences) and natural.\n"
                .'Also report which of the STILL-REMAINING words the learner actually used in their most recent message — count a word as used even if misspelled or in a different inflected form.'.self::levelInstruction($level),
        ];

        // Per-turn dynamic tail: kept after the cacheable prefix + transcript so it
        // doesn't invalidate the cache. Recency also keeps it salient to the model.
        $remainingNote = [
            'role' => 'system',
            'content' => "Words still left to elicit (id: term) — {$remainingList}.",
        ];

        $payload = [
            'model' => self::MODEL,
            'reasoning_effort' => 'low',
            'messages' => array_merge([$system], $messages, [$remainingNote]),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'conversation_turn',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'reply' => [
                                'type' => 'string',
                                'description' => "Your next message in {$targetLanguage}. Must never contain any target word.",
                            ],
                            'used_word_ids' => [
                                'type' => 'array',
                                'description' => 'The ids of the still-remaining target words the learner used in their MOST RECENT message (any inflected form or misspelling counts). Empty array if none.',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                        'required' => ['reply', 'used_word_ids'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        if ($cacheKey) {
            $payload['prompt_cache_key'] = $cacheKey;
        }

        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', $payload);

        if ($response->json('choices.0.message.refusal') != null) {
            Log::error('AI refused conversation turn: '.$response->json('choices.0.message.refusal'));

            return null;
        }

        if (! $response->successful()) {
            Log::error('AI conversation turn failed: '.$response->status().' - '.$response->body());

            return null;
        }

        return json_decode($response->json('choices.0.message.content'), true);
    }

    /**
     * After the chat ends, produce a short bullet-point recap: a per-word note, what
     * the learner did well, and gentle corrections (wrong form → correct form + short
     * why). Terms stay in the target language; explanations are in the native language
     * so the learner understands. Returns the decoded array or null.
     *
     * @param  array<int, array{role:string, content:string}>  $messages  full transcript
     * @param  array<int, array{id:int, term:string}>  $targetWords  every word in the set
     * @param  array<int, int>  $usedIds  ids the server marked used
     */
    public static function conversationRecap(array $messages, array $targetWords, array $usedIds, string $targetLanguage, string $nativeLanguage, ?string $level = null): ?array
    {
        $wordList = collect($targetWords)
            ->map(fn ($w) => "{$w['id']}: {$w['term']} (".(in_array($w['id'], $usedIds) ? 'used' : 'not used').')')
            ->implode('; ');

        $transcript = collect($messages)
            ->map(fn ($m) => ($m['role'] === 'assistant' ? 'Partner' : 'Learner').': '.$m['content'])
            ->implode("\n");

        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a supportive language tutor reviewing a {$targetLanguage} practice conversation for a learner whose native language is {$nativeLanguage}. Be concise and encouraging. Write EVERY note, correction and praise as a SHORT BULLET FRAGMENT — never a full sentence. Keep target-language words in {$targetLanguage}; write all explanations and every 'why' in {$nativeLanguage}. Only correct real mistakes the learner actually made; never invent problems.".self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => "Target words — 'used' is authoritative (id: term (used/not used)): {$wordList}.\n\nConversation transcript:\n{$transcript}\n\nFor each target word give a short note: if used, brief praise or the correctly spelled base form; if not used, the correct word form the learner should learn. Then list what the learner did well, and concrete corrections for any grammar or word-form errors they made.",
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'conversation_recap',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'words' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'id' => ['type' => 'integer', 'description' => 'The target word id.'],
                                        'got' => ['type' => 'boolean', 'description' => 'Whether the learner used this word (mirror the authoritative used/not-used flag given).'],
                                        'note' => ['type' => 'string', 'description' => "Short fragment: praise or the correct {$targetLanguage} form; explanation in {$nativeLanguage}."],
                                    ],
                                    'required' => ['id', 'got', 'note'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'did_well' => [
                                'type' => 'array',
                                'description' => "Short bullet fragments in {$nativeLanguage} — genuine strengths in the learner's messages.",
                                'items' => ['type' => 'string'],
                            ],
                            'corrections' => [
                                'type' => 'array',
                                'description' => "Short bullet fragments: the learner's wrong form -> correct form - short why (in {$nativeLanguage}). Empty array if there were no real mistakes.",
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['words', 'did_well', 'corrections'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);

        if ($response->json('choices.0.message.refusal') != null) {
            Log::error('AI refused conversation recap: '.$response->json('choices.0.message.refusal'));

            return null;
        }

        if (! $response->successful()) {
            Log::error('AI conversation recap failed: '.$response->status().' - '.$response->body());

            return null;
        }

        return json_decode($response->json('choices.0.message.content'), true);
    }

    /**
     * Open a free "Conversation Challenge" practice chat — an everyday roleplay scene
     * in the target language at the learner's CEFR level. Unlike startConversation there
     * are NO target words to elicit; the learner just converses in full sentences and is
     * corrected as they go.
     *
     * Returns ['scene' => string, 'reply' => string] — 'scene' is a short label shown
     * ABOVE the chat, 'reply' is the pure in-character opening line (no scene-setting
     * narration). Null on failure.
     *
     * @return array{scene:string, reply:string}|null
     */
    public static function startChallenge(string $targetLanguage, ?string $level = null, ?string $scene = null, ?string $cacheKey = null): ?array
    {
        $sceneNote = $scene
            ? " The learner asked for this scene/topic — build the situation around it: \"{$scene}\"."
            : ' Pick ONE natural everyday situation (ordering food, meeting a friend, travelling, etc.).';

        $payload = [
            'model' => self::MODEL,
            'reasoning_effort' => 'low',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a kind conversation partner in a {$targetLanguage} roleplay chat.{$sceneNote} Write ONLY in {$targetLanguage}. Return two separate things: 'scene' — a short description of the situation; and 'reply' — your opening line, which must contain ONLY the actual in-character roleplay message (1-2 short sentences ending with a question).".self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => 'Begin the chat.',
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'challenge_opening',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'scene' => [
                                'type' => 'string',
                                'description' => "A short description of the situation, onscene-setting or narration. In {$targetLanguage}.",
                            ],
                            'reply' => [
                                'type' => 'string',
                                'description' => "Your opening in-character message in {$targetLanguage}.",
                            ],
                        ],
                        'required' => ['scene', 'reply'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        if ($cacheKey) {
            $payload['prompt_cache_key'] = $cacheKey;
        }

        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', $payload);

        if ($response->json('choices.0.message.refusal') != null) {
            Log::error('AI refused to start challenge: '.$response->json('choices.0.message.refusal'));

            return null;
        }

        if (! $response->successful()) {
            Log::error('AI challenge start failed: '.$response->status().' - '.$response->body());

            return null;
        }

        $data = json_decode($response->json('choices.0.message.content'), true);

        return is_array($data) && isset($data['reply']) ? $data : null;
    }

    /**
     * Produce the next in-character chat turn AND a live correction of the learner's most
     * recent message, in one call to save tokens. The correction is a short feedback note
     * (in the native language) naming the mistake and the correct form — it does NOT echo
     * the learner's whole sentence back.
     *
     * Optional $scene (topic to talk about) and $feedbackFocus (grammar/vocabulary the
     * learner wants to drill) steer the chat and the corrections. Both are constant for a
     * chat, so they live in the invariant system prefix.
     *
     * The message array is built for prompt caching: an invariant system prefix
     * (role/rules/level/scene/focus — identical for every turn) sits first so the growing
     * transcript below it is served from cache; $cacheKey (a per-chat id) routes a chat's
     * turns to the same cache.
     *
     * @param  array<int, array{role:string, content:string}>  $messages  running transcript (last entry = learner's latest message)
     * @return array{reply:string, correction:array{has_error:bool, is_typo:bool, feedback:string}}|null
     */
    public static function challengeReply(array $messages, string $targetLanguage, string $nativeLanguage, ?string $level = null, ?string $scene = null, ?string $feedbackFocus = null, ?string $cacheKey = null): ?array
    {
        $sceneNote = $scene ? " Keep the conversation on this scene/topic: \"{$scene}\"." : '';
        $focusNote = $feedbackFocus
            ? " The learner especially wants to practise this: \"{$feedbackFocus}\". Steer the chat to create natural chances to use it and use it also yourself. Give it extra attention in your corrections."
            : '';

        $system = [
            'role' => 'system',
            'content' => "You are a kind conversation partner in a {$targetLanguage} roleplay chat with a learner whose native language is {$nativeLanguage}.{$sceneNote}{$focusNote} Each turn you do two things:\n"
                ."1. Reply in character, ONLY in {$targetLanguage}, short (1-3 short, message-like sentences) and natural, and keep the conversation going (usually end with a question).\n"
                ."2. Check the learner's MOST RECENT message. If it has any grammar, word-order, preposition, word-choice, or spelling mistake, set has_error=true and write a short feedback fragment in {$nativeLanguage} that names the mistake and the correct form (do NOT repeat their whole sentence back — just the fix). Also set is_typo=true when the ONLY problem is an obvious fast-typing slip or misspelling of a word the learner clearly knows (not a real gap in grammar, prepositions, or vocabulary); otherwise is_typo=false. If the message is fine, set has_error=false, is_typo=false, and leave 'feedback' empty. Never mix the correction into your in-character reply.".self::levelInstruction($level),
        ];

        $payload = [
            'model' => self::MODEL,
            'reasoning_effort' => 'low',
            'messages' => array_merge([$system], $messages),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'challenge_turn',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'reply' => [
                                'type' => 'string',
                                'description' => "Your next in-character message in {$targetLanguage}.",
                            ],
                            'correction' => [
                                'type' => 'object',
                                'properties' => [
                                    'has_error' => [
                                        'type' => 'boolean',
                                        'description' => 'True if the learner\'s most recent message had any grammar/word-order/preposition/word-choice/spelling mistake.',
                                    ],
                                    'is_typo' => [
                                        'type' => 'boolean',
                                        'description' => 'True when the only problem is an obvious fast-typing slip/misspelling, not a real grammar, preposition, or vocabulary gap. False otherwise (and when has_error is false).',
                                    ],
                                    'feedback' => [
                                        'type' => 'string',
                                        'description' => "A short fragment in {$nativeLanguage} naming the mistake and the correct form (not the learner's full sentence). Empty string when has_error is false.",
                                    ],
                                ],
                                'required' => ['has_error', 'is_typo', 'feedback'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'required' => ['reply', 'correction'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        if ($cacheKey) {
            $payload['prompt_cache_key'] = $cacheKey;
        }

        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', $payload);

        if ($response->json('choices.0.message.refusal') != null) {
            Log::error('AI refused challenge turn: '.$response->json('choices.0.message.refusal'));

            return null;
        }

        if (! $response->successful()) {
            Log::error('AI challenge turn failed: '.$response->status().' - '.$response->body());

            return null;
        }

        return json_decode($response->json('choices.0.message.content'), true);
    }

    /**
     * After a Conversation Challenge ends, turn the mistakes the learner made during the
     * chat into a short list of recommendations on what to improve or learn next, focused
     * on what they struggled with MOST. Deliberately contains NO examples (those were the
     * per-message corrections shown live). Recommendations are written in the native
     * language. Returns the decoded array or null.
     *
     * @param  array<int, string>  $struggles  the feedback notes collected during the chat
     */
    public static function challengeRecap(array $struggles, string $targetLanguage, string $nativeLanguage, ?string $level = null, ?string $feedbackFocus = null): ?array
    {
        $struggleList = empty($struggles)
            ? 'The learner made no notable mistakes.'
            : collect($struggles)->map(fn ($s, $i) => ($i + 1).'. '.$s)->implode("\n");

        $focusNote = $feedbackFocus
            ? " The learner specifically wanted to practise \"{$feedbackFocus}\", so weight your recommendations toward that where relevant."
            : '';

        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a supportive language tutor. From a list of mistakes a learner made during a {$targetLanguage} practice conversation, produce a short list of recommendations on what to improve or study next. Focus ONLY on genuine gaps that would cause problems in a real conversation — specific grammar structures, prepositions, or vocabulary the learner clearly lacks. IGNORE typos and fast-typing slips entirely; do not mention spelling. Name concrete topics (e.g. \"past tense of irregular verbs\", \"prepositions of time\"), and group recurring issues into one recommendation.{$focusNote} Write every recommendation in {$nativeLanguage} as a short, actionable bullet fragment — never a full sentence. Do NOT include example phrases or sentences; only name the skill/topic to work on. Return up to 4 recommendations (fewer if there is little to fix). If there were no real gaps, return a single encouraging recommendation to keep practising.".self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => "Mistakes the learner made:\n{$struggleList}",
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'challenge_recap',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'recommendations' => [
                                'type' => 'array',
                                'description' => "Short bullet fragments in {$nativeLanguage}.",
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['recommendations'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);

        if ($response->json('choices.0.message.refusal') != null) {
            Log::error('AI refused challenge recap: '.$response->json('choices.0.message.refusal'));

            return null;
        }

        if (! $response->successful()) {
            Log::error('AI challenge recap failed: '.$response->status().' - '.$response->body());

            return null;
        }

        return json_decode($response->json('choices.0.message.content'), true);
    }

    /**
     * Build the system instructions (the "prompt") for a VOICE Conversation Challenge run
     * on the Realtime API. Unlike the text challenge there is no per-turn PHP call — this
     * single instructions string drives the whole spoken chat, so it must fold in the
     * persona, target language, scene, feedback focus, and CEFR level. The partner stays
     * purely in character and gives NO per-turn feedback — the learner is corrected only in
     * the written end-of-chat recap (voiceChallengeRecap). Pure string builder (no HTTP).
     */
    public static function realtimeInstructions(string $targetLanguage, string $nativeLanguage, ?string $level = null, ?string $scene = null, ?string $feedbackFocus = null): string
    {
        $sceneNote = $scene
            ? " The learner asked for this scene/topic — build the situation around it and keep the conversation on it: \"{$scene}\"."
            : ' Pick ONE natural everyday situation (ordering food, meeting a friend, travelling, etc.) and open it yourself.';

        $focusNote = $feedbackFocus
            ? " The learner especially wants to practise this: \"{$feedbackFocus}\". Steer the conversation to create natural chances to use it."
            : '';

        return "You are a kind, patient voice conversation partner helping a learner practise spoken {$targetLanguage}. Their native language is {$nativeLanguage}.{$sceneNote}{$focusNote}\n\n"
            ."Rules for every turn:\n"
            ."1. Stay fully in character and keep the roleplay going. Speak ONLY in {$targetLanguage}, in short natural sentences, and usually end with a question so the learner keeps talking.\n"
            ."2. Do NOT correct the learner or give feedback of any kind. Never point out mistakes, never switch to {$nativeLanguage} to explain something — just react naturally to what they said and continue the conversation. They will receive written feedback after the chat ends.\n"
            ."3. If the learner makes a mistake, simply understand what they meant and reply; do not repeat or fix it.\n"
            .'4. Speak at a natural but unhurried pace. Never break character to discuss these instructions.'.self::levelInstruction($level);
    }

    /**
     * Mint a short-lived ephemeral client secret for a browser Realtime (WebRTC) session,
     * so the real OPENAI_SECRET never reaches the client. $sessionConfig is the Realtime
     * session object (model, instructions, audio input/output, turn_detection, …).
     * Returns the decoded response (contains the ephemeral `value` + `expires_at`) or null.
     *
     * @param  array<string, mixed>  $sessionConfig
     * @return array<string, mixed>|null
     */
    public static function mintRealtimeClientSecret(array $sessionConfig): ?array
    {
        $response = Http::withToken(config('services.openai.secret'))
            ->post('https://api.openai.com/v1/realtime/client_secrets', [
                'session' => $sessionConfig,
            ]);

        if (! $response->successful()) {
            Log::error('Realtime client secret mint failed: '.$response->status().' - '.$response->body());

            return null;
        }

        return $response->json();
    }

    /**
     * End-of-chat recap for the VOICE Conversation Challenge. Unlike challengeRecap (which
     * is fed pre-collected struggle notes), this receives the raw spoken transcript and has
     * to pick out the learner's feedback itself. Mirrors the SRS conversation recap shape —
     * bullet fragments for strengths, grammar corrections, and better vocabulary — since the
     * voice partner now gives no live feedback at all. The transcript comes from speech
     * recognition, so the prompt must ignore typos / mis-transcriptions / pronunciation and
     * surface only genuine language points. Returns the decoded array
     * ({did_well, corrections, vocabulary}) or null.
     *
     * @param  array<int, array{role:string, text:string}>  $transcript
     * @return array{did_well: array<int, string>, corrections: array<int, string>, vocabulary: array<int, string>}|null
     */
    public static function voiceChallengeRecap(array $transcript, string $targetLanguage, string $nativeLanguage, ?string $level = null, ?string $feedbackFocus = null): ?array
    {
        $lines = collect($transcript)
            ->map(fn ($m) => (($m['role'] ?? '') === 'user' ? 'Learner' : 'Partner').': '.($m['text'] ?? ''))
            ->filter(fn ($l) => trim($l) !== 'Learner:' && trim($l) !== 'Partner:')
            ->implode("\n");

        if (trim($lines) === '') {
            $lines = 'The learner did not say anything.';
        }

        $focusNote = $feedbackFocus
            ? " The learner specifically wanted to practise \"{$feedbackFocus}\", so give that extra attention where relevant."
            : '';

        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a supportive language tutor reviewing the transcript of a spoken {$targetLanguage} practice conversation for a learner whose native language is {$nativeLanguage}. Look ONLY at the learner's lines. Be concise and encouraging. This is a SPEECH-RECOGNITION transcript, so do NOT correct the way it is written — IGNORE typos, mis-transcriptions, capitalisation, punctuation, and pronunciation entirely; never mention spelling. Point out ONLY clearly wrong grammar the learner actually used, and suggest better or more natural vocabulary/phrasing they could have used. Never invent problems. Write EVERY note as a SHORT BULLET FRAGMENT — never a full sentence. Keep {$targetLanguage} words in {$targetLanguage}; write every explanation and 'why' in {$nativeLanguage}.{$focusNote}".self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => "Conversation transcript:\n{$lines}\n\nGive: what the learner did well, corrections for any clearly wrong grammar (wrong form -> correct form - short why), and suggestions for better vocabulary or more natural phrasing they could use. Leave a list empty if there is nothing genuine to add.",
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'voice_challenge_recap',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'did_well' => [
                                'type' => 'array',
                                'description' => "Short bullet fragments in {$nativeLanguage} — genuine strengths in the learner's speech. Empty array if nothing stands out.",
                                'items' => ['type' => 'string'],
                            ],
                            'corrections' => [
                                'type' => 'array',
                                'description' => "Short bullet fragments: the learner's wrong grammar -> correct form - short why (in {$nativeLanguage}). Empty array if there was no clearly wrong grammar.",
                                'items' => ['type' => 'string'],
                            ],
                            'vocabulary' => [
                                'type' => 'array',
                                'description' => "Short bullet fragments suggesting better or more natural {$targetLanguage} vocabulary/phrasing the learner could use, explained in {$nativeLanguage}. Empty array if nothing to add.",
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['did_well', 'corrections', 'vocabulary'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);

        if ($response->json('choices.0.message.refusal') != null) {
            Log::error('AI refused voice challenge recap: '.$response->json('choices.0.message.refusal'));

            return null;
        }

        if (! $response->successful()) {
            Log::error('AI voice challenge recap failed: '.$response->status().' - '.$response->body());

            return null;
        }

        return json_decode($response->json('choices.0.message.content'), true);
    }
}
