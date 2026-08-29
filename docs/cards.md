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
| `term_type` | `'lexical'` \| `'expression'`, DB default `'lexical'` — see [ai-integration](ai-integration.md) "Term types" |
| `phrase` | the term itself, base/canonical form (see [ai-integration](ai-integration.md)) |
| `word` | nullable — the single word inside `phrase` the learner originally wanted to learn, **always in the form `phrase` spells it** (see "The `word` invariant" below) |
| `translation` | native-language equivalent; `''` for a native-language card (column is `NOT NULL`) |
| `example_sentence` | one sentence with the **card's target** wrapped in `[brackets]` once — powers the Sentences learning modes. On a phrase card with a `word`, the brackets hold that word, not the whole phrase (see "What the card is built around") |
| `example_1`, `example_2`, `example_3` | nullable, short unbracketed usage fragments of **2-3 words**, **lexical-only** |
| `definition` | dictionary definition or (for an expression) a usage note |
| `note` | nullable free-text, user-editable, not AI-generated |
| `context` | nullable — the learner's own context input, kept so a card can be regenerated in the sense it was captured in |
| `level` | integer SRS box/level — see [learning-flow](learning-flow.md) "SRS algorithm" |
| `last_studied`, `next_study_at` | SRS scheduling dates |
| `embedding` | nullable JSON array (`array` cast) — see [search-and-linking](search-and-linking.md) |

`question` existed originally but was **dropped** (migration
`2026_08_04_000001_add_examples_and_note_drop_question_from_cards`) along with adding
`example_1..3` and `note` — there is no `question` field or learning mode anymore.

## Model (`app/Models/Card.php`)

```
Card::TYPE_LEXICAL = 'lexical'
Card::TYPE_EXPRESSION = 'expression'
Card::TERM_TYPES = [TYPE_LEXICAL, TYPE_EXPRESSION]
```

### What the card is built around

Every card has exactly one **target** — the thing the learner is memorising — and it is
`word ?? phrase`:

| Card | target | what `translation`, `definition` and the sentence's brackets are about |
|---|---|---|
| word | the single word (`word` is `null`, `phrase` *is* the word) | that word |
| phrase **with** a `word` | that focus word | the word, in the sense it carries inside the phrase |
| phrase **without** a `word` | the whole phrase | the phrase as a unit |
| expression | the whole utterance | the utterance as a unit |

The middle row is the point of this whole feature: the phrase is *structure*, there to make the
word memorable and to fix its sense — it is not itself what is being learnt. So a card built
around `vet` inside `vet a candidate` translates and defines **`vet`**, and its sentence brackets
`[vet]` alone even though the whole phrase appears in it.

`word` being `null` is therefore not "no target" but "**the phrase is the target**" — the learner
typed a multi-word term, or picked one, and wants the unit itself. The phrase is not duplicated
into `word` to say so: `word` is reserved for the case where target and displayed term differ,
which is exactly the case `phraseHtml()` needs to mark. Most whole-unit terms are classified
`expression` anyway (see [ai-integration](ai-integration.md)); the ones that stay `lexical` are
compounds and naming-unit idioms — `traffic jam`, `under the weather`. A speaker-anchored idiom
like `not my cup of tea` is an expression, not a lexical phrase.

`Card::target()` returns exactly this — `word ?? phrase` — and it is what the **learning flow**
asks for: every mode's answer is the target, not the displayed `phrase`. `words` shows the target's
translation, `definitions` shows its definition, and both Sentences modes hide it in the example
sentence (in whatever form that sentence inflects it into, which is why they read the answer out of
the brackets rather than calling `target()` directly). The phrase around the gap stays visible as
the context that makes the word recallable. See [learning-flow](learning-flow.md).

### The `word` invariant

`word` holds the focus word **exactly as `phrase` spells it**, never the base form:
`phrase = "vet a candidate"` → `word = "vet"`, but `phrase = "vetting candidates"` → `word =
"vetting"`. This is what lets `Card::phraseHtml()` bold it with a plain substring match instead of
any kind of stemming, which could never work across the ~42 supported languages.

It is upheld in two places: the AI is asked for the word *as spelled in the phrase* (the phrase
card's schema resolves it against the finished phrase — see [ai-integration](ai-integration.md)),
and `Card::resolveFocusWord()` then drops it to `null` unless it really occurs inside `phrase` and
isn't the whole phrase. A `word` that can't be found is stored as `null` rather than left to
silently highlight nothing.

`word` is `null` for expression cards, for single-word cards (the card *is* the word), and for a
multi-word phrase the learner typed themselves — there is no distinct focus word in any of those.

### Helpers on the model

- **`Card::phraseHtml(string $emphasis = 'font-bold'): string`** — the phrase as HTML with `word`
  wrapped for emphasis. **Escapes with `e()` first** and only the `<span>` it adds is trusted,
  because every caller renders it with `{!! !!}` — the same discipline as the bracketed sentence in
  `CardController@show`. Used by `cards/show.blade.php` (whose heading is `font-medium` so the bold
  word reads as emphasis, and which passes `'font-bold underline underline-offset-4'` — the detail
  page is the one place a card is read closely, so the target word is spelled out there),
  `cards/_rows.blade.php`, `cards/_linked_rows.blade.php`,
  `components/card.blade.php`, `components/card-wordbox.blade.php`, `wordbox/index.blade.php` and
  the dashboard's "recently added" table. **Any query feeding those views must select `word`** — the
  narrow `get(['id','phrase','translation'])` selects were widened for exactly this reason.
- **`Card::suggestedPhrases(): array`** — the non-empty `example_*` fragments of a lexical card;
  empty for an expression. This is the single condition for showing the "learn it in a phrase
  instead" nudge.

Relations: `user()`, `theme()`, `language()`, `wordbox()` (belongs-to-many via `wordbox_card`),
`tag()` (has-many — see note in [wordboxes-themes-tags](wordboxes-themes-tags.md) about `Tag`'s actual relation shape),
`synonyms()` / `relatedTerms()` (see [search-and-linking](search-and-linking.md)), and `linkedCards()` — the
user-manual link relation, see below. `scopeForLanguage($query, $languageId)` is the standard
per-language scope used throughout the app.

## Capture flow (creating a card)

There are **two** ways a card gets created — one AI-assisted, one manual:

- **AI-assisted (the primary path)**: `AjaxController@index` (`POST /captureWordAjax`; the same
  controller action is reused by the browser extension at `POST /api/addWordAPI` — see
  [browser-extension](browser-extension.md)). Steps below.
- **Manual, no AI** (`GET /add` → `cards/add.blade.php` → `POST /cards/new` →
  `CardController::save()`, via `StoreCardRequest`): the user types every field themselves
  (`phrase`, `definition`, optional `translation`/`example_sentence`/`example_1..3`/`note`/
  `theme_id`). Saves under `currentSaveLanguage()` like the AI path, and still dispatches
  `GenerateEmbeddingJob` so the card participates in linking/search the same way. This entry
  point has no nav link (reach it directly at `/add`) but is a real, working path.

Steps for the AI-assisted path, in order:

1. Validate `capturedWord` (2-120 chars — long enough to accept a **pasted sentence**, which the
   AI reduces to a reusable expression frame), optional `context` (2-250 chars), optional
   `language_id`/`wordbox_id`.
2. Resolve the **save destination**: `resolveSaveLanguage()` (request → session
   `capture_language_id` → `User::currentSaveLanguage()`) and `resolveSaveWordbox()` (request →
   session `capture_wordbox_id` → none). If there's no language to save into (new user, no
   languages configured yet), redirect to `/profile/edit` (or return 422 for a JSON/extension
   caller). See [multi-language](multi-language.md) for the save-destination picker itself.
3. **Duplicate check**: case-insensitive `phrase` match within the same language — a 409 (or a
   plain redirect) if it already exists. Note this checks the term *as typed*, before the AI
   canonicalizes it, so `vetting` doesn't collide with an existing `vet` card.
4. Hand off to **`Card::createFromTerm()`**, which runs the two AI calls and writes the row — see
   [ai-integration](ai-integration.md). Everything from here down lives on the model, not the
   controller, so the "learn it in a phrase instead" path can reuse it.
5. `null` from either call (refusal, non-2xx, unparseable answer) → a 500 for a JSON caller, or a
   redirect with a `popup_message`. The controller no longer tries to parse a failed response.
6. Otherwise attach the resolved wordbox if one was chosen, and redirect to `/` flashing
   `captured_card_id` so the dashboard can show the phrase nudge (below).

`Card::createFromTerm()` itself: call 1 (`AI::analyzeTerm`) → route to the matching call 2 → persist
via the private `Card::persist()`, which maps the AI response onto the columns, dispatches
`GenerateEmbeddingJob` (see [search-and-linking](search-and-linking.md)) and leaves `theme_id` null
(the AI no longer assigns a theme at capture time — see
[wordboxes-themes-tags](wordboxes-themes-tags.md)). `persist()` also cleans the `examples`:

- **blank entries are filtered out** — the model still occasionally returns a stray `[""]`, which
  would otherwise become an empty box on the card page;
- **square brackets are stripped**. Brackets belong to `example_sentence` alone, where the
  `/\[.*?\]/` blanking regex uses them. The model has been observed leaking them into a fragment
  (`"a rooted [tree] with three levels"`), and since a fragment can become another card's `phrase`,
  a bracket there would travel into a sentence that must bracket exactly once and quietly break
  that card's Sentences modes.

## Learning a word inside a phrase (the replacement flow)

A single-word card is treated as a **stepping stone**, not a finished card. Its three fragments are
natural phrases the word occurs in, and each is offered as something to learn *instead* — this is
the app's "vocabulary in context" premise made actionable, rather than left as a slogan.

`<x-phrase-suggestions :card="$card"/>`
(`resources/views/components/phrase-suggestions.blade.php`) renders the nudge and owns its own
jQuery, the way `<x-wordbox-picker>` does (see [frontend-patterns](frontend-patterns.md)). It
renders nothing when `suggestedPhrases()` is empty, so phrase and expression cards are unaffected.
It appears in **two** places: on the card detail page, and on the dashboard right after a capture
(driven by the `captured_card_id` flash), so the nudge lands while the learner's intent is fresh.

Clicking a fragment posts to `POST /cards/{card:id}/learn-as-phrase`
(`CardController@learnAsPhrase`), which:

1. authorizes via `CardPolicy` (`update`), and **rejects any phrase that isn't one of that card's
   own `example_*`** — the click targets are server-known, so there's no reason to accept arbitrary
   text;
2. returns **409** (with a link to the existing card) if the learner already has that phrase, and
   leaves both cards untouched;
3. in a `DB::transaction`, calls **`Card::createFromPhrase()`** — which *skips call 1*, since the
   shape and phrase are already known, so an upgrade costs **one** model call, not two. The old
   card's `phrase` goes in as the focus word and comes back stored in the new phrase's own form
   (`vet` + "vetting candidates" → `word = "vetting"`). A `null` here aborts the transaction: the
   original must survive a failed generation;
4. carries the old card over as an **in-place upgrade** — `level`, `last_studied`, `next_study_at`,
   `note`, the wordbox pivot, and every manual link re-attached in **both** directions. A card
   studied for weeks doesn't restart at level 1 just because the learner sharpened what it teaches;
5. detaches every pivot row before deleting the old card (unlike the older
   `POST /cards/{card:id}/delete` closure, which leaves orphans — follow `bulkDestroy` instead);
6. returns `{redirect}` to the new card.

**Pre-existing cards**: fragments captured before this change are old-style inflected drills
("broke a window"). They are still clickable and still produce a valid phrase card, so this
degrades gracefully — but the suggestions on old cards aren't collocations. There is no backfill.

`AjaxController@setCaptureTarget` (`POST /capture-target`) is the endpoint the save-destination
picker on the dashboard calls to persist the chosen language/wordbox into the session (and, for
the language, as the user's new durable `active_language_id`).

## Card detail page (`cards/show.blade.php`, `CardController@show`)

`show()`, `edit()`, and `update()` all check ownership via `$this->authorize(..., $card)` against
`CardPolicy` (see [overview](overview.md) "Authorization") before doing anything else — a card
belongs to exactly one user and no one else can view, edit, or update it.

`show()` also escapes `example_sentence` (`e($card->example_sentence)`) **before** splicing in the
`[term]` → `<span>` highlight markup, since the view renders the result with `{!! !!}` — the
sentence itself is AI-generated (ultimately from user input, see [ai-integration](ai-integration.md)) and must
never be trusted as pre-sanitized HTML. `SeachController::index`/`searchWordbox` do the same for
their own copies of this highlight logic.

Renders: `term_type` as small lowercase text left of the language flag; `<x-phrase-suggestions>`
below the term (see the replacement flow above — nothing for a phrase or expression card); the
bracketed `example_sentence` as plain text (bracket markers highlighted, no bullets); the `note` if
present; and the "Linked cards" section (below). The term heading is `font-medium`, not `font-bold`,
so `phraseHtml()`'s focus word reads as emphasis against it; here alone it is **underlined as well
as bold**, because this is the page where the learner studies the card and needs to see at a glance
which word of the phrase is the one being learnt. The linked-cards rows the JS
appends after a link use a server-built `phrase_html` from `CardController@link` so they match the
server-rendered rows. Because an `expression`'s `phrase` can be much longer than a single word, the
term heading wraps (`flex-wrap` + `break-words`), and every place that prints `example_sentence` keeps
`whitespace-pre-line` so a line break in the AI's answer survives (`cards/show.blade.php` and the
search-result `<x-card>` component). `cards/edit.blade.php` renders `example_sentence` as a
`<x-forms.textarea>` rather than a single-line input for the same reason, and **hides** (not
removes — a small jQuery handler keeps it submittable) the three example-phrase inputs when
`term_type` is switched to `expression`.

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
whose **Term**/**Definition** column headers are themselves live search `<input>`s, and a
**Wordbox** column); an AJAX request (`$request->ajax()`) returns JSON `{rows, pagination}`
rendered from the `cards/_rows.blade.php` partial, which the page swaps into `#cardsTableBody` /
`#cardsPagination` — the header inputs stay in the DOM the whole time, so focus is preserved
while typing.

Filters, all combinable and all preserved across pagination via `->appends($request->query())`:

- `language_id` — defaults to `currentSaveLanguage()`. Falls back to that default if an invalid
  id is passed.
- `wordbox` — `all` (default) \| `general` (no wordbox) \| a wordbox id.
- `type` — one of `Card::TERM_TYPES` or `both` (default, no constraint). Rendered as a
  **3-option segmented control** (`#typeFilter`: Lexical | Both | Expressions) at the **left** of
  the bulk-action bar row (which stays right-aligned). This is a filter only — term type is
  **not** a table column, so `cards/_rows.blade.php` keeps a fixed 5 columns regardless (and its
  `@empty` row's hardcoded `colspan="5"`).
- `term` — substring match (`LIKE %…%`) against `phrase`.
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
