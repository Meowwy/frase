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
| `card_shape` | `'word'` \| `'phrase'` \| `'expression'`, **not nullable** — replaces `term_type`, set once at creation from CALL 1's `card_kind`. See "Card shape and term type" below |
| `term` | the Term itself, in the card's own spelling — renamed from `phrase` (see [ai-integration](ai-integration.md)) |
| `translation` | native-language equivalent; `''` for a native-language card (column is `NOT NULL`) |
| `example_sentence` | one sentence with the whole **Term** wrapped in `[brackets]` once — powers the Sentences learning modes |
| `anchor`, `anchor_translation` | nullable, **word-shape only** — the anchor phrase, the Term's occurrence marked `[bracketed]` inside it (same convention as `example_sentence`), plus its own translation. See "The anchor phrase" below |
| `definition` | dictionary definition or (for an expression) a usage note |
| `note` | nullable free-text, user-editable, not AI-generated |
| `context` | nullable — the learner's own context input, kept so a card can be regenerated in the sense it was captured in; may seed the anchor phrase, never rewritten by it |
| `level` | integer SRS box/level — see [learning-flow](learning-flow.md) "SRS algorithm" |
| `last_studied`, `next_study_at` | SRS scheduling dates |
| `embedding` | nullable JSON array (`array` cast) — see [search-and-linking](search-and-linking.md) |

Retired by the vocabulary-base redesign: `word` (there is no focus word — see "What a card is
built around" below) and `example_1`, `example_2`, `example_3` (the "learn it in a phrase instead"
suggestions they powered are gone; the anchor phrase replaces that role for word cards). `question`
was already dropped before this redesign (migration
`2026_08_04_000001_add_examples_and_note_drop_question_from_cards`).

## Model (`app/Models/Card.php`)

### Card shape and term type

`card_shape` is the stored column (`'word'`\|`'phrase'`\|`'expression'`), populated once from
CALL 1's already-computed `card_kind` — see [ai-integration](ai-integration.md). `Card::TYPE_LEXICAL`
/ `TYPE_EXPRESSION` become a **derived predicate** rather than a stored column:
`card_shape === 'expression' ? TYPE_EXPRESSION : TYPE_LEXICAL`. Everything that reads `term_type`
today — the `/cards` type filter, `Learning::modeTypeFilter`, the type `<select>` on
`cards/edit.blade.php` — needs no new information, only this derived source instead of a stored
one. The manual `/add` path (no AI, no `card_kind`) needs its own way to set `card_shape` — a form
field or a default, implementation's call.

### What a card is built around

Every card has exactly one thing it is about: its **Term**, always. There is no focus word and no
word/phrase split inside a Term — `translation`, `definition` and the `example_sentence` brackets
are always about the whole Term, in whatever form it takes. This replaces the old `word ?? phrase`
target: `Card::target()` collapses to the Term, and `Card::resolveFocusWord()` and
`Card::phraseHtml()` (which existed to bold a focus word inside a phrase) are retired along with
the column that fed them.

### The anchor phrase

A lone word is not replaced by a phrase card built around it — it keeps its own card and gains a
**word-shape-only**, nullable phrase as a property: `anchor` holds the Term's occurrence in
`[brackets]` inside a natural setting (`[collateral] damage`), `anchor_translation` its own
translation. It is proposed by CALL 1 at capture (from the Context when that already contains the
Term in a natural phrase, otherwise invented), editable in staging (see below), and its translation
is written by CALL 2 at approval. Its other words never reach the vocabulary base — only the Term
does. Re-suggesting one later is a regenerate.

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

`base_words` — one entry per lemma per language per learner:

| Column | Notes |
|---|---|
| `user_id`, `language_id` | owner + language |
| `lemma` | canonical spelling — the dedup key |
| `translation` | set once at creation from CALL 1, never revised — sense lives on cards, not here |
| `last_recalled_at` | nullable, stamped only on a correct answer |

Unique on `(user_id, language_id, lemma)`. There is no expressions store — an expression card's
surviving words link into `base_words` exactly like any other card's.

`card_base_word` — the pivot linking a card to the base entries for its Term's lexical words:

| Column | Notes |
|---|---|
| `card_id`, `base_word_id` | |
| `surface_form` | the spelling this card's Term actually uses (`kostade` in the Term, `kosta` in the base) |

Unique on `(card_id, base_word_id)` — one link per base entry per card, even when the Term repeats
a word. Cardinality is enforced at proposal-approval time against `card_shape`, not as a DB
constraint: a **word** card links exactly one base word (its own lemma — the proficiency filter
below must never drop it), a **phrase** card at least one and at most **5**, an **expression** card
zero or more.

The **already-present check**: for a word, query `base_words` on `(user_id, language_id, lemma)`;
for an expression (no separate store), query `cards` where `card_shape = 'expression'`,
case-insensitive match on `term`. Neither is stored — both are computed live, at staging render
time and again at approval, so two proposals for the same lemma approved in either order both link
to one `base_words` row instead of creating a duplicate.

**Two filters** narrow which of CALL 1's extracted words are proposed as base words in the first
place: **proficiency** (from the stored CEFR level — at B1 and above, prepositions, pronouns and
other very basic function words aren't suggested; it never drops a word card's own Term) and
**already present** (a lemma the learner already has isn't suggested again, but they're told it's
already there — the already-present check above).

## Staging

Capture no longer writes a card directly. It writes a `proposals` row and returns immediately;
CALL 2 and the card write happen only once the learner **approves** it.

`proposals`:

| Column | Notes |
|---|---|
| `user_id` | |
| `language_id` | nullable until CALL 1 resolves it — detection is CALL 1's job |
| `raw_input` | the term as typed/pasted, known immediately |
| `context` | nullable, the learner's own input |
| `term`, `card_shape` | nullable until CALL 1 resolves |
| `anchor` | nullable, editable, word-shape only — no `anchor_translation` here, since CALL 2 (post-approval) is what writes that |
| `source` | `'web'` \| `'extension'` |
| `status` | `pending` \| `processing` \| `completed` \| `failed` — the same async shape as `gap_fill_exercises` + `GenerateGapFillJob` (see [gap-fill](gap-fill.md)), so the fast-path skeleton polls/resolves the same way |

`proposal_base_words` — the strikeable candidate tray, one row per extracted lemma:

| Column | Notes |
|---|---|
| `proposal_id` | |
| `lemma`, `surface_form`, `translation` | carried straight onto `base_words`/`card_base_word` at approval |
| `struck` | boolean, default false |

**Approve** creates (or reuses, per the idempotency note above) the `base_words` rows for every
non-struck candidate, creates the card and its `card_base_word` links, and only then runs CALL 2.
**Discard** deletes the proposal; nothing is kept once its undo window passes.

Exact routes/controller shape for capture → staging → approve are implementation's call; this
section fixes what is stored and when, not the endpoints.

## Capture flow (creating a card)

There are still **two** ways a card gets created — one AI-assisted, one manual:

- **AI-assisted (the primary path)**: capture validates `capturedWord`/`context` and writes a
  `proposals` row (see "Staging" above) instead of a card, returning immediately. A queued job
  resolves CALL 1 against it — language detection, `card_shape` and the canonical Term, the
  candidate base words with their translations and, for a lone-word Term, a proposed anchor
  phrase. The learner reviews and edits the result in staging; the card itself, its base-word
  links and (via CALL 2) its content are written only on **approve**. The browser extension writes
  into the same `proposals` table — see [browser-extension](browser-extension.md).
- **Manual, no AI** (`GET /add` → `cards/add.blade.php` → `POST /cards/new` →
  `CardController::save()`, via `StoreCardRequest`): the user types every field themselves
  (`term`, `definition`, optional `translation`/`example_sentence`/`note`/`theme_id`, plus
  `anchor`/`anchor_translation` on a word-shape card) and sets `card_shape` directly. This path
  never goes through staging — there's nothing to approve that the learner didn't already type.
  Still dispatches `GenerateEmbeddingJob` so the card participates in linking/search the same way.
  This entry point has no nav link (reach it directly at `/add`) but is a real, working path.

`persist()` still maps the AI response onto the columns and dispatches `GenerateEmbeddingJob` (see
[search-and-linking](search-and-linking.md)), leaving `theme_id` null (the AI no longer assigns a
theme at capture time — see [wordboxes-themes-tags](wordboxes-themes-tags.md)).

Exact routes/controller/job names for the capture → staging → approve flow are implementation's
call; this section fixes what happens and in what order, not the endpoints.

## The duplicate check

`Card::scopeMatchingTerm($term)` is the single definition of "the learner already has this": a
case-insensitive match, within one language, against `term` alone — the `word` leg is gone with
the column. Owning a base word of a Term is a different fact from owning a card for that Term, and
never blocks capturing the card; staging's already-present notice (above) is what surfaces the
base-word fact.

Whether this check runs against a proposal's resolved Term before it can be approved (offering
**regenerate** on the existing card instead, as today) is implementation's call — this redesign
fixes the rule the check applies, not when in the new capture → staging → approve flow it runs.

## Regenerate

`Card::regenerate()` replaces an existing card's generated content while its review progress stays
put: it re-runs the two-call pipeline and `update()`s only the AI-written columns — `level`,
`last_studied`, `next_study_at`, the `note`, the wordbox pivot and every manual link are never
touched. It carries the caller's `context` if one is given, and otherwise falls back to the card's
stored `context`, so a regeneration keeps the sense the card was originally captured in. A `null`
from either call returns `false` and leaves the card exactly as it was; the embedding is
re-dispatched, since the content it described has changed (see
[search-and-linking](search-and-linking.md)).

`Card::contentColumns()` — the AI response → column mapping — covers **only** what the AI writes:
no `level`, no `next_study_at`, no `note`. `persist()` adds those for a new card; `regenerate()`
hands the array straight to `update()`, which is what makes "keep the progress" a property of the
mapping rather than a list of fields someone has to remember to exclude.

How a repeat capture reaches Regenerate — whether staging itself detects the existing card and
offers it in place of Approve, or capture surfaces it another way — is implementation's call; see
"The duplicate check" above.

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
below. The nav deliberately ignores the list's wordbox/type/search filters; it walks the language,
not whatever view the learner arrived from. At either end the missing arrow is still rendered, just
dimmed and non-clickable, so the row doesn't shift as the learner walks the list.

Renders: the card's term type (derived from `card_shape`, see above) as small lowercase text left
of the language flag; the anchor phrase editor below the term on a word-shape card (nothing for a
phrase or expression card); the bracketed `example_sentence` as plain text (bracket markers
highlighted, no bullets); the `note` if present; and the "Linked cards" section (below). The term
heading is `font-medium`, not `font-bold` — there is no focus word left to emphasize against it, so
the Term itself is what's shown. Because an `expression`'s Term can be much longer than a single
word, the heading wraps (`flex-wrap` + `break-words`), and every place that prints
`example_sentence` keeps `whitespace-pre-line` so a line break in the AI's answer survives
(`cards/show.blade.php` and the search-result `<x-card>` component). `cards/edit.blade.php` renders
`example_sentence` as a `<x-forms.textarea>` rather than a single-line input for the same reason,
and shows the anchor/anchor-translation inputs only when `card_shape` is `word`.

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
- `type` — one of `Card::TERM_TYPES` (the derived predicate over `card_shape`, see above) or `both`
  (default, no constraint). Rendered as a **3-option segmented control** (`#typeFilter`: Lexical |
  Both | Expressions) at the **left** of the bulk-action bar row (which stays right-aligned). This
  is a filter only — term type is **not** a table column, so `cards/_rows.blade.php` keeps a fixed
  6 columns regardless (checkbox, Term, Translation, Definition, Wordbox, row menu — and its
  `@empty` row's hardcoded `colspan="6"`, which has to be kept in step with them).
- `term` — substring match (`LIKE %…%`) against `term`.
- `definition` — substring match against `definition`.
- Search inputs are debounced ~250ms client-side before firing the AJAX request.

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
