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
   describe that exact term. A **single word** is reduced to its **base/dictionary form**
   (`broken` → `break`, `vetting` → `vet`) and **kept as a single word** — never expanded into a
   collocation. An existing **multi-word phrase is kept as written** (spelling fixes only): its
   internal grammar is part of the phrase, so `vetting candidates` does *not* become
   `vet candidates`. An `expression` becomes a **reusable frame** (see Card shapes below).
   Since the two-call split this is no longer only a prompt rule — `phrase` is produced by call 1
   and passed *into* call 2 as an input, so the content call has no opportunity to drift.
2. **The example `sentence` bracket rule.** It must contain the term wrapped in square brackets
   **exactly once**, in whatever inflected form it takes there (`[term]`), because the learning
   UI's blanking regex is `/\[.*?\]/` (see [learning-flow](learning-flow.md) and [cards](cards.md)) — this is what turns
   the sentence into a flashcard front and what the "Sentences — writing" mode checks the typed
   answer against. Punctuation must stay outside the brackets.
3. **The `sentence` must be rich enough to guess the term from.** There is an explicit floor:
   "at least 6 words besides the term, naming a concrete situation, actor or result, so a
   learner who does not know the term could work out its meaning from the surrounding words
   alone," with a worked negative in the schema (`"It is [nice]."` is explicitly called invalid).
   Without a numeric floor, the model wrote near-empty frames, especially at low CEFR levels,
   because "illustrative" alone wasn't a strong enough constraint.
4. **`examples` must never contain square brackets**, and **every fragment must contain the term
   itself** — a synonym or antonym is explicitly called out as invalid, because at A1 the model
   once answered `coward` with the fragment `"a brave soldier"` (a fragment about the *opposite*
   concept). Each negative rule in the schema descriptions was added in direct response to one
   observed failure like this — they read like an itemized bug list because that's what they are.
   The bracket rule earned a worked negative of its own (`"a rooted tree with three levels"`, NOT
   `"a rooted [tree] with three levels"`) after the model bracketed fragments for `tree` in a
   graph-theory context, and `Card::persist()` now strips brackets defensively as well — see
   [cards](cards.md) for why a bracket in a fragment is actively harmful, not just untidy.
5. **CEFR level caps difficulty, never length.** `AI::levelInstruction()` and the
   `config/proficiency.php` descriptions describe which words/structures are allowed at a level,
   and end with an explicit sentence saying so ("This caps difficulty, not length — never write
   less than a field asks for"). The level descriptions deliberately contain **no length
   wording** — an earlier version of the A1/A2 descriptions said "very short sentences", and the
   model responded with two- and three-word example sentences that gave no context to guess the
   term from. Where a prompt genuinely wants brevity (chat turns, recap bullets), it says so
   itself, separately from the level instruction.

## Card shapes and `cards.term_type`

Card creation is **two calls**: a router (`AI::analyzeTerm`) that decides the shape and fixes the
term, then one of three generators that writes that shape's fields. The shapes:

| Shape | What it is | `phrase` | `word` | `examples` |
|---|---|---|---|---|
| **word** | a naming unit the learner gave as one word, with no phrase to be had | the word, base form | `null` | 3 natural phrases the word occurs in |
| **phrase** | a naming unit of several words - typed as such, inferred from context, or picked from a word card's suggestions | the phrase, canonical | the focus word **in the form the phrase uses**, else `null` | *empty* |
| **expression** | a ready-made utterance or utterance frame | reusable frame with `[something]` slots | `null` | *empty* |

`term_type` on the row is unchanged and still binary: `expression` → `TYPE_EXPRESSION`, the other
two → `TYPE_LEXICAL`. So `Learning::modeTypeFilter`, the `/cards` type filter and the user-facing
type `<select>` in `cards/edit.blade.php` all work exactly as before. The word/phrase distinction
needs **no column**: a word card is the lexical card that *has* `example_*` fragments.

The axis between lexical and expression is unchanged — **naming unit vs. ready-made utterance**,
*not* word count:

- **`lexical`** — a naming unit; answers *"X means …"*. Single words (`cabinet`),
  collocations/compounds (`traffic jam`, `make a decision`), and **idioms**
  (`under the weather`, `kick the bucket`) are all lexical, because each names a concept.
  The test for an idiom is **substitution**: one ordinary word of a single part of speech can
  stand in its place in a neutral third-person sentence — *under the weather* → ill,
  *kick the bucket* → die, *a piece of cake* → easy.
- **`expression`** — a ready-made utterance or utterance *frame* performing a communicative
  function (refusing, requesting, hedging, warning, greeting); answers *"you say X when you want
  to …"*: `I'd rather not`, `can you hand me the [something]`, `you better be ready`.

A pasted **full sentence** is not a fourth shape — it is normalized into the reusable frame
(`I would like to go to the cinema tomorrow` → `I would like to [do something]`) and classified
`expression`.

**Idioms are the hard case, and they used to fall the wrong way.** `not my cup of tea` was
classified `lexical`, because every cue the router had for `expression` was *grammatical* — a
subject pronoun with a finite verb, a speech-act clause, a variable slot — and that phrase has
none of them, while the one semantic cue in the prompt ("idioms are lexical") argued actively for
the wrong answer. So a fixed phrase was pulled toward `lexical` no matter what it did.

Two rules now separate them, and both are in `card_kind`'s description:

1. **The substitution test above**, stated as the *condition* for an idiom being lexical rather
   than as a blanket grant.
2. **Speaker anchoring.** A phrase containing `my` / `me` / `I` / `you` / `we` (or the language's
   equivalent), or stating the speaker's stance, evaluation, reaction or willingness rather than
   naming anything, is an `expression` **even as a fragment with no finite verb**: `not my cup of
   tea`, `fine by me`, `no skin off my nose`, `over my dead body`. This is the discriminating
   feature — *under the weather* describes a state anyone can be in, *not my cup of tea* cannot be
   said without the speaker being in it.

The tie-break is explicit: when *"X means …"* and *"you say X when you want to …"* both seem to
fit, **expression wins**. That direction is deliberate — it is the side the router was observed
erring on, and the cost is asymmetric: a misfiled expression gets a dictionary definition and a
word-for-word translation, which is simply wrong for it, whereas a misfiled naming unit merely
gets a usage note that is a little wordy.

The column is `string` with a **DB-level default of `'lexical'`** (migration
`2026_08_08_000001_add_term_type_to_cards_table`), which backfills existing rows and covers the
manual `/add` path (`CardController::save`, which never sets it). The type is assigned at capture
but is **user-correctable**: `cards/edit.blade.php` renders it as a `<x-forms.select>` and the
update route validates it with `Rule::in(Card::TERM_TYPES)`.

## Why two calls

Card generation used to classify **and** generate in one call, with `term_type` first in the schema
so the model committed to the type before the fields that depend on it. That worked only because
both types shared one output shape.

They no longer do: a word card has `examples`, a phrase card has `word` and no examples, an
expression card has neither. A strict `json_schema` **cannot be conditional**, so a single call
would mean one union schema policed by "return an empty array if …" instructions — precisely the
pattern that once made the model return an empty `examples` array for lexical terms, and that the
duplicated rule in the old `typeRules()` existed to paper over. Splitting is the structural fix.

The larger payoff is prompt rule #1 above: `phrase` is now an **input** to the content call, so
"never swap the learner's term" is guaranteed by construction rather than by instruction.

The cost is one extra round trip. It is kept small: `analyzeTerm` uses a tiny schema and
`reasoning_effort: 'low'` (the precedent set by the chat-turn methods), and its whole answer is a
few dozen tokens. The **browser extension shares this endpoint** and pays the same latency — see
[browser-extension](browser-extension.md).

### Call 1 — `AI::analyzeTerm($term, $termLanguage, $context = null): ?array`

One method covers every case: bilingual vs. native and CEFR level are irrelevant to a decision
about shape and canonical form. Returns
`{card_kind: 'word'|'phrase'|'expression', phrase, word, submitted_form}` — the last two as empty
strings when they do not apply, normalized to `null` in PHP.

- **`card_kind` is first in the schema**, for the same key-order reason the old `term_type` was.
- **`phrase`** carries prompt rule #1, with the base-form reduction promoted to the *first* clause
  rather than buried mid-paragraph — the old wording let `vetting` through unreduced.
- **`word`** is only filled for a `phrase` card built around a single word the learner gave, and
  must be spelled as the phrase spells it. See [cards](cards.md) for the invariant and the PHP
  guard that enforces it.
- **`submitted_form`** is the learner's own inflected form when they typed one (`vetting` for
  `vet`). The form someone types is the form they met the word in, so the word card uses it to make
  **exactly one** of its suggestions read the way they encountered it. It is an AI field rather
  than a PHP comparison against the raw input because the raw input may be misspelled (`runing`),
  and feeding a misspelling into example generation would poison it.

### Call 2 — three generators

```
AI::generateWordCard($phrase, $submittedForm, $language, $nativeLanguage, $context, $level)
AI::generatePhraseCard($phrase, $focusWord, $language, $nativeLanguage, $context, $level)
AI::generateExpressionCard($phrase, $language, $nativeLanguage, $context, $level)
```

**`$nativeLanguage === null` means a monolingual native-language card.** That single flag replaces
the whole former `getContentForCardNative` variant: it drops `translation` from both `properties`
and `required`, and skips `levelInstruction()`/`fieldContrastRule()` (a user isn't learning their
own language, so a difficulty cap doesn't apply). `$context === null` drops the sense-fixing
clauses. Three methods therefore cover the six cases the three old near-duplicate generators did;
`getContentForCard`, `getContentForCardWithContext` and `getContentForCardNative` are gone, and so
are `typeRules()` and `termTypeProperty()`, absorbed into `analyzeTerm`.

`levelInstruction()`, `definitionLanguage()` and `fieldContrastRule()` are unchanged and still
shared. All four calls go through one private `requestCardJson()` helper, which checks
`successful()` and `refusal`, logs, and returns a **decoded array** or `null` — the old card
generators were the only methods in this file that did none of that and returned a raw string for
the caller to parse.

Every generator writes about the card's **target**, which is `word ?? phrase` — see
[cards](cards.md) "What the card is built around". For two of the three shapes that is trivially
the Term itself; the phrase card is where it bites, so `generatePhraseCard` branches on
`$focusWord` and is the only generator that does:

| Field | `word` | `phrase` **with** a focus word | `phrase` **without** one | `expression` |
|---|---|---|---|---|
| `word` | *(absent)* | **first in the schema**: the focus word as this phrase spells it | *(absent)* | *(absent)* |
| `sentence` | term bracketed once, in whatever form it takes | whole phrase present, **only the focus word** bracketed | the **whole phrase** bracketed once | whole expression bracketed once, every slot filled with real words |
| `translation` | word-level, ≤2 variants separated by `; ` | the **focus word**, in the sense the phrase gives it | the **whole phrase** - a natural equivalent, never word-by-word, ≤2 variants | **exactly one** functional equivalent, not literal, slots localized (`[něco]`, never `[something]`) |
| `definition` | dictionary-style | what the **focus word** means inside this phrase | what the **whole phrase** means | **usage note** ("used to politely refuse something you have been offered") |
| `examples` | **3 natural phrases the word occurs in**, 2-3 words each | *(absent)* | *(absent)* | *(absent)* |

These rules exist because of specific observed failures, and removing one reopens it:

- **`examples` are phrases, not an inflection drill.** The old prompt demanded "a DIFFERENT
  grammatical form in each of the three", which is the opposite of what these are for now: each
  fragment is a click target that can become a real card, so it must use the term in the form
  `phrase` gives it.
- **`submitted_form` is confined to exactly one example.** Written as "at least one", it took the
  card over: asked for `vet` typed as `vetting`, the model wrote all three examples *and* the
  translation (`prověřování`, the noun) about `vetting`, so a card whose term was `vet` never
  showed `vet` anywhere. The system prompt and the `translation` description now both say that
  every other field describes the term as given.
- **`generatePhraseCard`'s `word` property is placed first**, and is omitted entirely when no
  focus word was supplied. First, for the same key-order reason `card_kind` is first: every other
  field on a focused phrase card is written *about* that word, so the model has to settle which
  form of it the phrase uses before it writes any of them. (It was briefly last, from the earlier
  design where the whole phrase was the target and the single word had to be kept from pulling the
  other fields toward it — that inverted with the target rule above.)
- **Suggestions and inferred phrases are capped at 2-3 words.** A fragment is a click target that
  becomes a real card's `phrase`, and a long one buries the word it exists to teach: "an
  adversarial relationship", not "an adversarial relationship between rival teams". The same cap is
  written into `analyzeTerm`'s `phrase` description, which otherwise lifts whole clauses out of a
  sentence the learner pasted as context.

Both callers are on the `Card` model, not in a controller — `Card::createFromTerm()` (call 1 + call
2) and `Card::createFromPhrase()` (call 2 only, for the suggestion-click path). See
[cards](cards.md).

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
ever told one language to write in. Consequently every bilingual user message says "Target
language: … Native language: … Each field says which of the two it must be written in" rather
than labelling the native one as translation-only. For a monolingual native card
(`$nativeLanguage === null`) it resolves to the card's own language either way.

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

Every method in this file returns `null` on refusal or a non-2xx response, and logs via
`Log::error`/`logger()` first. Every caller must check for `null`/failure before proceeding — see
[overview](overview.md)'s "AI Integration" coding-standard note. None of these methods throw on an
API failure; they degrade to `null` so the caller can show the user a plain retry message instead
of a 500.

The card-creation path used to be the exception — its three generators checked nothing and returned
a raw string, and `AjaxController` had its `return` on a null response **commented out**, so a
failed call fell through into `trim(null)` and surfaced as a generic caught error. Both are fixed:
`requestCardJson()` does the checking for all four calls, and a `null` from `Card::createFromTerm()`
is now handled explicitly.
