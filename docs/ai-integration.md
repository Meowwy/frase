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
2. **CEFR level caps difficulty, never length.** `AI::levelInstruction()` and the
   `config/proficiency.php` descriptions describe which words/structures are allowed at a level,
   and end with an explicit sentence saying so ("This caps difficulty, not length — never write
   less than a field asks for"). The level descriptions deliberately contain **no length
   wording** — an earlier version of the A1/A2 descriptions said "very short sentences", and the
   model responded with two- and three-word stubs. Where a prompt genuinely wants brevity (chat turns, recap bullets), it says so
   itself, separately from the level instruction.

## Why two calls

Card creation is **two calls**: `AI::analyzeTerm` (CALL 1) fixes the Term and names its base
words at capture, and `AI::generateCard` (CALL 2) writes the card's translation and describes the
words that are new to the learner. The split exists so that **CALL 2 runs only at approval**, not
right after CALL 1. Capture is CALL 1 alone — cheap, and the only thing standing between the
learner and staging — while CALL 2's cost is paid only for proposals the learner actually keeps. A
single-call design couldn't defer content generation like this at all.

**CALL 1 names the words and does not describe them.** A word is only a `{lemma, part_of_speech}`
there: enough for staging to tell, with a database lookup, which the learner already has (see
[cards](cards.md) "Staging"). Its translation, dictionary form and grammar attributes are
CALL 2's job, and CALL 2 is asked only for the words the base lacks — a word already in the base
keeps the details it was saved with, so describing it again would be paid for and thrown away. The
language is settled by then, so CALL 2's schema also carries just that one language's attributes
rather than the union CALL 1 would have needed.

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
  other_languages: [name, ...],              // only in the schema with 2+ candidate languages
  term,
  senses: [{part_of_speech, gloss, translation}, ...],
  fixed_expressions: [{form, translation}, ...],
  base_words: [{lemma, part_of_speech}, ...],
}
```

- **`language` is first**: strict structured outputs emit keys in schema order, so the model
  commits to it before writing anything whose rules depend on it. It is an `enum` over the
  candidate names, so an unknown language can't come back at all.
- **`other_languages`** is empty unless the Term, exactly as written, is also a real, common word
  in another of the learner's languages (*bad* in English and Swedish) and no Context settles it.
  It feeds staging's **language picker** (see [cards](cards.md) "Staging"), which pins the picked
  language and re-runs this call — so the property is only in the schema when there are two or more
  candidate languages, and a pinned re-run can't ask again.
- **`term`** carries prompt rule #1: typos fixed, nothing else changed.
- **`senses`** is empty unless the Term is a single word, no Context was given, and it has two or
  more common senses (*run* the verb or noun, *bank* money or riverside): then at most 4, gloss and
  translation in the native language. They feed staging's sense picker (see [cards](cards.md)
  "Staging"); it sits before `base_words` so the model has settled the ambiguity before extracting.
- **`fixed_expressions`**: at most 3, from the Term only. The prompt allows **exactly two kinds**:
  a unit with a meaning of its own that its words don't add up to (phrasal/particle verbs such as
  *make up*, *take off*, *tycka om*; idioms; fixed units such as *på grund av*), and a grammatical
  frame used in a set shape with gaps (*either … or …*, *inte bara … utan också*). It then names
  what is **not** one, because models over-report: ordinary combinations (*make a decision*, *heavy
  rain*), literal verb + particle pairs (*titta på*), and **reflexive verbs** (*lära sig*). The last
  is a failure, not a guess: an earlier prompt stated the rule as a bare *swap test* ("no word can
  be swapped without breaking it"), and the model flagged *lära sig*, reading *sig* as unswappable —
  yet the pattern is open (*vrida sig*, *tvätta sig*) and the meaning follows the verb. The test is
  now phrased the other way round: if a word can be swapped for another of its kind and the meaning
  simply follows, it is not a fixed expression. A Term that is itself a fixed expression is listed too. `form` is
  canonical (`…` for a gap) so the same expression matches across cards, and shows how the
  expression is used: a slot it takes is a bracketed placeholder in the Term's language
  (*tycka om [någon]*, *make [something] up*), and `translation` marks the same slots in the native
  language (*to like [someone]*) — a bare *tycka om* doesn't say whether it takes an object. It sits before
  `base_words` because of the next rule.
- **`base_words`** are the Term's words, each reduced to its **lemma**, tagged with its
  **part of speech** (an `enum` over `LanguageGuideline::PARTS_OF_SPEECH`), with a native
  translation — the translation is decided here, not
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
consulted by `AI::generateCard`'s prompt builder when it describes the new `base_words` of that language.
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
note plus one per attribute plus the dictionary-form rule, concatenated for CALL 2's system
message), `allAttributes()` (used to build CALL 2's word schema),
`wantsDictionaryForm($partOfSpeech)` / `usesDictionaryForms()` (the per-part-of-speech and
whole-language forms of the same question — the first filters CALL 2's answer in
`WordDetails`, the second decides whether the schema carries the property at all), and
`displayForm()`, which turns `{lemma, part_of_speech, grammar_attributes, dictionary_form}` into
what the learner actually sees (*"ett hus"*, *"komm|a -er"*; a stored dictionary form stands in for
the lemma and the article prefixes still apply around it). `BaseWord::displayForm()` and
`ProposalBaseWord::displayForm()` are thin wrappers over that last one, so staging chips, the
vocabulary base and Frammenti can never disagree about how a word is spelled.

The attribute properties and `dictionary_form` are part of **CALL 2's** `base_words` schema, built
from the one guideline of the card's language (`AI::baseWordProperties()`), and the language's
prose note is quoted in CALL 2's system message only when there are new words to describe. CALL 1
quotes no guideline at all: it names lemmas and parts of speech, which need none of it. An answer
for a part of speech the guideline doesn't define the attribute or dictionary form for (a verb's
`komm|a -er` on a noun) is still discarded (`WordDetails::grammar()`).

**For Swedish, the lexicon comes first and the model only fills the gaps.** Where the lexicon (a
downloaded dictionary — see [cards](cards.md) "The lexicon") knows the word with a single value,
that value is stored and CALL 2 is not even asked for it. The model's answer is used only for a
word the lexicon lacks, or as the tie-break between homographs, where it may pick only among the
values the lexicon allows. A language with no lexicon rows keeps the AI-only path as it was.

### Call 2 — `AI::generateCard($term, $language, $languageCode, $nativeLanguage, $context, $newWords): ?array`

Runs only once the proposal is **approved** — see [cards](cards.md) "Staging". `Card::generateContent()`
resolves the native language and calls it; `Card::regenerate()` re-runs it alone (with no new
words), since a card already carries everything call 1 would decide (see [cards](cards.md)
"Regenerate"). One schema for every Term:

| Field | Rule |
|---|---|
| `translation` | a natural equivalent of the Term **as typed**, inflection included (a past-tense Term gets a past-tense translation — Translation mode asks for exactly that form), never word-by-word, ≤2 variants separated by `; `. Left out for a native-language card |
| `base_words` | only when `$newWords` is non-empty: one entry per new word, in the listed order, `lemma` and `part_of_speech` echoed back (the lemma and part of speech are CALL 1's, settled; the echo is only how the answer is matched to the word), then `translation` (the lemma's equivalent in the sense the Term uses it, ≤2 variants), `dictionary_form` and the language's attributes |

`$newWords` is the proposal's linked candidates the base doesn't hold — `Proposal::approve()` works
it out from the presence index, so a known or already-present word is never described. Each word
carries an `ask` list: the dictionary form and grammar attributes its language defines for its part
of speech, **minus every one the lexicon answers with a single value** (`WordDetails::fieldsToAsk()`).
The schema holds only the fields some word still lacks, a word with an empty `ask` is marked
*translation only* in the user message, and the language's prose note is quoted only when any field
is left — so a Swedish noun or verb the lexicon lists once costs a translation and nothing more,
and a Term whose new words are all such (or are adverbs, prepositions…) carries no grammar
vocabulary in the prompt at all. A homograph (two values), a word the lexicon lacks, and a language
with no lexicon rows are asked as before. `WordDetails::forNewWords()` reads the answer: it matches each word by lemma and part of speech,
lets the lexicon override the grammar, and returns null when the model left a word out or gave it
no translation, which fails the approval (a retry, like any CALL 2 failure) rather than saving an
entry with nothing to show. A deleted base word still counts as new, as it does in staging, so its
description is paid for and ignored when approval revives the row.

**There used to be a second field, `definition`** (what the Term means, written in the native or
target language by CEFR level, with a guard against the model swapping it with the translation).
It was dropped because nothing needed it any more — the Definitions learning mode went with it —
and every token of it was paid on every approval. `cards.definition` stays in the schema, `''` on
new cards, and is shown nowhere; don't bring the field back without a screen that uses it.

**A monolingual native-language card has no translation to write**: it gets no `translation`
field, and its words are glossed in their own language (`nativeLanguageFor()` is `null`). With no
new words either, `Card::generateContent()` returns `[]` without calling the AI.
`$context === null` drops the sense-fixing clauses.

Both calls go through one private `requestCardJson()` helper, which checks `successful()` and `refusal`, logs,
and returns a **decoded array** or `null`.

Every field describes the card's **Term**, always — see [cards](cards.md) "What a card is built
around". The system prompt says so explicitly: the old `submitted_form` failure — the model writing
every field about an inflected form it was only meant to touch once — is the general risk any
secondary field carries.

### CEFR level

`AI::levelInstruction($level)` builds the difficulty-capping instruction fragment described
above, pulled from `config/proficiency.php` (`levels` map, `A1`..`C2`, plus a `default` and a
`names` map for the UI). It returns `''` when no/unknown level is given, so prompts are unchanged
for a language with no proficiency set yet. CALL 2 does not use it: a translation has no level to
cap.

## Related generators

- **`AI::generateTextWithGaps($words, $targetLanguage, $themePreference = null, $level = null)`**
  — one gap-fill story; see "Gap-fill" below.
- **`AI::generateFragments($slots, $targetLanguage, $nativeLanguage, $level, $ownWords)`** — one
  Frammenti batch, and **`AI::evaluateSentence()`** — one
  Frammenti tier III verdict; see "Frammenti" below.
- **`AI::getEmbedding($text)`** / **`AI::cosineSimilarity($a, $b)`** — embedding + similarity
  helpers used for (currently paused) automatic card linking. See [search-and-linking](search-and-linking.md).

### Gap-fill — `AI::generateTextWithGaps()`

Runs on the queue (`GenerateGapFillJob`, see [gap-fill](gap-fill.md)), through `requestCardJson()`
at the default reasoning effort. `$words` are a wordbox's ticked base words, each as
`{base_word_id, lemma, display_form, part_of_speech, grammar, translation}`, sent one per line:
id, lemma, part of speech, grammar attributes (`gender: neuter`) and — when it differs from the
lemma — the display form as "dictionary entry" (*ett hus*, *komm|a -er*), then the translation.

- **The words are lemmas to inflect.** The story uses each in whatever form its sentence needs,
  never the bare lemma where the grammar wants another. A gap-fill of dictionary forms would test
  nothing a flashcard doesn't; producing the right form in context is the exercise. The words
  around each gap must make that form unambiguous.
- **Part of speech and sense are kept**, and the translation is what anchors the sense: a lemma
  alone doesn't say which of its meanings the learner saved.
- **No word appears outside its own gap**, the title included — anywhere else it gives the answer
  away.
- **Length follows the word count, density doesn't.** The prompt states a minimum of 12 words of
  running text per listed word (`GAP_FILL_WORDS_PER_GAP`, computed in PHP so the model gets a
  number, not "longer"), and that ordinary words must clearly outnumber the gaps. Left alone the
  model wrote a short story that was mostly gaps. Gaps are spread through the story and
  interleaved with real text; two may touch (an article and its noun), never three in a row.
- The CEFR rule (rule 2 above) applies through `levelInstruction()`; the job falls back to the
  configured default level (A1) for a language with none set, so a level is always sent.
- **A1/A2 (`GAP_FILL_BASIC_LEVELS`) get a text the learner can read:** apart from the listed
  words, only really basic everyday words in short sentences, and up to 30 random other words of
  the learner's base in that language (`lemma (translation)`, not the gap words) that the model
  prefers wherever they fit, written as plain text. From B1 up there is no such list.
- It does **not** quote `LanguageGuideline::promptNote()`: those notes tell CALL 2 how to tag and
  write a base word (dictionary style with its `|`, "tag it as common or neuter") and would bleed
  into the story. The display form and attributes already carry what the model needs.

Strict schema: `title`, `text` (with `[1]`, `[2]`, … in order of appearance) and `answers[]` of
`{index, base_word_id, phrase, accepted[]}`, all required — `phrase` the form the story used,
`accepted` every form that fills the gap. PHP reshapes `answers` into
`[index => {phrase, accepted, base_word_id}]`, always counting `phrase` among `accepted`, and
returns `null` when the placeholders in `text` aren't exactly the answers' indexes or an answer
names a word that wasn't sent — such a story can't be checked.

### Frammenti — `AI::generateFragments()` and `AI::evaluateSentence()`

One strict-schema call (`requestCardJson`, default reasoning effort) per batch of 5 slots chosen
by `Frammenti::deal()`. Shapes, validation and the client side are in [frammenti](frammenti.md).
One call per fragment, sent in parallel, was tried and dropped: it sent the shared context (level,
word lists) five times for a shorter wait.

- **The exercise type is carried by the schema, never explained in prose.** Each slot is its own
  required property (`slot_1`…) with its type's shape: a gapped phrase plus `accepted` for I, a
  phrase with the item marked for II, a `task` plus `answer` for III, each field's rule in its own
  `description`. The model is never told what a fragment, a tier or the learner's exercise is, so
  it can't mix the types up, drop a slot or reorder them. An earlier version described every type
  in the system prompt and returned one generic array, which needed the model to read the type off
  each line.
- **One phrase or one sentence, natural.** A fragment exists to show the item in a fresh, real
  context. A paragraph would bury it, and a stilted textbook sentence teaches the wrong usage.
- **Difficulty is capped by the CEFR level, never length** (`levelInstruction()`, rule 2 above),
  at every tier including III's task and answer. A beginner still gets a whole sentence.
- **The item is used in a sense that fits its part of speech and translation.** Base words carry
  no sense finer than part of speech, so this is the only thing keeping *run* the noun from being
  tested as a verb.
- **The translation follows the phrase closely** (I and II, where it is the learner's cue): same
  tense, number, person and definiteness, the item rendered by its given translation, nothing
  added, dropped or paraphrased. A "natural" translation drifted from the phrase, so the learner
  couldn't tell which form or word the gap or the sentence asked for. Stated once in the system
  prompt; the field description says only "close" (it is sent once per slot).
- **No slot uses another slot's item.** A fragment with two of the batch's items in it would give
  one away or test two at once.
- **A beginner's context words are their proven words.** Below B1, and for a language with no
  level set (treated as a beginner), the call gets one list, "the learner's own words": up to 80
  random base-word lemmas and expressions at Frammenti level 2 or above, so produced correctly at
  least twice. "Proven" because a word the learner has never produced is a second unknown in the
  fragment. The one list serves every slot type:
  - **I and II**: besides the item, the only content words are the learner's own words or words too
    basic to collect, so a cloze has exactly one unknown, the tested item, and a sentence to
    translate only asks for words already produced, which keeps self-grading about the item.
  - **III**: the task asks only for what can be said with the item, the learner's own words and
    basic words, so the learner is never asked to say what they can't.

  A brand-new learner's list reads "none yet", and the fragment is built from basic words; with few
  words that makes for short phrases, which is fine. **From B1 there is no list** (`null`): the
  level instruction alone caps the vocabulary, which makes the contexts richer. These are
  schema-description rules, not checked in PHP: inflection makes a word-by-word check unreliable.
- **At I, a base word is a single gap, article included**, and only a fixed expression whose
  parts stand apart may get one gap per part — said in the `fragment` description, which differs
  for base words and expressions, and checked in PHP. Without it the model split *en skola* into
  two gaps.
- **At I, the surrounding words must make the gap's form recoverable**, and `accepted` lists
  every form that fits. The learner is typing an inflected form, so the sentence has to say which
  one, and any other correct form must not be marked wrong.
- **An item's linked synonyms are named on its line** ("linked synonyms: "lycklig" = "happy""),
  and at I its schema alone gains `synonym_answers`: for each gap, every form of those synonyms
  that would also fill it in this phrase. A synonym can fit a gap as well as the item, and the
  model is the one that can put it in the phrase's form, so the page can tell "another way" from a
  miss. The phrase is **not** made to rule the synonym out. A slot without synonyms isn't asked, so
  its schema stays as it was.
- **An item's saved Terms lock its meaning, not its context.** A slot's line ends with
  `saved Terms: "…", "…"` (up to 2 card Terms holding the item, see [frammenti](frammenti.md)
  "Dealing"), and the system prompt, only when some slot has any, says to use the item in the sense
  it has there but in a different context, never reusing or paraphrasing a Term. The learner's
  own-words rule still applies in full. Part of speech and translation alone don't pin a sense, and
  the learner's own Terms are the only evidence of the sense they meant.
- **The native translation (I, II) and the task (III) are plain text**, and PHP strips any `[[ ]]`
  the model still adds: it did mark translations, and the learner should see just the text.
- **At III, the task is an instruction to say something to someone** ("Tell your friend she should
  be reading more books"), in the native language, whose natural rendering uses the item. A
  situation rather than a sentence to translate is what makes the learner build the sentence. The
  `answer` is one natural sentence carrying it out, with the item marked, shown as "one way to say
  it".

**`AI::evaluateSentence()`** grades one tier III sentence: strict schema, default reasoning effort
(a fair verdict matters more than a second saved). Input: the item with its part of speech,
translation and linked synonyms, the task, the sentence, both languages and the level. Like the generator, the prompt
describes the data, never the exercise.

- **`correct` is about the item only**: true when it's used in a correct form, in a sense that fits
  its translation. Mistakes elsewhere don't make it false. Frammenti schedules that one item, so a
  wrong article on another word must not fail it.
- **`used_synonym` says a linked synonym stood in for the item.** The input lists the item's
  synonyms when it has any, so a sentence built on *lycklig* for *glad* is told apart from a miss;
  the page then asks for another way instead of grading.
- **`corrected` fixes every mistake and changes nothing else**, identical when there are none, so
  the learner sees their own sentence repaired rather than a rewrite.
- **`note` is one short native-language explanation of the main mistake**, about the item or
  anything else, empty when there's nothing to say. One, because a list of every slip buries the
  one that matters.

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
