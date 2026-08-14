# Wordboxes, Themes & Tags

Three different ways cards get grouped in this app — they look similar but serve different
purposes and are at very different levels of completeness.

## Wordboxes — the real, actively-developed grouping

A `Wordbox` (`wordboxes` table: `name`, `user_id`, `description`, `exam_text`, `language_id`,
`position`) is a user-created, named collection of cards in one language — the closest thing to a
"deck". `Wordbox::cards()` is `belongsToMany(Card::class, 'wordbox_card', ...)`, so a card can in
principle belong to more than one wordbox, though the bulk "assign wordbox" UI in [cards](cards.md)
treats a card as belonging to at most one at a time by `sync()`-ing rather than `attach()`-ing.

- **Creation**: `POST /wordbox/new` (`WordboxController@store`, via `StoreWordboxRequest`:
  `name` required, `description` nullable ≤1000 chars, optional `language_id`). If a
  multi-language user picked a language in the creation modal it's validated against their
  attached languages; otherwise it falls back to `currentSaveLanguage()`. `position` is set to
  `max(position) + 1` within that language, so new wordboxes append to the end of the ordering.
- **Ordering**: `/profile/wordboxes` (`UserController@wordboxesOrder`, drag-and-drop UI, presumably
  SortableJS given it's referenced in `documentation.md`'s changelog as a CDN dependency) shows
  wordboxes grouped by language; `POST /profile/wordboxes` (`@updateWordboxesOrder`) takes
  `order[languageId] = [wordboxId, ...]` and writes sequential `position` values, scoped to
  wordboxes the user actually owns (`$user->wordboxes()->pluck('id')->flip()` as a guard).
- **Detail/edit pages**: `GET /wordbox/{id}` (show) and `/wordbox/{id}/edit` both list the
  wordbox's cards. `PATCH /wordbox/{id}` (`@updateName`) renames it. `POST /saveCards/{id}`
  (`@update`) takes a JSON `cards` array from the edit page and diffs it against the current
  pivot rows — `array_diff` on ids to compute detach/attach sets — rather than a full
  detach-then-reattach, so unrelated pivot rows (and their `created_at`) aren't churned.
- Gap-fill exercises are generated per-wordbox — see [gap-fill](gap-fill.md).
- `WordboxController@index`/`create`/`destroy` are empty stock-scaffold methods; wordboxes aren't
  currently deletable through the UI.

## Themes — legacy grouping, still live but not actively extended

A `Theme` (`themes` table, per-language like everything else) is an older, flatter category.
`Theme::cards()` is a plain `hasMany` — a card has **at most one** theme (`cards.theme_id`,
nullable, `onDelete('set null')`), unlike the many-to-many wordbox relationship.

The AI no longer assigns a theme at capture time — `AjaxController@index` creates every new card
with `theme_id` unset (see [ai-integration](ai-integration.md)/[cards](cards.md) "Capture flow").
A card can still get a theme via the manual card-creation path (`theme_id` is one of the fields
on `/add`, see [cards](cards.md)); the "AI-suggested theme" behaviour used to live in
`AI::getContentForCard`/`WithContext`/`Native`'s `theme` schema property, which has been removed
from all three.

- The **dashboard** (`/`) still lists themes with total/due card counts (`withCount`) as one of
  its browsing surfaces, and links each one to `/cards?theme=<name>` — the vocabulary list's
  legacy theme-filter entry point (see [cards](cards.md)).
- `/filterCardsForLearning/{filter}` still supports starting a learning session filtered by theme
  name (see [learning-flow](learning-flow.md) "Legacy entry points").
- **Management** (`GET /themes/manage`, `ThemeController@create`/`store`) is a bulk rename/delete
  UI (`user.themes` view): the client posts a full JSON list of `{id?, name}` for the current
  save language; the server deletes any existing theme of that language **not** present in the
  posted list, then updates or creates the rest. Since it's scoped to
  `currentSaveLanguage()` on both read and write, switching languages can never delete another
  language's themes.
- `AI::generateThemes` (see [ai-integration](ai-integration.md)) still exists but has no live caller —
  the debug-only `ThemeController@generate`/`POST /generateThemes` route and the broken
  `CreateThemesJob` job (referenced a nonexistent `phrases()` relation) that used to call it have
  been removed. If AI-suggested theme names is ever wanted as a real feature, it needs a proper
  UI, not a resurrection of either of those.
- Wordboxes have mostly superseded themes as the primary organizing concept for new work; themes
  are kept because existing cards/UI depend on them, not because they're the preferred pattern
  going forward.

## Tags — present in the schema, essentially unused

`Tag` (`tags` table) and the `card_tag` pivot exist from an early migration
(`2024_08_15_114847_create_tags_table`, `2024_08_15_115737_card_tag`), and `Card::tag()` /
`Tag::cards()` relations are defined (note the inconsistent naming: `Card::tag()` — singular — is
actually a `hasMany`, not the `belongsToMany` you'd expect from the pivot table's existence;
`Tag::cards()` is the `belongsToMany` side). `TagController` is a full resource-controller
skeleton with every method **empty** except `__invoke()` (used by the single wired route,
`GET cards/{tag:name}`) and `store()` (via `StoreTagRequest`, itself empty rules). There's no UI
to create or assign a tag — treat tags as present-but-dormant infrastructure, not a feature to
extend without first deciding whether it should be finished or removed in favor of wordboxes.

`tags` has **no `user_id` column** — a tag name is shared/global, not owned by a user — so
`__invoke()` can't use a `CardPolicy`-style ownership check the way [cards](cards.md)'s other
endpoints do; instead it scopes the *cards* themselves (`$tag->cards()->where('cards.user_id',
Auth::id())->get()`), otherwise a tag would return every user's cards that happen to share that
tag name.
