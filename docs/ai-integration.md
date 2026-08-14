# AI Integration

Everything about how Frase talks to OpenAI: the model/params used, the prompt-design rules that
were learned the hard way, and every generator method on `App\Models\AI`. All OpenAI calls in the
app are centralized in this one model — there is no AI logic anywhere else except the thin
controller code that assembles arguments and interprets the result.

## Model & params

- **Chat model**: `AI::MODEL` = `gpt-5.6-luna`, called via the Chat Completions endpoint
  (`POST /v1/chat/completions`) with `AI::REASONING_EFFORT` = `medium` by default.
  Latency-sensitive calls — every conversation/challenge/game **turn** (not the opening or the
  recap) — pass `reasoning_effort: low` explicitly instead, trading some instruction-following
  for a snappier chat.
- **Embeddings**: `text-embedding-3-small` via `/v1/embeddings` (`AI::getEmbedding`).
- **Realtime (voice)**: `gpt-realtime-2.1-mini` — see [conversation-voice](conversation-voice.md).
- Reasoning models reject the `temperature` param, so it is never sent anywhere in this file.
- Every structured response uses a strict `json_schema` response format
  (`"strict" => true, "additionalProperties" => false`), never free-text parsing.
- **Luna vs. the previous nano model**: `gpt-5.6-luna` follows per-field instructions far more
  reliably than the `gpt-5.4-nano` model used previously. Several defensive repetitions in the
  prompts were trimmed when Luna landed, but not removed wholesale — a few (like the `examples`
  rule repeated in `typeRules()`, see below) stayed because removing them caused a regression
  even under Luna.

## Prompt-design rules learned from failures

These are not stylistic choices — each one exists because a specific, more naive prompt produced
a specific bad output. Keep them if you touch these prompts; removing one reopens the failure it
fixed.

1. **The term is the learner's own word.** The model must never swap it for a different term: it
   spelling-corrects and reduces it to a canonical form, but the *field content* must always
   describe that exact term. For a `lexical` term this is the **base/dictionary form**
   (`broken` → `break`). For an `expression` it is a **reusable frame** (see Term types below).
   A single word submitted by the learner is **kept as a single word** — the model must not
   expand it into a collocation — and an existing multi-word phrase is kept as written (only
   spelling/base-form fixes).
2. **The example `sentence` bracket rule.** It must contain the term wrapped in square brackets
   **exactly once**, in whatever inflected form it takes there (`[term]`), because the learning
   UI's blanking regex is `/\[.*?\]/` (see [learning-flow](learning-flow.md) and [cards](cards.md)) — this is what turns
   the sentence into a flashcard front and what the "Sentences — writing" mode checks the typed
   answer against. Punctuation must stay outside the brackets.
3. **The `sentence` must be rich enough to guess the term from.** There is an explicit floor:
   "at least 6-8 words besides the term, naming a concrete situation, actor or result, so a
   learner who does not know the term could work out its meaning from the surrounding words
   alone," with a worked negative in the schema (`"It is [nice]."` is explicitly called invalid).
   Without a numeric floor, the model wrote near-empty frames, especially at low CEFR levels,
   because "illustrative" alone wasn't a strong enough constraint.
4. **`examples` must never contain square brackets**, and **every fragment must contain the term
   itself** — a synonym or antonym is explicitly called out as invalid, because at A1 the model
   once answered `coward` with the fragment `"a brave soldier"` (a fragment about the *opposite*
   concept). Each negative rule in the schema descriptions was added in direct response to one
   observed failure like this — they read like an itemized bug list because that's what they are.
5. **CEFR level caps difficulty, never length.** `AI::levelInstruction()` and the
   `config/proficiency.php` descriptions describe which words/structures are allowed at a level,
   and end with an explicit sentence saying so ("This caps difficulty, not length — never write
   less than a field asks for"). The level descriptions deliberately contain **no length
   wording** — an earlier version of the A1/A2 descriptions said "very short sentences", and the
   model responded with two- and three-word example sentences that gave no context to guess the
   term from. Where a prompt genuinely wants brevity (chat turns, recap bullets), it says so
   itself, separately from the level instruction.

## Term types (`cards.term_type`)

Every card is classified at capture as either `lexical` or `expression`
(`Card::TYPE_LEXICAL` / `Card::TYPE_EXPRESSION`, `Card::TERM_TYPES`). The axis is **naming unit
vs. ready-made utterance**, *not* word count:

- **`lexical`** — a naming unit; answers *"X means …"*. Single words (`cabinet`),
  collocations/compounds (`traffic jam`, `make a decision`), and **idioms**
  (`under the weather`, `kick the bucket`) are all lexical, because each names a concept.
- **`expression`** — a ready-made utterance or utterance *frame* performing a communicative
  function (refusing, requesting, hedging, warning, greeting); answers *"you say X when you want
  to …"*: `I'd rather not`, `can you hand me the [something]`, `you better be ready`.

A pasted **full sentence** is not a third type — it is normalized into the reusable frame
(`I would like to go to the cinema tomorrow` → `I would like to [do something]`) and classified
`expression`.

The column is `string` with a **DB-level default of `'lexical'`** (migration
`2026_08_08_000001_add_term_type_to_cards_table`), which backfills existing rows and covers the
legacy creation paths (`CreateCardJob`, `CallAIJob`, the manual-create route closure — all dead,
see [overview](overview.md)) without touching them. The type is assigned by the AI at capture but is
**user-correctable**: `cards/edit.blade.php` renders it as a `<x-forms.select>` and the update
route validates it with `Rule::in(Card::TERM_TYPES)`.

### Type-conditional field generation

All three card generators (below) classify **and** generate in a *single* call. `term_type` is
the **first property** in every schema — strict structured outputs emit keys in schema order, so
the model commits to the type before writing the fields that depend on it. This is what makes a
separate classifier call unnecessary (no extra latency on the capture path). Two helpers hold the
shared text so it's defined once: `AI::typeRules()` (system-prompt fragment defining the two
types) and `AI::termTypeProperty()` (the shared `term_type` schema property).

| Field | `lexical` | `expression` |
|---|---|---|
| `phrase` | base/dictionary form, **never bracketed** | canonical frame — pronoun + contraction kept, situation-specific tail dropped, each variable part as a **dictionary-style bracketed placeholder** `[something]` / `[someone]` / `[somewhere]` / `[do something]` (never `...`) |
| `definition` | dictionary-style definition | **usage note** ("used to politely refuse something you have been offered") — same language rule as lexical, see below |
| `translation` | word-level, ≤2 variants separated by `; ` | **exactly one** functional equivalent — what a native speaker actually says in that situation, explicitly *not* literal, no `;`. It translates the **frame**, so a slot reappears as a placeholder **in the native language** (`[něco]`, `[etwas]`) — never the English `[something]` |
| `sentence` | one sentence, term bracketed once | one sentence using the expression in an everyday situation, every `[something]` slot filled with **real words** and the **whole expression** bracketed once |
| `examples` | 3 × 2-4 word collocation fragments, no final period | **empty array** — an expression is illustrated by its sentence alone |

`examples` never contains square brackets, and the "bracket the term exactly once" rule holds
for **both** types, so the Sentences learning mode works for expressions too.

## Card generation variants

Three near-identical generators, each returning a JSON string with `term_type`, `phrase`,
`sentence`, `examples`, `definition`, `theme`, and (except the native variant) `translation`.
All are called from `AjaxController@index` (see [cards](cards.md) for how the result is mapped onto the
`cards` row); none run on a queue — card creation is synchronous.

- **`AI::getContentForCard($phrase, $themes, $targetLanguage, $nativeLanguage, $level = null)`**
  — the default bilingual generator, no extra context.
- **`AI::getContentForCardWithContext($phrase, $themes, $targetLanguage, $nativeLanguage,
  $context, $level = null)`** — same shape, plus a `$context` string (the sentence/snippet the
  learner saw the term in). The context **fixes which sense/domain** the card is about, so
  **every** field — including all three example fragments — must reflect only that meaning
  (e.g. "tree" in a graph-theory context → "binary tree", "spanning tree", never "climb a tree").
  This constraint is stated in both the system prompt and the `examples` property description.
- **`AI::getContentForCardNative($phrase, $themes, $nativeLanguage, $context = null)`** — the
  **monolingual** variant, used when the save destination *is* the user's native language
  (`AjaxController@index` branches on `$language->id === $user->native_language_id`). Every
  field is written in the native language, there is **no `translation`** (schema omits it; the
  card stores `translation = ''` since the column is `NOT NULL`), and no CEFR steering is
  applied (a user isn't learning their own language, so a difficulty cap doesn't apply). Takes
  an optional `$context` to cover both the plain and context-supplied cases in one method,
  rather than needing a fourth variant.

`AjaxController@index` picks between them: native destination → `getContentForCardNative`;
otherwise `getContentForCardWithContext` if a context was supplied, else `getContentForCard`.

### CEFR level and the `definition` language

`AI::levelInstruction($level)` builds the difficulty-capping instruction fragment described
above, pulled from `config/proficiency.php` (`levels` map, `A1`..`C2`, plus a `default` and a
`names` map for the UI). It returns `''` when no/unknown level is given, so prompts are unchanged
for a language with no proficiency set yet.

The **`definition` field's language depends on the level**: at `A1`/`A2`/`B1`
(`AI::NATIVE_DEFINITION_LEVELS`) it is written in the **native** language; from `B2` up (and when
no level is set) it's in the **target** language — below B2 a target-language definition is
usually harder to read than the term it explains. This is **not** left to the model to decide:
`AI::definitionLanguage($level, $target, $native)` resolves it in PHP and the resolved language
name is interpolated straight into the `definition` property description, so the model is only
ever told one language to write in. Consequently the two bilingual user messages say "Target
language: … Native language: … Each field says which of the two it must be written in" rather
than labelling the native one as translation-only.

**Field-collision guard**: once `definition` is native, it shares a language with `translation`,
and the model started **swapping them** — e.g. German `schön` came back with definition
`"hezký; krásný"` (that's a translation) and translation `"To je hezké."` (that's a sentence, and
in the wrong field). `AI::fieldContrastRule($definitionLanguage, $nativeLanguage)` appends one
sentence to the system prompt — *"both are in X but are NOT the same: the translation is the
equivalent term, the definition explains what it means"* — and is emitted **only** when the two
languages actually match, so B2+ cards (where they differ) don't pay the extra prompt tokens for
a rule that can't apply. The two property descriptions also carry the same contrast as explicit
negatives plus a worked `schön` example each.

## Related generators

- **`AI::generateThemes($phrases, $targetLanguage)`** — groups a semicolon-joined list of a
  user's phrases into up to 10 theme names. Called from `ThemeController@generate`, which is
  scratch/debug code (`dd()`s the result) — not wired into a real flow. See [wordboxes-themes-tags](wordboxes-themes-tags.md).
- **`AI::generateTextWithGaps($phrases, $targetLanguage, $wordboxName, $themePreference = null,
  $level = null)`** — writes a short story that naturally works in every supplied phrase
  (adapting inflection/form as needed, not requiring verbatim use), replaces each with a numbered
  `[n]` placeholder, and returns `{text, title, answers}` where `answers` maps each index to the
  exact text that belongs in that gap. Runs on the queue (`GenerateGapFillJob`), not
  synchronously — see [gap-fill](gap-fill.md).
- **`AI::getEmbedding($text)`** / **`AI::cosineSimilarity($a, $b)`** — embedding + similarity
  helpers used for (currently paused) automatic card linking. See [search-and-linking](search-and-linking.md).

## Conversation & challenge chat methods

These power the three separate AI-chat features (see their own docs for the surrounding
controller/session logic): the SRS **Conversation** learning mode, the free-form **Conversation
Challenge** (text/voice/game). Unlike the card generators, these take a **multi-message
transcript** and are built around **OpenAI's automatic prompt-prefix cache**.

### Prompt caching pattern (shared by all turn-based chats)

Every `*Reply`/turn method builds its `messages` array as:

1. An **invariant system prefix** — role, rules, CEFR level, and (for the challenge variants)
   scene/feedback-focus — identical on every turn of one chat. Because it's first and unchanging,
   OpenAI serves the (unchanged) growing transcript below it from cache instead of reprocessing it.
2. The actual **running transcript** (`$messages`, appended to each turn from the session).
3. A small **trailing** system message for the one piece of state that *does* change every turn
   (the shrinking remaining-words list for the SRS conversation; the current task for the game)
   — kept at the end, after the cacheable prefix, so it never invalidates the cache.

A per-chat `prompt_cache_key` (minted as `conv-`/`chal-`/`game-` + user id + a UUID, stored in the
relevant session bucket) is passed on every call for that chat so all its turns route to the same
cache. Caching only kicks in above OpenAI's ~1,024-token minimum, so it mainly benefits longer
chats — a two-turn chat won't see much benefit, a ten-turn one will.

### SRS Conversation methods (used by the Learning "Conversation" mode — see [learning-flow](learning-flow.md))

- **`AI::startConversation(array $targetWords, $targetLanguage, $level = null, $cacheKey = null): ?string`**
  — opens the chat: the model invents an everyday scene and asks an opening question steering
  toward one of the target words, **never writing any target word itself**. Returns the opening
  line or `null` on failure/refusal.
- **`AI::conversationReply(array $messages, array $remainingWords, $targetLanguage, $level = null,
  $cacheKey = null): ?array`** — returns `{reply, used_word_ids}`. **Ids never leave PHP**: the
  model is sent **only the words still left to elicit**, as **plain terms**, never card ids (an
  earlier version sent an `id: term` map plus a full avoid-list and the ids occasionally leaked
  into the model's chat replies). It reports back `used_words` — the terms it recognised, copied
  from the list it was given — which `matchUsedWords()` maps back to ids in PHP.
- **`AI::matchUsedWords(array $usedTerms, array $remainingWords): array`** *(private)* — generous
  normalisation (lowercase, strip accents/punctuation) plus a **two-way substring test** guarded
  to terms ≥4 characters (so a short term like "a" can't sweep up everything). This is also what
  lets **one mention clear several near-identical target words** at once — the prompt explicitly
  asks the model to report every covered variant, and the substring match then fans that out to
  every matching id.
- **`AI::conversationRecap(array $messages, $targetLanguage, $nativeLanguage, $level = null): ?array`**
  — end-of-chat bullet-fragment corrections (`corrections` only). Deliberately does **not** ask
  which words were used — the server (`ChatController`) already owns that and would overwrite
  any answer, so asking for it would be wasted tokens.

### Conversation Challenge methods (free-form practice — see [conversation-challenge](conversation-challenge.md))

- **`AI::startChallenge($targetLanguage, $level = null, $scene = null, $cacheKey = null): ?array`**
  — returns `{scene, reply}`: `scene` is a short label shown **above** the chat, `reply` is the
  pure in-character opening line with no scene-setting narration mixed in.
- **`AI::challengeReply(array $messages, $targetLanguage, $nativeLanguage, $level = null,
  $scene = null, $feedbackFocus = null, $cacheKey = null): ?array`** — returns
  `{reply, correction: {has_error, is_typo, feedback}}` in **one call** (reply + correction of the
  learner's latest message together, to save tokens vs. two calls). `correction.feedback` is a
  short **note**, not an echo of the learner's sentence. `is_typo` exists so obvious fast-typing
  slips can be excluded from what reaches the end recap (see below) without excluding real
  grammar/vocabulary gaps.
- **`AI::challengeRecap(array $struggles, $targetLanguage, $nativeLanguage, $level = null,
  $feedbackFocus = null): ?array`** — turns the accumulated per-turn `feedback` notes (not the
  full transcript — token-efficient) into up to 4 native-language recommendation bullets, with
  **no examples** (those were the live corrections already shown).

### Conversation Challenge — Game methods (see [conversation-game](conversation-game.md))

- **`AI::startChallengeGame($targetLanguage, $nativeLanguage, $level = null, $cacheKey = null,
  $scene = null): ?array`** — returns `{scene, reply, suggestion}`. `scene` is native-language
  setup text, `reply` is the first in-character line spoken *to* the learner, `suggestion` is the
  first task: a concise native-language instruction of what information to convey, explicitly
  **never** prescribing grammar or specific words.
- **`AI::challengeGameReply(array $messages, $task, $targetLanguage, $nativeLanguage,
  $level = null, $cacheKey = null): ?array`** — returns
  `{task_completed, task_feedback, mistakes[], reply, suggestion}` in one call. The model **only
  reports** — whether the task was met, and every individual language mistake as its own
  `{quote, explanation}` entry (typos/spelling/accents/capitalisation explicitly ignored, max 4).
  It never sees lives, scoring, or win/lose state — the caller (`ChallengeController`) owns all
  of that. See [conversation-game](conversation-game.md) for how mistakes become lost lives.

### Voice (Realtime) methods — see [conversation-voice](conversation-voice.md)

- **`AI::realtimeInstructions($targetLanguage, $nativeLanguage, $level = null, $scene = null,
  $feedbackFocus = null): string`** — pure string builder (no HTTP call). Produces the Realtime
  session's persistent `instructions`, since there's no per-turn PHP call once the browser is
  connected directly to OpenAI — this one string has to carry the whole conversation's behaviour,
  including "never correct the learner mid-chat; all feedback is in the end recap."
- **`AI::mintRealtimeClientSecret(array $sessionConfig): ?array`** — `POST
  /v1/realtime/client_secrets`, returns an ephemeral client secret so the real API key never
  reaches the browser.
- **`AI::voiceChallengeRecap(array $transcript, $targetLanguage, $nativeLanguage, $level = null,
  $feedbackFocus = null): ?array`** — returns `{did_well, corrections, vocabulary}`. Unlike the
  text-challenge recap, this is the learner's **only** feedback (the voice partner gives none
  live), and the input is a **speech-recognition transcript**, so the prompt explicitly ignores
  typos/mis-transcriptions/pronunciation and never comments on spelling.

## Failure handling

Every method in this file returns `null` (or, for the two-shot card generators, an unparseable
content string) on refusal or a non-2xx response, and logs via `Log::error`/`logger()` first.
Every caller must check for `null`/failure before proceeding — see [overview](overview.md)'s "AI Integration"
coding-standard note. None of these methods throw on an API failure; they degrade to `null` so
the caller can show the user a plain retry message instead of a 500.
