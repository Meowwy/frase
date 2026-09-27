# Learning Flow (SRS flashcards)

The card-set builder, the spaced-repetition scheduling algorithm, the four flashcard-style
learning modes, and Refresher, the unscheduled vocabulary-base practice that sits alongside them.
The fifth learning mode, live AI conversation, is only bootstrapped here — its actual chat logic is
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

Both code paths share the same **due-set cap**: if more than 20 cards are due, only **15** are
served and `session(['more_cards_available' => true])` is set (the UI can then show a "there are
more due" hint); otherwise all due cards are served. `cram` scope always returns everything,
uncapped. The returned collection is always `->shuffle()`d.

### Term-type filtering per mode

`sentences`, `sentences_write`, and `definitions` are **lexical-only** modes
(`Learning::LEXICAL_ONLY_MODES`) — their card fronts are built from a blanked example sentence or
a dictionary definition, both of which only make sense for a naming unit, not a whole utterance.
`Learning::modeTypeFilter($mode)` returns a closure (`whereIn('card_shape', ['word', 'phrase'])` —
the derived `term_type`'s lexical leg, now read off `card_shape` directly, see
[ai-integration](ai-integration.md)) that **every** card query in both
`getCardsForLearning`/`getCardsForSelection` applies via `->tap()`. This is applied **before** the
due-count/15-card cap, not after — filtering the collection afterward would have skewed which
cards land inside the cap. `words` and `conversation` modes pass no restriction, so they keep both
types; `lexical` cards are served by every mode exactly as before this filter existed — it only
ever *removes* expressions from the lexical-only modes.

There is **no frontend surface for this yet**: the mode buttons in `set.blade.php` are always
offered regardless of whether a selection has any lexical cards, and the "N due cards" counts
computed in `web.php` are not mode-aware, so a lexical-only mode can end up serving fewer cards
than the count implied.

## Rendering a session (`Learning::renderLearningView($mode)`)

For `sentences`, `sentences_write` and `definitions`, each entry is still built **per card**,
`{id, front, back, hint, wordbox}`:

| Mode | front | back | hint |
|---|---|---|---|
| `sentences` | `example_sentence` with the bracketed span replaced by `...` | **the bracketed form** | `translation` |
| `sentences_write` | *(see below — split, not a single front)* | *(none — the split carries `answer`)* | `translation` |
| `definitions` | `definition` | the **Term** | `translation` |

**Every mode's answer is the card's Term** — there is no focus word to answer instead of it (see
[cards](cards.md) "What a card is built around"). `definitions` shows the Term itself as the back.

The two Sentences modes take the answer from the sentence itself instead, because the gap is the
question: whatever the brackets hide is what the learner has to produce. That is the Term too, but
in the form *this* sentence inflects it into, which the stored `term` (a base form) would not
match — so `sentences` reveals `Learning::sentenceParts($card)['answer']` as its back, the same
string `sentences_write` grades against. `sentences_write` therefore carries no `back` at all; its
`answer` key is the one source of truth, and the flip-card `back` element is not rendered in that
mode anyway.

The blanking uses the same `/\[.*?\]/` regex the sentence-bracket prompt rule exists to support
(see [ai-integration](ai-integration.md)), now always around the **whole Term** rather than a
focus word inside it. The full set is serialized as a JS variable (`let cards = [...];`) and handed
to `learning/index.blade.php`, which drives the whole session **client-side** — no per-card request
during review.

A correct answer in any of these three modes clears the card (SRS level/`next_study_at` advance —
see "SRS algorithm" below) and stamps last-recall on **every** base word linked to it — producing
the Term is producing all of its words. Conversation mode's clearing/stamping is the same; see
"Conversation mode" below.

`mode === 'conversation'` **short-circuits** this whole flow — see "Conversation mode" below.

### Words mode (redefined)

`words` no longer builds one entry per card. It pulls the **individual base words** of due cards
into one shuffled, per-word session capped at **15 words**: a due card enters the pool only if
*all* of its base words fit under that cap — no card contributes a partial word set. This can't
overflow a single card, because a card is capped at 5 base words (see [cards](cards.md)). Cards
with **zero** base words (an expression whose words were all filtered at capture — see
[cards](cards.md) "The vocabulary base") are excluded from this pool entirely; they can only clear
through the other modes.

Each entry is `{base_word_id, card_id, front, back, hint}`:

- **front** — the base word's own translation (from the vocabulary base);
- **back** — the base word's **lemma**, never the inflected surface form — Words always tests the
  lemma, unlike the deferred hide-a-word mode (out of scope — see `USERFLOW.md`), which is the one
  place the surface form would matter;
- **hint** — the parent card's context (its blanked example sentence).

Answers are tracked client-side and batched into one write at session end, extending
`/saveLearning`'s existing `{id, result}` array with a parallel per-word array (see "SRS algorithm"
below) — no live per-word request. A card **clears** once every one of its base words has been
answered correctly within the session — a second path to "the Term is produced," word-by-word
rather than as one string — and each correct word also stamps that word's own last recall
independently of whether the card as a whole clears.

## Refresher

Free-form, unscheduled practice over the **whole vocabulary base** — not scoped to a card, a
wordbox, or the `/setLearning` builder's due/cram split. Cards are ordered by **staleness** (how
long since last recall), most stale first. Front/back are the same shape as Words mode's own. A
correct answer stamps that word's last recall and nothing else: no SRS level, no `next_study_at`,
and it never clears a card. Refresher is deliberately **not** a learning mode — no session scope,
which is what separates it from Words.

## SRS algorithm

`Learning::getNextStudyDay($level, $result)`:

- `result === 1` (correct) → `next_study_at = now() + 2^(level - 1) days` — a simple doubling
  interval per level (level 1 → +1 day, level 2 → +2 days, level 3 → +4 days, …).
- otherwise (wrong) → `next_study_at = now() + 1 day`.

Persisting a result (`AjaxController@saveLearning`, `POST /saveLearning`) reads a JSON array of
`{id, result}` from the request, and per card: sets `next_study_at` via the formula above,
increments `level` on a correct result or **resets it to 1** on a wrong one, stamps
`last_studied = now()`, saves. There is no per-card ownership check here beyond `Card::find` —
the id list comes from the session-driven client the user is already looking at, not arbitrary
input.

A Words-mode session extends this same request with a parallel per-word array (base word id +
result), stamping `last_recalled_at` on each correct one independently of the card-level array —
which is what still lets a card clear here without every base word answer needing its own request.
Exact key/endpoint shape for the per-word array is implementation's call.

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

### Sentences — writing variant (`sentences_write`)

A second flavour of the Sentences mode where the learner **types** the missing word instead of
flipping a card. Same page, same deck/SRS/counters/hint/"Save and quit" — only the card area and
action button change: `$writeMode = $mode === 'sentences_write'` swaps the flashcard for a panel
holding the example sentence with an **inline `<input>`** where the blank is, and swaps
Flip/Wrong/Correct for a single **Check → Next** button.

`Learning::sentenceParts($card)` splits `example_sentence` around the **first** `[...]` into
`before`/`answer`/`after` — so the checked answer is the **exact inflected form the sentence
actually hides**, not the card's base-form `term` (a sentence with no brackets at all falls
back to using the whole sentence as `before` and the **Term** as the answer, so the mode degrades
gracefully instead of erroring).

Checking is entirely **client-side** (no request, same pattern as the gap-fill exercise checker)
and deliberately **forgiving about form, not spelling**: `isAnswerCorrect()` lowercases, collapses
whitespace, and strips punctuation the sentence's surrounding text can drag into what the learner
selects/types (`. , ! ? ; : … " ' ( )` etc.) — **apostrophes are kept**, since they're part of the
word itself (contractions, possessives). If that still doesn't match, it retries with every
hyphen replaced by a space, so a hyphenated term is accepted written apart too (`e-mail` /
`e mail`). Result colours reuse the gap-fill exercise's green/red input-border classes. The typed
text stays visible and the correct answer is revealed **below** the sentence after checking.
Grading is then automatic: **Next** feeds the boolean comparison straight into the same
`advance(correct)` function the Wrong/Correct buttons in the other modes call — it's the same
repeat-until-correct path, just fed a computed result instead of a manual button press.

**A checked answer is final** — `check()` sets the input `readOnly` and nothing takes that back
before the next card. Letting a wrong answer be corrected in place was tried and reverted: it
would have been redundant, because a wrong card is not lost anyway. It stays in the deck and comes
round again in the same session, so the learner gets their second attempt there, on a card they
have to type from scratch rather than one with the answer already on screen.

**Keyboard**: `Enter` checks while the input is editable; the page's shared spacebar shortcut
(used to flip/advance cards in the other modes) skips text inputs only while they're editable, so
once `check()` sets the input `readOnly`, the **spacebar** works to trigger Check/Next exactly
like it advances a card elsewhere.

In `set.blade.php`, the Sentences mode tile is split horizontally into two halves (`divide-y`,
each its own hoverable `.mode-link`) — *Sentences* (flip-card) on top, *Writing* below — so the
mode grid still shows 4 top-level tiles even though there are 5 modes total.

## Conversation mode (live AI roleplay chat)

`data-mode="conversation"` in `set.blade.php` is the fourth (well, fifth-counting-writing) mode —
it replaced an earlier "Questions" panel that had gone dead (linked to a 404). Selecting it hands
off entirely to `Learning::startConversation()` (called from `renderLearningView`) instead of
building a front/back/hint deck:

1. Takes **up to 10** cards from the current selection (no mode-type filter — expressions are
   included).
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
