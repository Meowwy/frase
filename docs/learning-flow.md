# Learning Flow (SRS flashcards)

The card-set builder, the spaced-repetition scheduling algorithm and the flashcard-style learning
modes. Unscheduled practice over the vocabulary base is [Frammenti](frammenti.md), which is not a
learning mode and touches no card. The last learning mode, live AI conversation, is only bootstrapped here — its actual chat logic is
in [conversation-challenge](conversation-challenge.md)'s sibling doc, the SRS-specific one: see
"Conversation mode" below, which hands off to `ChatController`.

## The builder (`/setLearning`)

The **Learn** nav link goes to `/setLearning`, a language-aware card-set builder (closure in
`web.php`, not a controller — see [overview](overview.md) "Routing"). Flow: pick a target language (the
`<x-wordbox-picker>` switcher row — multi-language users only, client-side toggle, no DB calls —
see [multi-language](multi-language.md)) → a wordbox scope (**nothing selected = all terms in the language**, the
*General vocabulary* node = terms in no wordbox, or a specific wordbox) → **Due** (default) vs.
**Cram** → a learning mode.

The route precomputes **per-selection card counts** (total + due, for "all", "general", and every
wordbox in every language) in one pass so the builder can show "N due cards" for every possible
choice without a round trip per click. These counts intentionally mirror the exact rules in
`Learning::getCardsForSelection` (due = `next_study_at` on/before today) — if you change one, you
must change the other, or the counts shown in the builder will disagree with what a session
actually serves.

Selection is submitted to `GET /startLearningSet/{mode}` as `language_id` / `wordbox`
(`all|general|<id>`) / `scope` (`due|cram`) query params, handled by
**`Learning::startLearningSet()`** (a static method used directly as the route action — see
[overview](overview.md)). It validates ownership of the language/wordbox, normalizes `wordbox`/`scope` to a
known value, and stores a **structured array** in `session('learning_filter')` before rendering.

### Legacy entry points (still live, don't remove)

- Theme cards: `GET /filterCardsForLearning/{filter}` → `Learning::setLearning($filter)` stores
  the filter (theme name or `'due'`) as a **plain string** in the session and redirects to
  `/setLearning`, which renders a **simple mode picker** (no builder UI) whenever the session
  filter turns out to be a theme name it recognizes.
- Wordbox detail page: links straight to `GET /startLearning/{wbid}/{mode}` (always **cram**
  scope) → `Learning::startLearning()`.
- Both of these, and the builder path, ultimately converge on
  **`Learning::renderLearningView($mode)`**, the single shared flashcard renderer.

## `Learning::getCardsForLearning()` — two calling conventions

Accepts **either** the structured array from the builder (dispatches to
`getCardsForSelection()`) **or** the legacy string filter (`'due'`, a numeric wordbox id, or a
theme name) for the old entry points — kept as one method so `renderLearningView()` doesn't need
to know which flow it's serving.

Both code paths share the same **due-set cap**, `Learning::onlyDue()`: if more than 20 cards are
due, only **15** are served and `session(['more_cards_available' => true])` is set (the UI can then
show a "there are more due" hint); otherwise all due cards are served. `cram` scope — and a
wordbox's own page, which is always cram — returns everything, uncapped. A theme is looked up
among the learner's own themes; an unknown one serves nothing. The returned collection is always
`->shuffle()`d.

Every mode serves every card. There is one kind of card (see [cards](cards.md)), so Definitions
skips no Term, however long or idiomatic: a whole-sentence Term's definition says when you'd say
it.

## Rendering a session (`Learning::renderLearningView($mode)`)

`Learning::cardEntries()` builds each entry **per card**,
`{id, front, back, hint, wordbox}`:

| Mode | front | back | hint |
|---|---|---|---|
| `translation` | `translation`, or `definition` when it is empty | the **Term** | *(none)* |
| `definitions` | `definition` | the **Term** | `translation` |

Any other mode, including a stale link to the removed `words` mode, is a 404.

**Every mode's answer is the card's Term** — there is no focus word to answer instead of it (see
[cards](cards.md) "What a card is built around").

`translation` is the classic Anki-style review and the builder's **first** tile. A native-language
card (see [multi-language](multi-language.md)) is generated without a translation, so its
definition stands in on the front — otherwise those cards would have an empty front. It has no
hint: the hint used to be the card's blanked example sentence, and cards no longer have one.

The full set is serialized as a JS variable (`let cards = [...];`) and handed
to `learning/index.blade.php`, which drives the whole session **client-side** — no per-card request
during review.

A correct answer in either mode clears the card (SRS level/`next_study_at` advance —
see "SRS algorithm" below) and stamps last-recall on **every** base word and fixed expression
linked to it (`Card::stampRecall()`) — producing the Term is producing all of them. Conversation mode's clearing/stamping is the same; see
"Conversation mode" below.

`mode === 'conversation'` **short-circuits** this whole flow — see "Conversation mode" below.

## SRS algorithm

`Learning::getNextStudyDay($level, $result)`:

- `result === 1` (correct) → `next_study_at = now() + 2^(level - 1) days` — a simple doubling
  interval per level (level 1 → +1 day, level 2 → +2 days, level 3 → +4 days, …).
- otherwise (wrong) → `next_study_at = now() + 1 day`.

Persisting a result (`AjaxController@saveLearning` — the whole of what that controller is now,
`POST /saveLearning`) reads a JSON array of
`{id, result}` from the request, and per card: sets `next_study_at` via the formula above,
increments `level` on a correct result or **resets it to 1** on a wrong one, stamps
`last_studied = now()`, saves. The cards are loaded through `Auth::user()->cards()`, so an id
the learner doesn't own is silently skipped.

Besides the schedule, a correct result stamps `last_recalled_at` on every base word and fixed
expression linked to that card (`Card::stampRecall()`).

Conversation mode's recap (`ChatController@recap`) applies the `results` branch's behaviour
directly, base-word stamping included.

**In-session flow is repeat-until-correct**, entirely client-side: a card marked *Wrong* is
recorded locally (only actually persisted to `/saveLearning` as whatever its **final** result in
the session was, once) and stays in the deck until answered *Correct*; the session ends only when
the local deck queue is empty. The visible "queue" counter is the real remaining-card count, not
a one-pass countdown.

## Flashcard view details (`learning/index.blade.php`)

- Shows the card's **wordbox name** in an orange pill above the card
  (`$card->wordbox->first()?->name`); a card in no wordbox keeps the pill's space reserved but
  `invisible`, so cards don't visually jump around depending on whether they have one.
- "Save and quit" is the standard back-button style (arrow + text), absolutely positioned
  top-left, rather than a full-width strip.
- The session-complete screen (`learning/complete.blade.php`) offers **Play Frammenti** in the
  finished session's language, so the learner can keep practising once the due cards run out (see
  [frammenti](frammenti.md)).
- The **hint** panel is hidden for any entry whose `hint` is empty, which today is everything but
  Definitions. It is kept, not deleted, so a future hint source only has to fill the field.

## Conversation mode (live AI roleplay chat)

`data-mode="conversation"` in `set.blade.php` replaced an earlier "Questions" panel that had gone dead (linked to a 404). Selecting it hands
off entirely to `Learning::startConversation()` (called from `renderLearningView`) instead of
building a front/back/hint deck:

1. Takes **up to 10** cards from the current selection.
2. Resolves the language's CEFR level via `User::levelForLanguage()`.
3. Calls `AI::startConversation()` for an opening line (see [ai-integration](ai-integration.md)). On failure,
   redirects back to `/setLearning` with a popup message rather than rendering a broken chat.
4. Seeds **ephemeral** `session('chat_practice')`:
   `{messages, target_words: [{id, term, translation}], used_ids, stale_count, language_id,
   level, cache_key}` — **no DB row** is created for the chat itself.
5. Renders `learning/conversation.blade.php`.

The chat itself is **synchronous** (a jQuery `$.post` per turn with a "typing…" bubble while
waiting — no queue/job) and is handled by `ChatController`, not `Learning`:

- **`POST /chat/message`** (`ChatController@message`) — appends the learner's message, sends the
  AI only the **still-remaining** target words (never the ones already used), and merges any
  newly-used ids into `used_ids` (session ids are `intval`-cast on read, since a round-tripped
  session value coming back as a string would otherwise break the strict `in_array` comparisons
  used to decide "already cleared"). `used_ids` only ever **grows**, so the "words left" counter
  the client shows is monotonic — it can't tick back up. A **stall guard** ends the chat after
  **4 consecutive** learner messages that used no new word; it also ends normally once
  `remaining_count` hits 0.
- **`POST /chat/recap`** (`ChatController@recap`) — calls `AI::conversationRecap()` for
  `corrections` text, then applies an **SRS credit** for every used word: same formula as a
  correct manual review (`getNextStudyDay(level, 1)`, `level++`, `last_studied = now()`) — this
  mirrors `AjaxController@saveLearning`'s `result: 1` branch exactly, just applied automatically
  instead of from a client-submitted array. Unused words are left completely untouched (no
  penalty for a word that just didn't come up in an 10-card, one-chat sample). Clears
  `chat_practice` afterward.

The server is the sole authority on which words counted as "got" — the recap AI call is **not**
asked to judge this (see [ai-integration](ai-integration.md) "conversationRecap") — only word *usage detection*
during the chat is AI-judged (via `matchUsedWords`); the deterministic end/stall/SRS-credit logic
all lives in PHP.
