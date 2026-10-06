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

Every mode serves every card. There is one kind of card (see [cards](cards.md)), so Sentences,
Sentences-write and Definitions no longer skip cards that used to be classed as expressions: a
whole-sentence Term's example is a two-line exchange and its definition says when you'd say it, so
both fronts still work. Only Words mode narrows the pool, to cards that have base words (below).

## Rendering a session (`Learning::renderLearningView($mode)`)

It splits on one thing: Words mode builds its deck from base words (`Learning::wordEntries()`),
every other mode from cards (`Learning::cardEntries()`). Both hand off to
`Learning::renderDeck($cards, $mode)`, which serializes the deck into the shared view — and which
Refresher also calls directly, being the same word-dealing shape without a session.

For `sentences`, `sentences_write` and `definitions`, each entry is built **per card**,
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
*all* of its base words fit under that cap — no card contributes a partial word set, so a card
with more than 15 base words would never enter the pool (no real Term has that many). Cards with **zero** base words (every word struck or filtered at capture — see
[cards](cards.md) "The vocabulary base") are excluded from this pool entirely; they can only clear
through the other modes.

The deck is keyed by **base word**, not by card/word pair: a word linked to several due cards is
dealt **once**, and the answer counts towards every one of them. That is the normal case rather than
an edge one — words running through many phrases is what the base exists to record — and dealing the
same word two or three times in one sitting would be both tedious and the wrong arithmetic. A word
already dealt for an earlier card also costs a later card nothing against the 15-word cap.

Each entry is `{id, card_ids, front, back, hint, part_of_speech, wordbox}` — `id` is the **base
word's** id here, which is what lets the view's existing deck machinery (repeat-until-correct,
record-the-first-answer) work unchanged on words instead of cards, and `card_ids` is what spreads
one answer across the cards that share the word (empty for Refresher, which clears nothing):

- **front** — the base word's own translation (from the vocabulary base);
- **back** — the base word's **lemma**, never the inflected surface form — Words always tests the
  lemma, unlike the deferred hide-a-word mode (out of scope — see `USERFLOW.md`), which is the one
  place the surface form would matter. Rendered in its **display form** where the language's
  guideline defines one — a Swedish noun's back is *"ett hus"*, not bare *"hus"*, and a Swedish
  verb's is *"komm|a -er"* — see
  [cards](cards.md) "The vocabulary base" and [ai-integration](ai-integration.md) "Language
  guidelines";
- **part_of_speech** — shown alongside the word on both front and back, not just the back. Two base
  words can share a lemma and differ only by part of speech (*run* the verb vs. *run* the noun), so
  the learner needs it to know which one is being asked about even before flipping the card. The
  view renders it in a pill **outside** the flashcard, next to the wordbox pill — one element that
  is simply visible the whole time the word is on screen, rather than a copy on each face;
- **hint** — the parent card's context (its blanked example sentence); the first such card's, for a
  word shared by several.

Answers are tracked client-side and batched into one write at session end: the deck's own
`results` array becomes `/saveLearning`'s `words` parameter, and the per-card array beside it is
**derived** from it in the view (`derivedCardResults()`). A card clears — `result: 1` — only when
every one of its base words was answered correctly *first time*, the same standard the other modes
apply to a card's own answer; a word the learner never reached (they quit early) leaves its card out
of the array entirely, so nothing is scheduled off a half-finished card. This is the second path to
"the Term is produced," word-by-word rather than as one string. Each correct word also stamps its
own last recall, independently of whether the card clears.

## Refresher

`GET /refresher` (`BaseWordController::refresher`), reached from the vocabulary-base page. Free-form,
unscheduled practice over the **whole vocabulary base** in one language — not scoped to a card, a
wordbox, or the `/setLearning` builder's due/cram split. Words are ordered by **staleness** (how long
since last recall, never-recalled first) and **not shuffled**: staleness *is* the order. Front/back
are the same shape as Words mode's own, built by the same `Learning::wordEntry()`.

A correct answer stamps that word's last recall and nothing else: no SRS level, no `next_study_at`,
and it never clears a card. That falls out of reusing `/saveLearning` rather than needing its own
endpoint — Refresher posts a `words` array and an **empty** card array, and "stamp last recall and
touch nothing else" is exactly what that endpoint then does. The view is the same flashcard page in
its word-dealing shape, with card-result derivation switched off.

It deals `BaseWordController::REFRESHER_BATCH` (30) words at a time. That is a page size, not a
session scope — Refresher is deliberately **not** a learning mode, which is what separates it from
Words. Because it shares `/completeLearning` with the real modes, it resets
`session('learning_mode')`/`more_cards_available` on the way in, so finishing a Refresher never
offers to "continue another set" of whatever mode an earlier session left behind.

## SRS algorithm

`Learning::getNextStudyDay($level, $result)`:

- `result === 1` (correct) → `next_study_at = now() + 2^(level - 1) days` — a simple doubling
  interval per level (level 1 → +1 day, level 2 → +2 days, level 3 → +4 days, …).
- otherwise (wrong) → `next_study_at = now() + 1 day`.

Persisting a result (`AjaxController@saveLearning` — the whole of what that controller is now,
`POST /saveLearning`) reads a JSON array of
`{id, result}` from the request, and per card: sets `next_study_at` via the formula above,
increments `level` on a correct result or **resets it to 1** on a wrong one, stamps
`last_studied = now()`, saves. There is no per-card ownership check here beyond `Card::find` —
the id list comes from the session-driven client the user is already looking at, not arbitrary
input.

`POST /saveLearning` takes two parallel JSON arrays, both of `{id, result}`:

- **`results`** — per **card**. Besides the schedule, a correct result stamps `last_recalled_at` on
  every base word linked to that card: producing the Term is producing all of its words.
- **`words`** — per **base word**, which Words mode and Refresher send. A correct answer stamps that
  word's own last recall; nothing here touches a card's schedule, because whether the card cleared
  is already decided in `results`. The update is scoped through `Auth::user()->baseWords()`, so an
  id the learner doesn't own is silently ignored — the same discipline the card array has.

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
