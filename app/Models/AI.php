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
     * Chat model used for every text generation in the app.
     * GPT-5.6 luna: follows per-field instructions far more reliably than the
     * previous nano model, which is what the flashcard schemas depend on.
     */
    private const MODEL = 'gpt-5.6-luna';

    /**
     * Default reasoning effort for the chat model. "medium" buys enough
     * reasoning for the flashcard generators to follow their per-field rules.
     * Latency-sensitive calls (the conversation turns) pass "low" explicitly.
     */
    private const REASONING_EFFORT = 'medium';

    /**
     * Build an instruction fragment that constrains the language the model produces to
     * the learner's CEFR proficiency level. Returns an empty string when no (or an
     * unknown) level is given, so prompts stay unchanged.
     *
     * The last sentence is load-bearing: read as a length rule, a beginner level made
     * the model answer with two- or three-word stubs. The level caps DIFFICULTY only.
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

        return " The learner is CEFR level {$level}: {$description} Every word and structure around the term must stay at this level; the term itself may be harder. This caps difficulty, not length — never write less than a field asks for.";
    }

    /**
     * CEFR levels that get the definition in the learner's native language: below B2 a
     * target-language definition is usually harder than the term it explains.
     */
    private const NATIVE_DEFINITION_LEVELS = ['A1', 'A2', 'B1'];

    /**
     * Which language the `definition` field is written in. Resolved here in PHP and
     * interpolated into the schema, so the model is only ever told one language to
     * write in — it never decides this itself.
     */
    private static function definitionLanguage(?string $level, string $targetLanguage, string $nativeLanguage): string
    {
        return in_array($level, self::NATIVE_DEFINITION_LEVELS, true)
            ? $nativeLanguage
            : $targetLanguage;
    }

    /**
     * Keeps `translation` and `definition` apart. Only needed when both land in the
     * native language (A1-B1): without a language cue to tell the two fields apart the
     * model swapped them, returning "hezký; krásný" as the definition of "schön".
     */
    private static function fieldContrastRule(string $definitionLanguage, string $nativeLanguage): string
    {
        return $definitionLanguage === $nativeLanguage
            ? " Translation and definition are both in {$nativeLanguage} but are NOT the same: the translation is the equivalent term, the definition explains what it means."
            : '';
    }

    /**
     * System-prompt fragment telling the model to classify the term before writing
     * anything else. Shared by all three card generators so the definition of the two
     * types stays in exactly one place.
     */
    private static function typeRules(): string
    {
        // The examples rule is repeated here on purpose: with it only in the property
        // description, the model returned an empty array for lexical terms.
        return ' Decide the term_type first — every other field depends on it. The distinction is not word count: a naming unit whose meaning you can define is "lexical", a ready-made utterance performing a communicative function is an "expression". A lexical term always gets exactly 3 examples, each a 2-4 word fragment containing the term itself in a different grammatical form, never a sentence and never bracketed; an expression gets none. Put in each field only what that field asks for.';
    }

    /**
     * The shared `term_type` schema property. Placed FIRST in every card schema on
     * purpose: strict structured outputs emit keys in schema order, so the model
     * commits to the type before generating the fields that depend on it.
     */
    private static function termTypeProperty(): array
    {
        return [
            'type' => 'string',
            'enum' => Card::TERM_TYPES,
            'description' => 'What kind of term this card teaches. Choose "lexical" if it is a naming unit — you can complete "X means ...". Single words ("cabinet", "reluctant"), collocations and compounds ("traffic jam", "make a decision") and idioms ("under the weather", "kick the bucket") are ALL lexical, because each names a thing, action, quality or concept. Choose "expression" if it is a ready-made utterance or utterance frame performing a communicative function — you complete "you say X when you want to ...": refusing, requesting, hedging, warning, agreeing, greeting ("I\'d rather not", "can you hand me the [something]", "you better be ready", "as far as I\'m concerned"). A subject pronoun with a finite verb, a clause performing a speech act, or a variable slot all point to "expression"; a whole sentence submitted by the learner is an "expression" and gets reduced to its reusable frame.',
        ];
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
        $definitionLanguage = self::definitionLanguage($level, $targetLanguage, $nativeLanguage);
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a vocabulary tutor turning a learner's Term into one flashcard. Never swap it for a different term: fix its spelling, put it into its canonical form, and describe that exact term in every field.".self::typeRules().self::fieldContrastRule($definitionLanguage, $nativeLanguage).self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => "Original term: \"{$phrase}\" (fix the spelling if it is wrong). Target language: \"{$targetLanguage}\". Native language: \"{$nativeLanguage}\". Each field says which of the two it must be written in.",
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
                            'term_type' => self::termTypeProperty(),
                            'phrase' => [
                                'type' => 'string',
                                'description' => 'The term this card teaches, taken from the Original term; always fix spelling mistakes. If term_type is "lexical": reduce it to its base/dictionary form (e.g. broken => break, running => run, mice => mouse), correctly spelled; If term_type is "expression": give the canonical, reusable form — KEEP the subject pronoun and any contraction, drop the situation-specific tail, and write each variable part as a dictionary-style placeholder in square brackets, "[something]" / "[someone]" / "[somewhere]" / "[do something]" (e.g. "can you hand me the salt" => "can you hand me the [something]", "I would like to go to the cinema tomorrow" => "I would like to [do something]"). Never mark a slot with dots or an ellipsis, and if the expression has no variable part use no square brackets at all — never bracket an ordinary word. Every other field must describe THIS term.',
                            ],
                            'sentence' => [
                                'type' => 'string',
                                'description' => "Exactly ONE natural {$targetLanguage} sentence containing the term inside square brackets exactly once; never put the surrounding punctuation inside the brackets. It must be RICH and ILLUSTRATIVE: at least 6 words besides the term, naming a concrete situation, actor or result, so a learner who does NOT know the term could work out its meaning from the surrounding words alone. A bare frame that gives no clue (\"It is [nice].\", \"He is a [coward].\") is invalid. If term_type is \"lexical\": bracket the term itself in whatever form it takes there — e.g. \"She [broke] her promise to call me the moment she landed.\" If term_type is \"expression\": write what someone actually SAYS — every \"[something]\"/\"[someone]\" slot filled in with real words, the WHOLE expression bracketed once, plus a clause before or after that shows the situation. Never reword the expression and never nest it inside another question. — e.g. \"[Can you hand me the salt], I can't reach it from this side of the table.\"",
                            ],
                            'examples' => [
                                'type' => 'array',
                                'description' => "If term_type is \"lexical\": you MUST return exactly 3 entries in {$targetLanguage}, never bracketed — 2-4 word usage fragments with NO subject pronoun and NO final period. EVERY fragment must contain the term itself; one built from a synonym, an antonym or a merely related word is invalid (for \"coward\": \"a complete coward\", NOT \"a brave soldier\"). The phrase field already gives the base/dictionary form, so these must show the term IN USE: give it a DIFFERENT grammatical form in each of the three whenever the term has more than one — past, participle, 3rd person, plural, comparative, case — e.g. for \"break\": \"broke a window\", \"breaking the rules\", \"a broken promise\". Inflections come first; when the term has too few to fill all three, USE a close word-family form for the rest — \"reluctant\" => \"reluctant to change\", \"reluctantly accepted it\", \"her sudden reluctance\" — but never a loosely related word. Repeating the base form twice is a last resort, only for a term with no other form at all. Never start a fragment with a subject pronoun. Vary the kind of use as well: a preposition the term pairs with, a frequent collocation, a set phrase. Returning fewer than 3 for a lexical term is invalid. If term_type is \"expression\": return an empty array — an expression is illustrated by its sentence alone.",
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                            'translation' => [
                                'type' => 'string',
                                'description' => "The term ITSELF in {$nativeLanguage}, same part of speech; never a sentence and never an explanation. If term_type is \"lexical\": the equivalent word, at most 2 variants separated by \"; \" (\"schön\" => \"hezký; krásný\"). If term_type is \"expression\": EXACTLY ONE functional equivalent — what a native speaker really says there, never literal, never a second variant. Translate the FRAME: a placeholder returns as a {$nativeLanguage} slot (\"[něco]\"), never the English \"[something]\".",
                            ],
                            'definition' => [
                                'type' => 'string',
                                'description' => "EXPLAINS the meaning in {$definitionLanguage} — never a translation, an equivalent word or a list of synonyms, and it never contains the term itself. If term_type is \"lexical\": a dictionary-style definition (\"schön\" => \"co vypadá příjemně a líbí se\", NOT \"hezký\"). If term_type is \"expression\": a short usage note saying what the speaker is doing and when you say it.",
                            ],
                            'theme' => [
                                'type' => 'string',
                                'description' => "Pick the single best-fitting category from this list: \"{$themes}\" (copy it exactly). If none fit, create a short new category.",
                            ],
                        ],
                        'required' => ['term_type', 'phrase', 'sentence', 'examples', 'translation', 'definition', 'theme'],
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
        $definitionLanguage = self::definitionLanguage($level, $targetLanguage, $nativeLanguage);
        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [

            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a vocabulary tutor turning a learner's Term (seen in a specific context) into one flashcard. Never swap it for a different term: fix its spelling and put it into its canonical form. The context fixes WHICH sense or situation this card is about, so every field — including all examples — must reflect ONLY that sense and never another meaning of the term; it also shows how the term is really used, which helps you judge the term_type.".self::typeRules().self::fieldContrastRule($definitionLanguage, $nativeLanguage).self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => "Original term: \"{$phrase}\" (fix the spelling if it is wrong), used in this context: \"{$context}\". Target language: \"{$targetLanguage}\". Native language: \"{$nativeLanguage}\". Each field says which of the two it must be written in.",
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
                            'term_type' => self::termTypeProperty(),
                            'phrase' => [
                                'type' => 'string',
                                'description' => 'The term this card teaches, taken from the Original term; always fix spelling mistakes. Keep the meaning it has in the supplied context, but in a general, reusable form that is not tied to the specific subject of that context. If term_type is "lexical": reduce it to its base/dictionary form (e.g. broken => break, running => run, mice => mouse), correctly spelled; If term_type is "expression": give the canonical, reusable form — KEEP the subject pronoun and any contraction, drop the situation-specific tail, and write each variable part as a dictionary-style placeholder in square brackets, "[something]" / "[someone]" / "[somewhere]" / "[do something]" (e.g. "can you hand me the salt" => "can you hand me the [something]", "I would like to go to the cinema tomorrow" => "I would like to [do something]"). Never mark a slot with dots or an ellipsis, and if the expression has no variable part use no square brackets at all — never bracket an ordinary word. Every other field must describe THIS term.',
                            ],
                            'sentence' => [
                                'type' => 'string',
                                'description' => "Exactly ONE natural {$targetLanguage} sentence containing the term inside square brackets exactly once; never two sentences, and never put the surrounding punctuation inside the brackets. It must be RICH and ILLUSTRATIVE: at least 6 words besides the term, naming a concrete situation, actor or result, so a learner who does NOT know the term could work out its meaning from the surrounding words alone. A bare frame that gives no clue (\"It is [nice].\") is invalid. If term_type is \"lexical\": show the sense the term has in the supplied context, bracketing the term itself in whatever form it takes there — e.g. \"She [broke] her promise to call me the moment she landed.\" If term_type is \"expression\": write what someone actually SAYS in the situation the supplied context describes — every \"[something]\"/\"[someone]\" slot filled in with real words, the WHOLE expression bracketed once, plus a clause before or after that shows the situation. Never reword the expression and never nest it inside another question — e.g. \"[Can you hand me the salt], I can't reach it from this side of the table.\"",
                            ],
                            'examples' => [
                                'type' => 'array',
                                'description' => "If term_type is \"lexical\": you MUST return exactly 3 entries in {$targetLanguage}, never bracketed — 2-4 word usage fragments with NO subject pronoun and NO final period. EVERY fragment must contain the term itself; one built from a synonym, an antonym or a merely related word is invalid. The phrase field already gives the base/dictionary form, so these must show the term IN USE: give it a DIFFERENT grammatical form in each of the three whenever the term has more than one — past, participle, 3rd person, plural, comparative, case — e.g. for \"break\": \"broke a window\", \"breaking the rules\", \"a broken promise\". Inflections come first; when the term has too few to fill all three, USE a close word-family form for the rest — \"reluctant\" => \"reluctant to change\", \"reluctantly accepted it\", \"her sudden reluctance\" — but never a loosely related word. Repeating the base form twice is a last resort, only for a term with no other form at all. Never start a fragment with a subject pronoun. Vary the kind of use as well: a preposition it pairs with, a frequent collocation, a set phrase. ALL 3 must fit the term's meaning in the supplied context/domain ONLY — never a different sense (e.g. for \"tree\" in a graph-theory context: \"binary tree\", \"spanning trees\", \"root of the tree\" — NOT \"climb a tree\"). Returning fewer than 3 for a lexical term is invalid. If term_type is \"expression\": return an empty array — an expression is illustrated by its sentence alone.",
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                            'translation' => [
                                'type' => 'string',
                                'description' => "The term ITSELF in {$nativeLanguage} in the meaning it has in the supplied context, same part of speech; never a sentence and never an explanation. If term_type is \"lexical\": the equivalent word, at most 2 variants separated by \"; \" (\"schön\" => \"hezký; krásný\"). If term_type is \"expression\": EXACTLY ONE functional equivalent — what a native speaker really says there, never literal, never a second variant. Translate the FRAME: a placeholder returns as a {$nativeLanguage} slot (\"[něco]\"), never the English \"[something]\".",
                            ],
                            'definition' => [
                                'type' => 'string',
                                'description' => "EXPLAINS the meaning it has in the supplied context, in {$definitionLanguage} — never a translation, an equivalent word or a list of synonyms, and it never contains the term itself. If term_type is \"lexical\": a dictionary-style definition (\"schön\" => \"co vypadá příjemně a líbí se\", NOT \"hezký\"). If term_type is \"expression\": a short usage note saying what the speaker is doing and when you say it.",
                            ],
                            'theme' => [
                                'type' => 'string',
                                'description' => "Pick the single best-fitting category from this list: \"{$themes}\" (copy it exactly). If none fit, create a short new category.",
                            ],
                        ],
                        'required' => ['term_type', 'phrase', 'sentence', 'examples', 'translation', 'definition', 'theme'],
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

        $systemContent = "You are a vocabulary tutor helping a native speaker of {$nativeLanguage} build their vocabulary by learning words and phrases in context. Write every field in {$nativeLanguage}. Never swap the term for a different one: fix its spelling, put it into its canonical form, and describe that exact term in every field.";
        if (! is_null($context)) {
            $systemContent .= ' The term was seen in a specific context, which fixes WHICH sense of the term this card is about: capture that sense in a general, reusable form, and let every field — including all three examples — reflect ONLY that sense or domain. It also helps you judge the term_type.';
        }
        $systemContent .= self::typeRules();

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
                            'term_type' => self::termTypeProperty(),
                            'phrase' => [
                                'type' => 'string',
                                'description' => 'The term this card teaches, taken from the Original term; always fix spelling mistakes. If term_type is "lexical": reduce it to its base/dictionary form (e.g. broken => break, running => run, mice => mouse), correctly spelled, and never add square brackets or a placeholder of any kind; if the Original term is already a multi-word phrase keep it as the learner wrote it (spelling/base-form fixes only), and do NOT expand a single word into a collocation — a one-word term stays one word. If term_type is "expression": give the canonical, reusable form — KEEP the subject pronoun and any contraction, drop the situation-specific tail, and write each variable part as a dictionary-style placeholder in square brackets, "[something]" / "[someone]" / "[somewhere]" / "[do something]". Never mark a slot with dots or an ellipsis, and if the expression has no variable part use no square brackets at all — never bracket an ordinary word. Every other field must describe THIS term.',
                            ],
                            'sentence' => [
                                'type' => 'string',
                                'description' => "Exactly ONE natural {$nativeLanguage} sentence containing the term inside square brackets exactly once; never two sentences, and never put the surrounding punctuation inside the brackets. A sentence without the square brackets is invalid. It must be RICH and ILLUSTRATIVE: at least 6 words besides the term, naming a concrete situation, actor or result, so a reader who does NOT know the term could work out its meaning from the surrounding words alone. A bare frame that gives no clue (\"It is [nice].\") is invalid. If term_type is \"lexical\": bracket the term itself in whatever form it takes there — e.g. \"She [broke] her promise to call me the moment she landed.\" If term_type is \"expression\": write what someone actually SAYS — every \"[something]\"/\"[someone]\" slot filled in with real words, the WHOLE expression bracketed once, plus a clause before or after that shows the situation. Never reword the expression and never nest it inside another question — e.g. \"[Can you hand me the salt], I can't reach it from this side of the table.\"",
                            ],
                            'examples' => [
                                'type' => 'array',
                                'description' => "If term_type is \"lexical\": you MUST return exactly 3 entries in {$nativeLanguage}, never bracketed — 2-4 word usage fragments with NO subject and NO final period, just the fragment (verb+object or preposition+noun) and never a whole sentence. EVERY fragment must contain the term itself; one built from a synonym, an antonym or a merely related word is invalid. The phrase field already gives the base/dictionary form, so these must show the term IN USE: give it a DIFFERENT grammatical form in each of the three whenever the term has more than one — past, participle, person, plural, case, comparative. Inflections come first; when the term has too few to fill all three, USE a close word-family form for the rest — \"reluctant\" => \"reluctant to change\", \"reluctantly accepted it\", \"her sudden reluctance\" — but never a loosely related word. Repeating the base form twice is a last resort, only for a term with no other form at all. Never start a fragment with a subject pronoun. Vary the kind of use as well: a preposition the term pairs with, a frequent collocation, a set phrase. Returning fewer than 3 for a lexical term is invalid. If term_type is \"expression\": return an empty array — an expression is illustrated by its sentence alone.",
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                            'definition' => [
                                'type' => 'string',
                                'description' => "Written in {$nativeLanguage}. If term_type is \"lexical\": a concise dictionary-style definition of the term. If term_type is \"expression\": a short usage note saying what the speaker is doing and when you say it. Do not use the term itself.",
                            ],
                            'theme' => [
                                'type' => 'string',
                                'description' => "Pick the single best-fitting category from this list: \"{$themes}\" (copy it exactly). If none fit, create a short new category.",
                            ],
                        ],
                        'required' => ['term_type', 'phrase', 'sentence', 'examples', 'definition', 'theme'],
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
                    'content' => "You are a kind conversation partner in a {$targetLanguage} practice chat. Open with ONE short message (1-2 sentences) ending with a question, about an everyday situation that would naturally lead the learner to use these words: {$terms}. Write ONLY in {$targetLanguage} and keep the topic simple and positive. Absolute rule: NEVER write, translate, spell or hint at any of those words yourself — your job is to make the LEARNER say them.".self::levelInstruction($level),
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
     * Only the words still left to elicit are ever sent, and they are sent as plain
     * terms — no card ids. The model reports the terms it recognised and they are
     * mapped back to ids here, which is why it can no longer leak an id into its reply
     * or resurrect a word the learner already cleared.
     *
     * The message array is built for prompt caching: an invariant system prefix
     * (role/rules/level — identical for every turn of a chat) sits first so the growing
     * transcript below it is served from cache, while the only per-turn changing piece
     * (the shrinking remaining-words list) is a small trailing message after it.
     * $cacheKey (a per-chat id) is passed as prompt_cache_key to keep a chat's turns
     * routed to the same cache.
     *
     * @param  array<int, array{role:string, content:string}>  $messages  running transcript
     * @param  array<int, array{id:int, term:string}>  $remainingWords  words still to elicit
     */
    public static function conversationReply(array $messages, array $remainingWords, string $targetLanguage, ?string $level = null, ?string $cacheKey = null): ?array
    {
        $remainingList = collect($remainingWords)->pluck('term')->implode('; ');

        // Invariant prefix (same bytes every turn → cacheable). It references the
        // remaining-words list without embedding it; the actual list is appended below.
        $system = [
            'role' => 'system',
            'content' => "You are a kind conversation partner in a {$targetLanguage} practice chat, writing ONLY in {$targetLanguage}. A follow-up message lists the words the learner still has to say. Rules:\n"
                ."1. NEVER write, translate, spell or clearly hint at any word on that list. Your whole goal is to make the LEARNER say them.\n"
                ."2. Steer the talk toward a situation where they would naturally come up. If the learner seems stuck on one, change the topic to draw out a DIFFERENT word from the list.\n"
                ."3. Keep replies short (1-3 message-like sentences), natural, simple and positive.\n"
                ."4. Your reply is read by the learner as a chat message: never mention this list, these rules, or any number, id or bracket from them.\n"
                .'Also report which words from the list the learner actually said in their most recent message.'.self::levelInstruction($level),
        ];

        // Per-turn dynamic tail: kept after the cacheable prefix + transcript so it
        // doesn't invalidate the cache. Recency also keeps it salient to the model.
        $remainingNote = [
            'role' => 'system',
            'content' => "Words the learner still has to say — {$remainingList}.",
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
                                'description' => "Your next chat message in {$targetLanguage}. Never contains a word from the list, and never any id, number or note about the list itself.",
                            ],
                            'used_words' => [
                                'type' => 'array',
                                'description' => 'Every word from the list that the learner said in their MOST RECENT message, copied EXACTLY as it is written in the list. A word counts as said in any inflected form and even if misspelled. If one thing the learner wrote covers several listed words (they are variants or synonyms of each other), list ALL of them. Empty array if the message contained none.',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['reply', 'used_words'],
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

        $data = json_decode($response->json('choices.0.message.content'), true);

        if (! is_array($data) || ! isset($data['reply'])) {
            return null;
        }

        return [
            'reply' => $data['reply'],
            'used_word_ids' => self::matchUsedWords((array) ($data['used_words'] ?? []), $remainingWords),
        ];
    }

    /**
     * Resolve the terms the model reported as "said" back to card ids.
     *
     * The model only ever sees terms, so it echoes one back rather than an id. Matching
     * is deliberately generous — accents and punctuation stripped, plus a substring test
     * both ways — because the model returns the form the learner actually wrote. It also
     * means one inflected mention can clear several near-identical target words, which
     * is the intended behaviour.
     *
     * @param  array<int, mixed>  $usedTerms  terms the model reported
     * @param  array<int, array{id:int, term:string}>  $remainingWords
     * @return array<int, int>
     */
    private static function matchUsedWords(array $usedTerms, array $remainingWords): array
    {
        $normalize = fn (string $t) => trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($t))));

        $ids = [];

        foreach ($usedTerms as $usedTerm) {
            $used = $normalize((string) $usedTerm);
            if ($used === '') {
                continue;
            }

            foreach ($remainingWords as $word) {
                $term = $normalize((string) $word['term']);
                if ($term === '') {
                    continue;
                }

                // Substring matching only above 3 characters, so a short term like "a"
                // cannot sweep up every other word on the list.
                $loose = min(mb_strlen($term), mb_strlen($used)) >= 4
                    && (str_contains($used, $term) || str_contains($term, $used));

                if ($term === $used || $loose) {
                    $ids[] = (int) $word['id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * After the chat ends, produce gentle corrections of the mistakes the learner made
     * (wrong form → correct form + short why) as bullet fragments. Terms stay in the
     * target language; explanations are in the native language so the learner
     * understands. Returns the decoded array or null.
     *
     * Which target words were used is NOT asked for: the server already owns that and
     * overwrites whatever the model would say, so generating it was wasted tokens.
     *
     * @param  array<int, array{role:string, content:string}>  $messages  full transcript
     */
    public static function conversationRecap(array $messages, string $targetLanguage, string $nativeLanguage, ?string $level = null): ?array
    {
        $transcript = collect($messages)
            ->map(fn ($m) => ($m['role'] === 'assistant' ? 'Partner' : 'Learner').': '.$m['content'])
            ->implode("\n");

        $response = Http::withToken(config('services.openai.secret'))->post('https://api.openai.com/v1/chat/completions', [
            'model' => self::MODEL,
            'reasoning_effort' => self::REASONING_EFFORT,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a supportive language tutor reviewing a {$targetLanguage} practice conversation for a learner whose native language is {$nativeLanguage}. Look ONLY at the learner's lines. Correct real mistakes they actually made and never invent problems. Write every correction as a SHORT BULLET FRAGMENT — never a full sentence. Keep {$targetLanguage} words in {$targetLanguage}; write every explanation and 'why' in {$nativeLanguage}.".self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => "Conversation transcript:\n{$transcript}\n\nGive concrete corrections for any grammar or word-form errors the learner made.",
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
                            'corrections' => [
                                'type' => 'array',
                                'description' => "Short bullet fragments: the learner's wrong form -> correct form - short why (in {$nativeLanguage}). Empty array if there were no real mistakes.",
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['corrections'],
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
                                'description' => "A short label for the situation, in {$targetLanguage} — no narration, no greeting, no dialogue.",
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
     * Open a Conversation Challenge GAME: pick a random everyday scene and return the first
     * in-character line plus the first task. Unlike the plain challenge, the learner sets no
     * preferences — the AI decides the scene. The 'reply' is spoken by a character to the
     * learner (in {$targetLanguage}); the 'suggestion' is a concise {$nativeLanguage}
     * instruction telling the learner WHAT to convey next, WITHOUT prescribing any grammar.
     *
     * @return array{scene:string, reply:string, suggestion:string}|null
     */
    public static function startChallengeGame(string $targetLanguage, string $nativeLanguage, ?string $level = null, ?string $cacheKey = null, ?string $scene = null): ?array
    {
        $situation = $scene !== null && trim($scene) !== ''
            ? "The situation for this game is: {$scene}. Base the scene, the first line and every task on it."
            : 'Pick ONE random natural everyday situation yourself (ordering food, asking for directions, checking into a hotel, meeting a friend, etc.).';

        $payload = [
            'model' => self::MODEL,
            'reasoning_effort' => 'low',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are running a spoken-{$targetLanguage} practice GAME for a learner whose native language is {$nativeLanguage}. {$situation} Return three things:\n"
                        ."1. 'scene' — a short setup describing the situation, written in {$nativeLanguage} so the learner understands the context.\n"
                        ."2. 'reply' — the first in-character line, spoken BY A CHARACTER in the scene TO the learner, written ONLY in {$targetLanguage}, 1-2 short sentences that prompt the learner to respond (e.g. a waiter asking what they would like).\n"
                        ."3. 'suggestion' — a concise instruction in {$nativeLanguage} telling the learner what to say/convey in {$targetLanguage} in their reply. Be specific and go straight to the point in a few words (e.g. \"order a drink for you and your friend and ask if the pizza has vegan options\"). Describe only the INFORMATION to convey. NEVER name or prescribe a grammar structure or specific words to use.".self::levelInstruction($level),
                ],
                [
                    'role' => 'user',
                    'content' => 'Start the game.',
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'challenge_game_opening',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'scene' => [
                                'type' => 'string',
                                'description' => "Short setup of the situation, in {$nativeLanguage}.",
                            ],
                            'reply' => [
                                'type' => 'string',
                                'description' => "The first in-character line spoken to the learner, in {$targetLanguage}.",
                            ],
                            'suggestion' => [
                                'type' => 'string',
                                'description' => "Concise instruction in {$nativeLanguage} of what to convey next in {$targetLanguage}; no grammar prescribed.",
                            ],
                        ],
                        'required' => ['scene', 'reply', 'suggestion'],
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
            Log::error('AI refused to start challenge game: '.$response->json('choices.0.message.refusal'));

            return null;
        }

        if (! $response->successful()) {
            Log::error('AI challenge game start failed: '.$response->status().' - '.$response->body());

            return null;
        }

        $data = json_decode($response->json('choices.0.message.content'), true);

        return is_array($data) && isset($data['reply']) ? $data : null;
    }

    /**
     * Play one GAME turn. Judges the learner's MOST RECENT message against the task they were
     * set ($task), then continues the scene in character and sets the next task. In ONE call:
     *   - task_completed: did the message convey the information the task asked for?
     *   - task_feedback: short {$nativeLanguage} explanation of what information was missing
     *     (only when task_completed is false; empty otherwise).
     *   - mistakes: EVERY individual language mistake in that message, listed separately, each
     *     with the wrong fragment and a short {$nativeLanguage} explanation + correct form.
     *     Typos / fast-typing slips are IGNORED — same rule as the other chat modes.
     *   - reply: the next in-character line in {$targetLanguage}.
     *   - suggestion: the next task in {$nativeLanguage}.
     *
     * The AI only *reports* mistakes — the caller owns the whole game: it costs one life per
     * listed mistake (plus one for a missed task), ends the game when the lives run out or the
     * turn limit is cleared, and collects the mistakes for the end-of-game summary.
     *
     * Built for prompt caching: an invariant system prefix (role/rules/level) sits first so the
     * growing transcript is cache-served; the per-turn-changing task is a small trailing system
     * message. $cacheKey routes a game's turns to the same cache.
     *
     * @param  array<int, array{role:string, content:string}>  $messages  running transcript (last entry = learner's latest message)
     * @return array{task_completed:bool, task_feedback:string, mistakes:array<int, array{quote:string, explanation:string}>, reply:string, suggestion:string}|null
     */
    public static function challengeGameReply(array $messages, string $task, string $targetLanguage, string $nativeLanguage, ?string $level = null, ?string $cacheKey = null): ?array
    {
        $system = [
            'role' => 'system',
            'content' => "You are running a spoken-{$targetLanguage} practice GAME for a learner whose native language is {$nativeLanguage}. Each turn the learner is given a task (what to convey) and writes a reply in {$targetLanguage}. You must do all of the following:\n"
                ."1. Judge whether their MOST RECENT message accomplishes the task they were given (set task_completed). They pass as long as they convey the required information in {$targetLanguage} — accept any correct wording; do not require specific phrasing.\n"
                ."2. If task_completed is false, write 'task_feedback': a short {$nativeLanguage} explanation of what information was missing. Otherwise leave it empty.\n"
                ."3. List in 'mistakes' EVERY individual language mistake in that message, each as its OWN entry: the exact wrong fragment ('quote', copied from their message in {$targetLanguage}) and a short {$nativeLanguage} 'explanation' naming the mistake and giving the correct form. Count only REAL errors — grammar, word form, word order, or a clearly wrong word for the situation. IGNORE typos, spelling slips, missing accents/diacritics and capitalisation. Never list the same mistake twice, and never list more than 4. Return an empty array when the message is correct.\n"
                ."4. 'reply': continue the scene in character, ONLY in {$targetLanguage}, 1-2 short natural sentences that react to what they said and prompt the next response. Stay in character and NEVER mention the mistakes here.\n"
                ."5. 'suggestion': the next task — a concise {$nativeLanguage} instruction of what to convey next in {$targetLanguage}, specific and to the point in a few words, describing only the INFORMATION to convey and NEVER prescribing a grammar structure or specific words. Be creative and make the conversation diverse.".self::levelInstruction($level),
        ];

        // Per-turn-changing task goes in a trailing system message (kept out of the cached prefix).
        $taskMessage = [
            'role' => 'system',
            'content' => "The task the learner was asked to accomplish in their latest message: \"{$task}\".",
        ];

        $payload = [
            'model' => self::MODEL,
            'reasoning_effort' => 'low',
            'messages' => array_merge([$system], $messages, [$taskMessage]),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'challenge_game_turn',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'task_completed' => [
                                'type' => 'boolean',
                                'description' => 'True if the learner\'s latest message conveyed the information the task asked for.',
                            ],
                            'task_feedback' => [
                                'type' => 'string',
                                'description' => "Short {$nativeLanguage} explanation of what information was missing. Empty when task_completed is true.",
                            ],
                            'mistakes' => [
                                'type' => 'array',
                                'description' => 'One entry per individual language mistake in the latest message; empty when it is correct. Never more than 4, never the same mistake twice. Typos, spelling, accents and capitalisation are not mistakes.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'quote' => [
                                            'type' => 'string',
                                            'description' => "The exact wrong fragment copied from the learner's message, in {$targetLanguage}.",
                                        ],
                                        'explanation' => [
                                            'type' => 'string',
                                            'description' => "Short {$nativeLanguage} explanation naming the mistake and giving the correct form.",
                                        ],
                                    ],
                                    'required' => ['quote', 'explanation'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'reply' => [
                                'type' => 'string',
                                'description' => "The next in-character line in {$targetLanguage}. Never mentions the mistakes.",
                            ],
                            'suggestion' => [
                                'type' => 'string',
                                'description' => "The next task in {$nativeLanguage}; no grammar prescribed. Don't be monotonous.",
                            ],
                        ],
                        'required' => ['task_completed', 'task_feedback', 'mistakes', 'reply', 'suggestion'],
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
            Log::error('AI refused challenge game turn: '.$response->json('choices.0.message.refusal'));

            return null;
        }

        if (! $response->successful()) {
            Log::error('AI challenge game turn failed: '.$response->status().' - '.$response->body());

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
