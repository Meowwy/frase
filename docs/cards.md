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
| `translation` | native-language equivalent; `''` for a native-language card (column is `NOT NULL`) |
| `example_sentence` | one sentence with the term wrapped in `[brackets]` once — powers the Sentences learning modes |
| `example_1`, `example_2`, `example_3` | nullable, short unbracketed usage fragments, **lexical-only** |
| `definition` | dictionary definition or (for an expression) a usage note |
| `note` | nullable free-text, user-editable, not AI-generated |
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
   plain redirect) if it already exists.
4. Build the theme list for that language (up to 20 names) to hand the AI as candidates.
5. Pick which `AI::` generator to call (native destination → monolingual; else with/without
   `context` — see [ai-integration](ai-integration.md)).
6. Decode the JSON result. If the returned `theme` doesn't match an existing one and the user has
   fewer than 20 themes for this language, a new `Theme` row is created and used.
7. `examples` from the AI response are trimmed and **blank entries filtered out** — the model
   still occasionally returns a stray `[""]` even though it's asked for exactly 3 for a lexical
   term, so this guards against an empty box on the card page.
8. Create the `Card` row, dispatch `GenerateEmbeddingJob` (see [search-and-linking](search-and-linking.md)), and attach
   the resolved wordbox if one was chosen.

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

Renders: `term_type` as small lowercase text left of the language flag; the 3 `example_*`
fragments as small boxes below the term (lexical cards only — hidden for expressions, since
those get an empty `examples` array); the bracketed `example_sentence` as plain text (bracket
markers highlighted, no bullets); the `note` if present; and the "Linked cards" section (below).
Because an `expression`'s `phrase` can be much longer than a single word, the term heading wraps
(`flex-wrap` + `break-words`), and every place that prints `example_sentence` keeps
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
