# Cards

The `Card` model, its schema, the capture (creation) flow, the vocabulary list page, and manual
card linking. For how the AI fills in a card's content, see [ai-integration](ai-integration.md); for how a card
is later reviewed, see [learning-flow](learning-flow.md).

## Schema (`cards` table)

Base columns from the original migration plus everything added since:

| Column | Notes |
|---|---|
| `user_id`, `language_id` | owner + the single language this card belongs to |
| `theme_id` | nullable, `onDelete('set null')` — see [wordboxes-themes-tags](wordboxes-themes-tags.md) |
| `term` | the Term itself, exactly as the learner gave it with only typos fixed — renamed from `phrase` (see [ai-integration](ai-integration.md)) |
| `translation` | native-language equivalent; `''` for a native-language card (column is `NOT NULL`) |
| `example_sentence` | one sentence with the whole **Term** wrapped in `[brackets]` once (a two-line exchange when the Term is itself a sentence) — powers the Sentences learning modes |
| `definition` | what the Term means or, for a whole utterance, when you'd say it |
| `note` | nullable free-text, user-editable, not AI-generated |
| `context` | nullable — the learner's own context input, kept so a card can be regenerated in the sense it was captured in |
| `level` | integer SRS box/level — see [learning-flow](learning-flow.md) "SRS algorithm" |
| `last_studied`, `next_study_at` | SRS scheduling dates |
| `embedding` | nullable JSON array (`array` cast) — see [search-and-linking](search-and-linking.md) |

Dropped by the vocabulary-base redesign (migration
`2026_09_27_000001_redesign_cards_for_vocabulary_base`, which also renamed `phrase` → `term`):
`word` (there is no focus word — see "What a card is built around" below), `example_1`,
`example_2`, `example_3` and `term_type`. `question` had already gone earlier
(`2026_08_04_000001_add_examples_and_note_drop_question_from_cards`).

Dropped by `2026_10_06_000001_drop_card_shape_and_anchor`: `card_shape`, `anchor` and
`anchor_translation`. **There is one kind of card.** Every Term used to be classified as a word,
phrase or expression, which drove three generators, per-shape base-word limits, an anchor phrase
for word cards only and learning modes that silently skipped expressions — distinctions that
didn't help anyone learn. Existing data was expendable, so nothing was backfilled.

## Model (`app/Models/Card.php`)

### What a card is built around

Every card has exactly one thing it is about: its **Term**, always — kept exactly as the learner
typed it, with only typos fixed. A lone inflected word stays inflected (`kostade`) and a pasted
sentence stays a sentence; only the card's base words carry lemmas. There is no focus word and no
word/phrase split inside a Term — `translation`, `definition` and the `example_sentence` brackets
are always about the whole Term, in whatever form it takes. `Card::target()` is the Term.

### Helpers on the model

- **`Card::baseWords()`** — `belongsToMany(BaseWord::class, 'card_base_word')->withPivot('surface_form')`,
  the card's linked vocabulary-base entries. See "The vocabulary base" below.

Relations: `user()`, `theme()`, `language()`, `wordbox()` (belongs-to-many via `wordbox_card`),
`tag()` (has-many — see note in [wordboxes-themes-tags](wordboxes-themes-tags.md) about `Tag`'s actual relation shape),
`synonyms()` / `relatedTerms()` (see [search-and-linking](search-and-linking.md)), and `linkedCards()` — the
user-manual link relation, see below. `scopeForLanguage($query, $languageId)` is the standard
per-language scope used throughout the app.

Retired: `Card::phraseHtml()` (nothing left to bold, once there is no focus word) and
`Card::suggestedPhrases()` (the `example_*` fragments it read are gone).

## The vocabulary base

`base_words` — one entry per lemma **and part of speech** per language per learner:

| Column | Notes |
|---|---|
| `user_id`, `language_id` | owner + language |
| `lemma` | canonical spelling |
| `part_of_speech` | enum (`noun`, `verb`, `adjective`, `adverb`, `pronoun`, `preposition`, `conjunction`, `determiner`, `numeral`, `interjection`), not nullable — part of the dedup key, not a revisable property. *run* the verb and *run* the noun are two rows |
| `dictionary_form` | nullable string — the lemma as that language's dictionaries write it, for the parts of speech whose guideline asks for one (Swedish `verb` → `komm|a -er`); null everywhere else, which is most words. Stored rather than computed from the lemma, because where a verb's stem ends and which present-tense ending it takes are facts about that one verb — unlike a noun's *en*/*ett*, which follows from `grammar_attributes`. Taken from the lexicon where it knows the verb (see "The lexicon" below). **Not** part of the dedup key: `komma` is one entry |
| `grammar_attributes` | nullable JSON (`array` cast) — the extra grammatical facts this language's guideline defines for this part of speech (e.g. Swedish noun `{"gender": "neuter"}`); null for a part-of-speech/language pair with nothing to say. See [ai-integration](ai-integration.md) "Language guidelines". **Not** named plain `attributes`: that collides with Eloquent's own internal attribute bag, which would make the column unreadable as `$this->attributes` from inside the model |
| `translation` | set once at creation from CALL 1, never revised — sense lives on cards, not here |
| `last_recalled_at` | nullable, stamped only on a correct answer |

Unique on `(user_id, language_id, lemma, part_of_speech)` — this is the real dedup key, not
`lemma` alone. `grammar_attributes`, `translation` and `dictionary_form` are set once, at creation,
and never revised: **`BaseWord::resolve()`** is a `firstOrCreate` whose second argument holds
exactly those three, so "never revised" is a property of the write rather than a rule someone has
to remember. 

**`BaseWord::displayForm()`** renders the lemma the way the learner is expected to learn it
(*"ett hus"*, *"komm|a -er"*), reading the language's guideline. A correct whole-Term answer
stamps `last_recalled_at` through **`Card::stampRecall()`** (its base words and fixed expressions
together); a correct word-level answer stamps that one word.

`card_base_word` — the pivot linking a card to the base entries for its Term's words:

| Column | Notes |
|---|---|
| `card_id`, `base_word_id` | |
| `surface_form` | the spelling this card's Term actually uses (`kostade` in the Term, `kosta` in the base). Nothing reads it yet — it is carried for the deferred hide-a-word mode (out of scope, see `USERFLOW.md`) |

Unique on `(card_id, base_word_id)` — one link per base entry per card, even when the Term repeats
a word (`AnalyzeProposalJob` also drops a repeated lemma+part-of-speech from the chip tray, so the
constraint is never reached in practice). There is **no cardinality rule**: a card may link no
base words at all, or many. An earlier minimum and maximum per card shape could leave a proposal
permanently stuck in staging, with nothing the learner could do but discard it.

Each candidate falls into exactly one **group** (`Proposal::groupOf()`), checked in this order:

1. **Already present** (`Proposal::presenceIndex()`): matches `base_words` on
   `(user_id, language_id, lemma, part_of_speech)` — a matching lemma with a *different* part of
   speech is a different vocabulary item. Shown aside with no strike control, expanding to the cards
   that word is already used in, and linked to the new card on approval (`BaseWord::resolve()`
   reuses the row). Linking it is what makes coverage work, and hiding it would lose the fact the
   learner wants to see.
2. **Known** (`Proposal::knownIndex()`): matches `known_words` on the same key, so striking *hus*
   once covers *huset* and *husen*. Shown aside, labelled *known*; tapping it un-knows it. Never
   linked or created.
3. **New**: strikeable; created and linked on approval.

Already present wins if both ever apply (only a new chip can be struck, so in practice they don't).
The groups are **never stored**, always computed at staging render time and again at approval, which
is why a word the learner acquires or strikes between capture and approval is still recognised, and
why every candidate stays on the proposal — un-knowing a word brings its chip straight back.

Each store is resolved for a whole list in **one** query, keyed by `Proposal::presenceKeyFor()`,
rather than one query per chip. That is not premature: `/staging/list` re-renders the entire tray every 2 seconds
for as long as anything is still `pending`, and with no queue worker running (see
[overview](overview.md) "Known rough edges") that poll never stops — a per-chip query there is a
query storm that never ends.

**Proficiency filter** — the one filter applied on the way in:
 — `AnalyzeProposalJob::applyProficiencyFilter()`, applied as the chip tray is
  written: from B1 up, the parts of speech in `LanguageGuideline::FUNCTION_WORD_PARTS`
  (preposition, pronoun, conjunction, determiner) aren't worth an entry. The level is the learner's
  CEFR level *for the detected language*, so this cannot run before CALL 1 has returned — which is
  why it is a PHP filter after the call rather than an instruction inside it.
  It **always applies**, even when it leaves the tray empty (`in spite of` at C1): with no
  cardinality rule, an empty tray is still approvable.

## The expression base

`fixed_expressions` — the learner's **fixed expressions**, per language: multi-word units that pass
the **swap test** (no word can be swapped without breaking the unit or changing its meaning) —
frames with a gap (*inte bara … utan också*), fixed units (*på grund av*) and non-literal particle
verbs (*tycka om*). Ordinary combinations (*make a decision*) are not fixed expressions. They live
beside the vocabulary base rather than inside it because they are learnt as wholes: CALL 1 leaves out
any word that occurs in the Term only inside one, so *tycka om* is never split into *tycka* + *om*.

| Column | Notes |
|---|---|
| `user_id`, `language_id` | owner + language |
| `form` | canonical form (base form of each word, `…` for a gap). `NOCASE` collation, so the unique key `(user_id, language_id, form)` and every lookup are case-insensitive |
| `translation` | set once at creation from CALL 1, never revised — mirrors base words |
| `last_recalled_at` | nullable, stamped by `Card::stampRecall()` when a card linking it is cleared |

`card_fixed_expression` — the pivot, carrying the `surface_form` the Term spells it in (*tycker om*);
unique on `(card_id, fixed_expression_id)`.

There is no practice surface for fixed expressions: they don't enter Words mode, Refresher or
Gap-fill. They are shown on the card detail page and on the **Expressions** tab of `/base`
(`/base?tab=expressions`: form, translation, last recall, and the cards using each one).

## The lexicon

`lexicon_entries` — a downloaded reference dictionary, global (no `user_id`): `language_code`,
`lemma`, `part_of_speech`, `gender`, `dictionary_form`, `paradigm` (the source's own inflection
code, kept for debugging). Indexed but **not unique** on `(language_code, lemma, part_of_speech)`,
because homographs are separate rows.

**Why it exists:** a Swedish noun's *en*/*ett* and a verb's *komm|a -er* are fixed facts about the
word, not something to tailor — so they should come out the same every time, not be the model's
guess. `AnalyzeProposalJob` looks every candidate up (**`App\Support\Lexicon::lookup()`**, one
query per proposal) after CALL 1 returns, and where the lexicon knows the word its value wins:

- **Homographs** (*ett plan* the plane, *en plan* the plan): the lexicon can't tell senses apart,
  CALL 1 can — so CALL 1's answer is kept **only if it is one of the lexicon's values**, else the
  first one is used. The model picks the sense; it can never invent a value.
- **Compounds** the lexicon doesn't list fall back to their longest known last element (*sommarhus*
  → *hus* → *ett*): a Swedish compound always takes its last element's gender.
- **A word it doesn't know at all**, a noun SALDO allows either article for, and every language
  with no rows keep CALL 1's answer exactly as before.

**Source — Swedish only, from SALDO's morphology** (Språkbanken, University of Gothenburg,
[CC BY 4.0](https://sprakbanken.se/en/resources/saldom)). Download `saldom.xml` (~250 MB, never
committed) from `https://svn.spraakbanken.gu.se/sb-arkiv/pub/lmf/saldom/saldom.xml` and run
`php artisan lexicon:import-saldo <path>` (~20 s; re-running replaces the Swedish rows). It keeps
~83k nouns and ~8k verbs; multiword entries (*komma ihåg*) are skipped for now.

- **Gender** is the last letter of SALDO's inflection class: `nn_6n_hus` → `neuter`,
  `nn_2u_bil` → `common`; `v` (either article) and `p` (plural only) stay null.
- **Dictionary form** is built by `Lexicon::swedishDictionaryForm()` from the infinitive and SALDO's
  first active present form — the same rule `sv.php` states for CALL 1: `-ar`/`-er` after a bar
  (*tal|a -ar*, *komm|a -er*), `-r` after the whole infinitive (*bo -r*, and *ha -r*, whose would-be
  stem *h* has no vowel), and an irregular present written out (*var|a är*). s-verbs (*hoppas*)
  have no active present and stay null.

Folkets lexikon (what the `swe` CLI uses) was considered and rejected as the source: it has no
gender field, and about 45% of its nouns carry no inflections to infer one from.

## Staging

Capture no longer writes a card directly. It writes a `proposals` row and returns immediately;
CALL 2 and the card write happen only once the learner **approves** it.

`proposals`:

| Column | Notes |
|---|---|
| `user_id` | |
| `language_id` | nullable until CALL 1 resolves it — detection is CALL 1's job |
| `raw_input` | the term as typed/pasted, known immediately |
| `context` | nullable, the learner's own input — editable in staging (see below) |
| `term` | nullable until CALL 1 resolves |
| `senses` | nullable JSON (`array` cast) — the sense picker's options, `[{part_of_speech, gloss, translation}, …]`, up to 4. Only ever set for a single-word Term captured without a Context with two or more common senses; `AnalyzeProposalJob::senses()` enforces that in PHP too, since a stray answer would block approval |
| `merge_card_ids` | nullable JSON (`array` cast) — the existing cards marked to be merged away on approval (see "Related cards and Merge" below) |
| `source` | `'web'` \| `'extension'` |
| `status` | `pending` \| `processing` \| `completed` \| `failed` — the same async shape as `gap_fill_exercises` + `GenerateGapFillJob` (see [gap-fill](gap-fill.md)), so the fast-path skeleton polls/resolves the same way |

`proposal_base_words` — the candidate tray, one row per extracted lemma. It holds no strike state:
each row's group is derived live (above).

| Column | Notes |
|---|---|
| `proposal_id` | |
| `lemma`, `part_of_speech`, `dictionary_form`, `grammar_attributes`, `surface_form`, `translation` | carried straight onto `base_words`/`card_base_word` at approval |

`known_words` — what the learner has struck, per learner and language: `user_id`, `language_id`,
`lemma`, `part_of_speech`, unique on all four. Striking a new chip inserts a row; un-knowing deletes
it.

`proposal_fixed_expressions` — the fixed-expression chips, at most 3 per proposal: `form`,
`surface_form`, `translation`, `struck`. Striking one is **per proposal** — unlike a word, a fixed
expression is never remembered as known. Each chip is either **already present** (matches
`fixed_expressions` on form, case-insensitively, via `Proposal::expressionPresenceIndex()` — one
query per list: shown aside, not strikeable, linked) or **new** (strikeable; created unless struck).

**`Proposal::approve()`** resolves the `base_words` rows for every candidate that isn't known
(reusing an existing one per the check above) and first-or-creates the `fixed_expressions` row for
every fixed expression that isn't struck (an already-present one is linked even if it was struck
before it entered the base), writes the card and its `card_base_word` / `card_fixed_expression`
links, merges away the marked cards, deletes the proposal, and runs CALL 2 **before** opening the transaction — a failed call then leaves the
proposal exactly as it was in staging rather than a half-written card.

### Endpoints (`ProposalController`, `ProposalPolicy` for ownership)

| Route | What it does |
|---|---|
| `POST /capture` (name `capture`) | validates `capturedWord`/`context`, writes the `proposals` row, dispatches `AnalyzeProposalJob`, answers JSON with the new staged count. Shared with the extension — see [browser-extension](browser-extension.md) |
| `GET /staging` | the feed: every proposal, newest first, across every language |
| `GET /staging/list` | the same list as `{rows, count, pending}` JSON, rendered from `staging/_proposals.blade.php` |
| `POST /staging/{proposal}/words/{word}/known` | `known=1` strikes a new chip (inserts its `known_words` row), `known=0` un-knows it |
| `POST /staging/{proposal}/expressions/{expression}/strike` | `struck=1` / `struck=0` on one fixed-expression chip |
| `POST /staging/{proposal}/language` | correct the detected language — see below |
| `POST /staging/{proposal}/context` | add, edit or clear the Context, or pick a sense — see below |
| `POST /staging/{proposal}/merge/{card}` | `merge=1` / `merge=0` marks or unmarks one card for merge. `403` unless the card is the learner's own, `422` unless it is in the proposal's language |
| `POST /staging/{proposal}/approve` | `409` with the existing card's id on an identical Term that still blocks (see "The duplicate check"), `422` when the proposal isn't approvable yet, else the new card's URL |
| `DELETE /staging/{proposal}` | discard |

Two deliberate shapes in the UI (`staging/index.blade.php`):

- **Every action re-renders the whole list from `/staging/list`.** The rules that decide which
  controls are live then exist only in PHP (`Proposal::isApprovable()`) and cannot drift out of
  step with the markup. The cost is a re-render on each click, which is invisible next to the
  AI calls this page is otherwise waiting on.
- **The undo window is the toast's, client-side.** Discard hides the row and only fires the `DELETE`
  once the toast expires, so "undo" is cancelling a timer. There is no discard history and no
  tombstone row to clean up — which is exactly what the spec asks for, and why undo is *not* a
  server-side restore. The consequence is that the proposal is still in every re-render during that
  window, so the page tracks the ids being discarded and re-hides them after each refresh;
  otherwise the 2-second poll would resurrect a row the learner has already dismissed. Undo
  re-renders rather than un-hiding the row it captured, which a poll may since have replaced.
  Navigating away inside the window leaves the proposal in staging — there is nowhere durable to
  record the intent, and keeping it is the harmless direction to fail in.

**Correcting the language or editing the Context re-runs CALL 1** (`Proposal::reanalyze()`) rather
than patching the row: the candidate words were extracted, translated and tagged for the old
language and sense, so they cannot be carried over. It deletes the word and fixed-expression chips,
clears `senses`, writes the
new value, flips the status back to `pending` and re-dispatches. `AnalyzeProposalJob` reads a
pre-set `language_id` as "pinned" and offers the model only that one language, so the re-run can't
drift back to its original guess.

**The sense picker.** When `senses` is non-empty, staging shows them as radio options and
`isApprovable()` is false, so an ambiguous lone word (*run*, *bank*) can't be saved in whatever sense
the AI guessed. Picking one writes `"<term> (<part of speech>): <gloss>"` as the Context through the
same Context endpoint. The re-run has a Context, so it returns no senses and extracts everything in
the chosen sense, lexicon attributes included — there is no separate code path applying a sense to
the chips, and the sense is kept as the card's Context so Regenerate stays in it.

### Related cards and Merge

**Related cards** (`Proposal::relatedCards()`) are the learner's cards linked to any of the
proposal's already-present base words, most shared words first, at most 5
(`Proposal::MAX_RELATED_CARDS`) so a common word can't flood the panel. A card whose Term is a lone
word equal (case-insensitively) to one of those words' lemma or surface form is flagged *made
redundant by this card* and listed first — it's the card the learner most likely wants to replace.
Cards with the identical Term are left out; the duplicate notice shows those. Computed live, and
without a query of its own: the presence index's base words already carry their cards, and the
2-second poll is why that matters.

**Merge.** Any related or identical card can be marked; the mark lives in `merge_card_ids` until
approval and does nothing before it, so **discard removes nothing**. On approve, inside the
transaction that writes the new card, each marked card (re-scoped to the learner and language)
hands its wordbox memberships to the new card (`syncWithoutDetaching`, so no duplicates) and is
deleted; the FK cascades take its wordbox, base-word, fixed-expression and manual links (both
mirrored `synonyms` rows) with it. Its SRS progress and note are **not** carried over — the learner
should actually review the longer Term they just saved, so the new card starts fresh.

## Capture flow (creating a card)

There are still **two** ways a card gets created — one AI-assisted, one manual:

- **AI-assisted (the primary path)**: `POST /capture` writes a `proposals` row (see "Staging"
  above) instead of a card and returns immediately. **`AnalyzeProposalJob`** resolves CALL 1
  against it on the queue — language detection, the Term with its typos fixed, and the candidate
  base words with their translations. The learner reviews and edits the result in staging; the card itself, its base-word links and (via
  CALL 2) its content are written only on **approve**. The browser extension writes into the same
  `proposals` table — see [browser-extension](browser-extension.md).
  **`<x-capture-form>`** is the whole capture UI, self-contained (it posts over AJAX, toasts, and
  bumps the nav's `.js-staged-count` badge) and used on the dashboard, `/staging` and a wordbox
  page. Because CALL 1 is queued, capture needs a `queue:work` to make progress — see
  [overview](overview.md) "Known rough edges", which already applies to gap-fill.
- **Manual, no AI** (`GET /add` → `cards/add.blade.php` → `POST /cards/new` →
  `CardController::save()`, via `StoreCardRequest`): the user types every field themselves
  (`term`, `definition`, optional `translation`/`example_sentence`/`note`/`theme_id`). This path
  never goes through staging — there's nothing to approve that the learner didn't already type.
  Still dispatches `GenerateEmbeddingJob` so the card participates in linking/search the same way.
  This entry point has no nav link (reach it directly at `/add`) but is a real, working path.

`Card::generateContent()` is CALL 2 without a write (`AI::generateCard`, plus the native-language
and CEFR resolution), and `Card::persist()` maps its answer onto the columns and dispatches
`GenerateEmbeddingJob` (see [search-and-linking](search-and-linking.md)), leaving `theme_id` null
(the AI no longer assigns a theme at capture time — see
[wordboxes-themes-tags](wordboxes-themes-tags.md)). Both are public because `Proposal::approve()`
is the caller; nothing else in the app writes a generated card.

## The duplicate check

`Card::scopeMatchingTerm($term)` is the single definition of "the learner already has this": a
case-insensitive match, within one language, against `term` alone — the `word` leg is gone with
the column. Owning a base word of a Term is a different fact from owning a card for that Term, and
never blocks capturing the card; staging's already-present notice (above) is what surfaces the
base-word fact.

`Proposal::duplicateCards()` reads it. An identical Term doesn't always mean a duplicate — *run*
the verb and *run* the noun are two cards — so the proposal is approvable despite a match **only
if** it has a non-empty Context **or** every matching card is marked for merge
(`Proposal::blockingDuplicates()` is what's left otherwise, and `isApprovable()` requires it
empty). While a match still blocks, staging names the card, disables Approve and offers
**Regenerate that card**, Discard or Merge. The check runs at render time and again inside
`ProposalController::approve()`, which answers `409` — the render is a view of the world a moment
ago, and only the second one is authoritative.

## Regenerate

`Card::regenerate()` replaces an existing card's generated content while its review progress stays
put: it `update()`s only the AI-written columns — `level`, `last_studied`, `next_study_at`, the
`note`, the wordbox pivot, the base-word links and every manual link are never touched. It carries
the caller's `context` if one is given, and otherwise falls back to the card's stored `context`, so
a regeneration keeps the sense the card was originally captured in. A `null` from the call returns
`false` and leaves the card exactly as it was; the embedding is re-dispatched, since the content it
described has changed (see [search-and-linking](search-and-linking.md)).

**It runs CALL 2 only.** The Term is already settled on the card, so CALL 1 has nothing left to
decide — re-running it would pay for language detection and
a whole word extraction whose answer is thrown away. This is also why the capture path no longer
stashes call 1's answer in the session for a later regeneration to reuse: there is no second call
to save.

`Card::contentColumns()` — the AI response → column mapping — covers **only** what the AI writes:
no `level`, no `next_study_at`, no `note`. `persist()` adds those for a new card; `regenerate()`
hands the array straight to `update()`, which is what makes "keep the progress" a property of the
mapping rather than a list of fields someone has to remember to exclude.

A repeat capture reaches it through staging: the proposal shows the identical card that is in
the way and offers **Regenerate that card**, which posts to `POST /cards/{card}/regenerate` and then discards
the proposal. See "The duplicate check" above.

## Card detail page (`cards/show.blade.php`, `CardController@show`)

`show()`, `edit()`, and `update()` all check ownership via `$this->authorize(..., $card)` against
`CardPolicy` (see [overview](overview.md) "Authorization") before doing anything else — a card
belongs to exactly one user and no one else can view, edit, or update it.

`show()` also escapes `example_sentence` (`e($card->example_sentence)`) **before** splicing in the
`[term]` → `<span>` highlight markup, since the view renders the result with `{!! !!}` — the
sentence itself is AI-generated (ultimately from user input, see [ai-integration](ai-integration.md)) and must
never be trusted as pre-sanitized HTML. `SeachController::index`/`searchWordbox` do the same for
their own copies of this highlight logic.

The top row holds the **back** link on the left and **previous card** / **next card** arrows on
the right, same style as back. `show()` resolves the two neighbours as the user's adjacent cards
**in this card's language**, ordered by descending id — ids are monotonic with insertion, so that
is the order `/cards` lists them in, and "previous" is the row above there while "next" is the row
below. The nav deliberately ignores the list's wordbox/search filters; it walks the language,
not whatever view the learner arrived from. At either end the missing arrow is still rendered, just
dimmed and non-clickable, so the row doesn't shift as the learner walks the list.

Renders: the card's **base words** as chips, each in its display form with its
part of speech, linking to `/base`; the bracketed `example_sentence` as plain text (bracket markers
highlighted, no bullets); the `note` if present; and the "Linked cards" section (below). The term
heading is `font-medium`, not `font-bold` — there is no focus word left to emphasize against it, so
the Term itself is what's shown. Because a whole-sentence Term can be much longer than a single
word, the heading wraps (`flex-wrap` + `break-words`), and every place that prints
`example_sentence` keeps `whitespace-pre-line` so a line break in the AI's answer survives
(`cards/show.blade.php` and the search-result `<x-card>` component). `cards/edit.blade.php` renders
`example_sentence` as a `<x-forms.textarea>` rather than a single-line input for the same reason.

## Manual card linking ("Linked cards")

Users manually link two of their **same-language** cards from the card detail page. Links are
stored in the `synonyms` table (repurposed from an earlier automatic-similarity feature — see
[search-and-linking](search-and-linking.md)) as **two mirrored rows** per link, so the relation reads symmetrically in
either direction. `similarity_score` is nullable on that table specifically because a manual link
carries no score.

`Card::linkedCards()` is a `belongsToMany(Card::class, 'synonyms', 'card_id',
'synonym_card_id')->withTimestamps()`.

Endpoints (`CardController`), all enforcing `$card->user_id === Auth::id()` inline (predate
`CardPolicy` — see [overview](overview.md) "Authorization"; functionally equivalent, just written
before the policy existed):

- `GET /cards/{card:id}/link-search?q=` — same-language candidates, excludes self + already-linked.
- `POST /cards/{card:id}/links` (`{card_id}`) — `syncWithoutDetaching` on both sides.
- `DELETE /cards/{card:id}/links/{other:id}` — `detach` on both sides.
- `POST /cards/{card:id}/note` — saves the free-text `note`.

## Vocabulary list (`/cards`, `CardController@index`)

A single endpoint serves **both** the full page and its live updates: a normal request renders
`cards/index.blade.php` (the shared `<x-wordbox-picker>` — see [multi-language](multi-language.md) — plus a table
whose **Term**/**Definition** column headers are themselves live search `<input>`s, plus plain
**Translation** and **Wordbox** headers); an AJAX request (`$request->ajax()`) returns JSON `{rows, pagination}`
rendered from the `cards/_rows.blade.php` partial, which the page swaps into `#cardsTableBody` /
`#cardsPagination` — the header inputs stay in the DOM the whole time, so focus is preserved
while typing.

Filters, all combinable and all preserved across pagination via `->appends($request->query())`:

- `language_id` — defaults to `currentSaveLanguage()`. Falls back to that default if an invalid
  id is passed.
- `wordbox` — `all` (default) \| `general` (no wordbox) \| a wordbox id.
- `term` — substring match (`LIKE %…%`) against `term`.
- `definition` — substring match against `definition`.
- Search inputs are debounced ~250ms client-side before firing the AJAX request.

`cards/_rows.blade.php` has a fixed 6 columns (checkbox, Term, Translation, Definition, Wordbox,
row menu), and its `@empty` row's hardcoded `colspan="6"` has to be kept in step with them.

Legacy entry: the old dashboard theme card still links here as `GET /cards?theme=<name>`, which
resolves that theme and pre-filters + scopes the picker to its language.

### Bulk row actions

- `POST /cards/bulk-destroy` (`{ids: []}`) — deletes the given cards (ownership enforced by
  scoping through `Auth::user()->cards()`; unowned ids are silently ignored), detaching their
  wordbox pivot rows first so none are left orphaned. Also used for the single-row 3-dot-menu
  delete, with a one-id array.
- `POST /cards/assign-wordbox` (`{ids: [], wordbox_id}`) — (re)assigns the given cards to a
  wordbox. A card is treated as belonging to **at most one** wordbox at a time in this action, so
  it `sync()`s the pivot to just the target rather than attaching — moving a card out of whatever
  wordbox it was already in. Defensively filtered to cards whose `language_id` matches the target
  wordbox's language, since the list itself is single-language but the request is trusted input.

Both routes are registered **before** `POST /cards/{card:id}` in `web.php` so their literal paths
aren't swallowed by the wildcard — see [overview](overview.md) "Routing".
