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
  prompts were trimmed when Luna landed, but not removed wholesale where doing so caused a
  regression even under Luna.

## Prompt-design rules learned from failures

These are not stylistic choices — each one exists because a specific, more naive prompt produced
a specific bad output. Keep them if you touch these prompts; removing one reopens the failure it
fixed.

1. **The term is the learner's own word, kept as typed.** The model must never swap it for a
   different term and only ever fixes spelling mistakes — no reduction to a base form, lone words
   included (`kostade` stays `kostade`; its base word carries the lemma `kosta`), no expansion into
   a collocation, and a pasted sentence stays a sentence rather than becoming a frame with
   `[something]` slots. The card then shows exactly the form the learner met. Every *field* must
   describe that exact Term. This is not only a prompt rule — `term` is produced by call 1 and
   passed *into* call 2 as an input, so the content call has no opportunity to drift.
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
4. **CEFR level caps difficulty, never length.** `AI::levelInstruction()` and the
   `config/proficiency.php` descriptions describe which words/structures are allowed at a level,
   and end with an explicit sentence saying so ("This caps difficulty, not length — never write
   less than a field asks for"). The level descriptions deliberately contain **no length
   wording** — an earlier version of the A1/A2 descriptions said "very short sentences", and the
   model responded with two- and three-word example sentences that gave no context to guess the
   term from. Where a prompt genuinely wants brevity (chat turns, recap bullets), it says so
   itself, separately from the level instruction.

## Why two calls

Card creation is **two calls**: `AI::analyzeTerm` (CALL 1) fixes the Term and extracts its base
words at capture, and `AI::generateCard` (CALL 2) writes the card's content. The split exists so
that **CALL 2 runs only at approval**, not right after CALL 1. Capture is CALL 1 alone — cheap, and
the only thing standing between the learner and staging — while CALL 2's cost is paid only for
proposals the learner actually keeps. A single-call design couldn't defer content generation like
this at all.

The other payoff is prompt rule #1 above: `term` is an **input** to the content call, so "never
swap the learner's term" is guaranteed by construction rather than by instruction.

Every Term used to be classified into one of three kinds (`card_kind`), with substitution
tests, sentence-to-frame normalisation and a tie-break in the prompt, and three generators with
different schemas. All of it is gone: there is one kind of card (see [cards](cards.md)).

The cost is one extra round trip. It is kept small: `analyzeTerm` uses a tiny schema and
`reasoning_effort: 'low'` (the precedent set by the chat-turn methods), and its whole answer is a
few dozen tokens. The **browser extension shares this endpoint** and pays the same latency — see
[browser-extension](browser-extension.md).

### Call 1 — `AI::analyzeTerm($term, $candidateLanguages, $nativeLanguage, $context = null): ?array`

One method covers every case: CEFR level is irrelevant to detecting the language and fixing the
Term's spelling, and it does the work that used to require the learner to pick a save destination
up front. `$candidateLanguages` is the learner's own attached set as `[['code' => 'sv', 'name' =>
'Swedish'], …]` — detection picks from it, not from every language there is. Returns:

```
{
  language,                                  // one of the candidate language NAMES
  term,
  senses: [{part_of_speech, gloss, translation}, ...],
  fixed_expressions: [{form, surface_form, translation}, ...],
  base_words: [{lemma, part_of_speech, surface_form, translation, <attributes…>}, ...],
}
```

- **`language` is first**: strict structured outputs emit keys in schema order, so the model
  commits to it before writing anything whose rules depend on it. It is an `enum` over the
  candidate names, so an unknown language can't come back at all.
- **`term`** carries prompt rule #1: typos fixed, nothing else changed.
- **`senses`** is empty unless the Term is a single word, no Context was given, and it has two or
  more common senses (*run* the verb or noun, *bank* money or riverside): then at most 4, gloss and
  translation in the native language. They feed staging's sense picker (see [cards](cards.md)
  "Staging"); it sits before `base_words` so the model has settled the ambiguity before extracting.
- **`fixed_expressions`**: at most 3, from the Term only. The prompt carries the **swap test** (a
  multi-word unit in which no word can be swapped without breaking it or changing its meaning),
  names the three kinds it covers (gapped frames, fixed units, non-literal particle verbs) and
  explicitly excludes ordinary combinations (*make a decision*, *heavy rain*), which models
  otherwise over-report. A Term that is itself a fixed expression is listed too. `form` is
  canonical (`…` for a gap) so the same expression matches across cards. It sits before
  `base_words` because of the next rule.
- **`base_words`** are the Term's words, each reduced to its **lemma**, tagged with its
  **part of speech** (an `enum` over `LanguageGuideline::PARTS_OF_SPEECH`), with the surface form
  the Term actually spells it in and a native translation — the translation is decided here, not
  deferred to CALL 2 (see [cards](cards.md) "The vocabulary base"). The prompt is explicit that
  words come from the `term` field **only**, never from the Context, which is what keeps
  `collateral damage` from putting *damage* in the base; that a word occurring only inside one of
  the `fixed_expressions` is skipped (*tycka om* is learnt whole); and that an **article** is never listed,
  at any level (an article is never worth a vocabulary entry, so there is nothing for PHP to
  decide). Everything level- or learner-dependent — the B1+ function-word filter, known words,
  already-present words — is deterministic PHP after the call instead (see [cards](cards.md)).

Where the **grammatical attributes** go in the schema is the one awkward part, and it is forced:
strict structured outputs need the schema up front, but *which* language the Term is in is
something this same call decides. `AI::baseWordProperties()` therefore adds one property per
attribute **any** of the candidate languages defines, with that attribute's values plus `''` as its
enum — `''` meaning "this word's language and part of speech don't carry this one", which is what
most words return. `AnalyzeProposalJob::grammarAttributes()` then keeps only the attributes the
*detected* language's guideline actually defines for that part of speech, so a Swedish `gender`
arriving on an English noun is discarded rather than stored.

The two filters that narrow this list run in PHP, after the call, not as instructions inside it —
see [cards](cards.md) "Two filters" for why.

Retired: **`word`** (no focus word to spell), **`submitted_form`** (its only consumer, the
`examples` suggestions, is gone), and **`card_kind`** (one card type).

### Language guidelines

`resources/language-guidelines/` holds one PHP file per language code (`en.php`, `sv.php`, …),
consulted by `AI::analyzeTerm`'s prompt builder when it extracts `base_words` for that language.
Each file declares, for the language it covers:

- which parts of speech the language uses (a subset of the shared enum — see [cards](cards.md));
- which parts of speech carry **grammatical attributes**, the valid values for each, and how to
  **display** them (Swedish: `noun` → `gender` → `common`/`neuter`, displayed as *en*/*ett*);
- which parts of speech are written in **dictionary form** (Swedish: `verb` → `komm|a -er`). This
  is a separate declaration from an attribute precisely because it is *not* computable: *en*/*ett*
  follows from `{gender, lemma}` by a fixed mapping, whereas where a verb's stem ends and which
  present-tense ending it takes vary per verb. So CALL 1 is asked for the whole string, its format
  is pinned down by that language's prose note, and the answer is stored — unless the lexicon
  knows the verb (below);
- a short prose note interpolated into CALL 1's prompt so the model applies that language's own
  rule correctly (e.g. "Every Swedish noun is either a common-gender or a neuter word...").

A language with no guideline file still gets `part_of_speech` tagged on every base word (the model
can do this from general knowledge), just no attributes and no dictionary form — see
[cards](cards.md) "The vocabulary base". Nothing here is model/param configuration; it's declarative language data, the same role
`config/proficiency.php` plays for CEFR levels, just keyed by language instead of level.

**`App\Support\LanguageGuideline`** is the only reader. It is a plain class, not an Eloquent model
— there is no row behind it — and besides the raw lookups it owns: `promptNote()` (the language-wide
note plus one per attribute plus the dictionary-form rule, concatenated for CALL 1's system
message), `allAttributes()` (the union used to build CALL 1's schema, above),
`wantsDictionaryForm($partOfSpeech)` / `usesDictionaryForms()` (the per-part-of-speech and
whole-language forms of the same question — the first filters CALL 1's answer in
`AnalyzeProposalJob`, the second decides whether the schema carries the property at all), and
`displayForm()`, which turns `{lemma, part_of_speech, grammar_attributes, dictionary_form}` into
what the learner actually sees (*"ett hus"*, *"komm|a -er"*; a stored dictionary form stands in for
the lemma and the article prefixes still apply around it). `BaseWord::displayForm()` and
`ProposalBaseWord::displayForm()` are thin wrappers over that last one, so staging chips, the
vocabulary base, Words mode and Refresher can never disagree about how a word is spelled.

Both the attribute properties and `dictionary_form` reach CALL 1's `base_words` schema as a **union
across the learner's languages** — the call decides the language in the same answer, so the schema
cannot be narrowed to one guideline up front. A Swedish verb's dictionary form can therefore come
back on an English verb, which is why `AnalyzeProposalJob` discards any value the *detected*
language's guideline does not ask for.

**For Swedish, CALL 1's attribute and dictionary-form answers are only a fallback.** Where the
lexicon (a downloaded dictionary — see [cards](cards.md) "The lexicon") knows the word, its value
wins; the model's answer survives only for a word the lexicon lacks, or as the tie-break between
homographs, where it may pick only among the values the lexicon allows. The prompt and schema are
unchanged — the model still has to answer, because it is that fallback and that tie-break. A
language with no lexicon rows keeps the AI-only path as it was.

### Call 2 — `AI::generateCard($term, $language, $nativeLanguage, $context, $level): ?array`

Runs only once the proposal is **approved** — see [cards](cards.md) "Staging". `Card::generateContent()`
resolves the native language and level and calls it; `Card::regenerate()` re-runs it alone, since a
card already carries everything call 1 would decide (see [cards](cards.md) "Regenerate"). One
schema for every Term:

| Field | Rule |
|---|---|
| `sentence` | the whole Term bracketed once, in the form it takes there. If the Term is itself a whole sentence, a short two-line exchange with the Term bracketed as the reply |
| `translation` | a natural equivalent of the Term **as typed**, inflection included (a past-tense Term gets a past-tense translation — Translation mode asks for exactly that form), never word-by-word, ≤2 variants separated by `; ` |
| `definition` | what the Term means or, for a whole utterance, when you'd say it ("used to politely refuse something you have been offered") |

**`$nativeLanguage === null` means a monolingual native-language card.** That single flag replaces
the whole former `getContentForCardNative` variant: it drops `translation` from both `properties`
and `required`, and skips `levelInstruction()`/`fieldContrastRule()` (a user isn't learning their
own language, so a difficulty cap doesn't apply). `$context === null` drops the sense-fixing
clauses.

`levelInstruction()`, `definitionLanguage()` and `fieldContrastRule()` are shared. Both calls go
through one private `requestCardJson()` helper, which checks `successful()` and `refusal`, logs,
and returns a **decoded array** or `null`.

Every field describes the card's **Term**, always — see [cards](cards.md) "What a card is built
around". The system prompt says so explicitly: the old `submitted_form` failure — the model writing
every field about an inflected form it was only meant to touch once — is the general risk any
secondary field carries.

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

The card-creation path used to be the exception — its generators checked nothing and returned
a raw string, and the capture controller had its `return` on a null response **commented out**, so a
failed call fell through into `trim(null)` and surfaced as a generic caught error. Both are fixed:
`requestCardJson()` does the checking for every one of these calls, and a `null` from either step of
the capture pipeline is handled explicitly — CALL 1 marks the proposal `failed` (staging then offers
to discard it), CALL 2 leaves the proposal untouched in staging and answers a plain retry message.
