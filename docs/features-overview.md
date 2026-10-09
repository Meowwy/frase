# Feature Overview

What a Frase user can actually do, described from their point of view — not the technical
architecture (that's the rest of `docs/`, linked from each section below). Use this file to get
oriented on the product before diving into a specific technical doc, or when you need a
plain-English answer to "does the app already do X?"

## Capturing words & phrases

The core loop: a user types (or pastes) a word, collocation, idiom, or even a whole sentence, and
the app turns it into a flashcard — by way of **staging**, where they get the last word on it.

- **AI-assisted capture** (the main way): type a term, optionally add the sentence/context it was
  seen in so the AI captures the right sense/domain, and press Capture. It returns **immediately**:
  the fields clear, a "✓ added" note appears beside the staging link and the Staging nav count
  goes up, and nothing else waits on the AI. You don't
  pick a language — the app works out which of your languages the term is in.
- **Staging** is where a captured term waits for you, and nothing is in your vocabulary until you
  approve it. Each proposal shows your term exactly as you typed it (only typos fixed — a lone
  inflected word or a whole sentence is kept as it is), the detected language (changeable, if it
  guessed wrong), its context (add or edit it any time and the proposal is re-read in that sense —
  a lone word with several meanings, like *run* or *bank*, asks you to pick one before it can be
  approved, and a term that is a real word in two of your languages, like *bad*, first asks which
  language you mean), and the individual words it wants to add to your vocabulary base as a tray of
  chips. **Strike** a chip and that word is remembered as **known**: it is left out now and never
  proposed again, in any form — the card still teaches the whole term either way, and a card may
  keep any number of words, none included. A word you already have stays in the tray with a green
  border and nothing to decide (it is linked to the new card automatically); known words are shown
  aside — tap one to un-know it.
- **Approve** writes the card and its words — and only then are the definition and translation
  generated, so nothing is spent on a term you throw away. You stay on staging:
  the proposal shrinks to one line, then becomes a link to the new card. **Discard** leaves
  nothing behind: the proposal shrinks to a "discarded" line with **Capture again**, which captures
  the same term and context afresh. A term the AI couldn't read — it failed, or took
  more than a few minutes — shows as failed, with **Try again** and a bin to delete it.
- **Manual capture** (`/add`, no AI): type every field yourself. Useful when you already know
  exactly what you want on the card. It skips staging entirely.
- **Browser extension**: capture a word from any webpage without visiting the site — it lands in
  the same staging feed. See [browser-extension](browser-extension.md).
- **Related cards**: each proposal lists up to 4 of your existing cards that share at least two of
  its words, or one noun, most overlap first.
- **A term that adds nothing** — all its words and expressions are already on one of your cards
  (*book* when you have *She is reading a book*) — is refused: staging says so and links that card,
  and you can only clear it.
- **Capturing a term you already have** is blocked until you give it a Context (to keep both, e.g.
  *run* the verb and *run* the noun). Staging also offers to
  **regenerate** the existing card instead — fresh translation and definition,
  while your review progress, note, words and linked cards stay as they are. Having one of a term's
  *words* in your vocabulary base is a different thing, and never stops you saving the term.

## Multi-language vocabulary

- Learn **up to 5 target languages** at once, each with its own vocabulary, vocabulary base,
  wordboxes, and proficiency (CEFR) level, which steers how difficult the AI-generated content is.
  Your level also decides which words are too basic to be worth collecting.
- Optionally build vocabulary in your **own native language** too (e.g. a Czech speaker collecting
  Czech words/idioms) — a separate opt-in, doesn't count against the 5-language limit.
- You never choose a language when capturing: it is detected, and corrected in staging if wrong.
- See [multi-language](multi-language.md).

## The vocabulary base

`/base` — every word you have met in one language, one entry per word **and part of speech**
(*run* the verb and *run* the noun are two different things to know), and your **fixed
expressions** — multi-word units you learn as a whole, like *tycka om* or *either … or …* — all in
one list, where an expression's part of speech reads *expression*. Each entry shows the word the way you are meant to
learn it — a Swedish noun with its article, *"ett hus"*, not bare *"hus"*, a Swedish verb in
dictionary form, *"komm|a -er"* — its part of speech, its translation and how many of your cards use it. Search by
word or translation as you type, or filter by part of speech (*expression* shows just the
expressions). Click a row and the panel on the right lists those cards' terms;
clicking a word or expression on a card's page brings you here with it already picked.

Fixed expressions are picked out of the terms you capture and shown in staging as their own chips
(strike one to skip it for that capture).

It is not a second review queue: it has no review schedule. Its jobs are to stop the same word being
collected twice and to show you which words run through many of your phrases without belonging to
any one card, and it is what Frammenti practises (below). See [cards](cards.md) "The vocabulary base".

## Cards list (nav: "Cards")

`/cards` — every saved term in one searchable, filterable table: filter by language, by wordbox
(or "general vocabulary" = no wordbox), and search by term or definition text as you type. Each row shows the term, its translation, its definition and
its wordbox. Select multiple cards to bulk-delete or bulk-move them into a wordbox. Opening a card
gives you **previous/next** arrows to walk that language's terms in the same order the list shows
them, without going back to the list each time. See [cards](cards.md).

## Wordboxes & themes

- **Wordboxes** are user-created named decks (e.g. "Travel", "Chapter 3") — the main way to
  organize vocabulary into study sets, reorderable by drag-and-drop.
- **Themes** are an older, simpler auto-assigned category (the AI picks one per card at capture
  time); still visible on the dashboard and usable as a filter, but wordboxes are the primary
  organizing tool for new vocabulary.
- See [wordboxes-themes-tags](wordboxes-themes-tags.md).

## Reviewing with flashcards

`/setLearning` — the study-session builder: pick a language, a scope (a specific wordbox,
"general vocabulary", or everything), due-only vs. cram (everything regardless of schedule), then
a mode:

- **Translation** (the first mode) — see the translation, recall the term, flip to check. The
  classic Anki-style review; a card in your own native language shows its definition instead.
- **Definitions** — see the definition, recall the term.
- Reviews use **spaced repetition**: getting a card right pushes its next review further out
  (doubling each time), getting it wrong resets it to "review again tomorrow."
- See [learning-flow](learning-flow.md).

## Frammenti (nav: "Frammenti")

- Unscheduled practice with your words and fixed expressions, five short AI-written sentences at a
  time, each built around one of them. Always playable, and it never changes a card's review date.
- Opens for a language once you've saved at least 5 nouns and 5 verbs in it. Pick the language (if
  you learn several) and press **Start**; Enter checks, moves on, and starts the next batch.
- Each word moves through two **tiers**: type it into a gap in the form the sentence needs, then
  write a whole sentence from your native language, built only from words you've reached that tier
  with, and mark yourself. Two right in a row move a word
  up, two wrong move it back down, and two right at the top make it **mastered**, checked again only
  every few weeks.
- A word you get right rests for a while (longer at higher tiers), so you meet the ones you struggle
  with more often. After each batch you see what you got right and which words went up or down.
- Reached from the nav, from the vocabulary base, and from the end of a review session. See
  [frammenti](frammenti.md).

## Conversation practice (three ways)

- **SRS Conversation mode** (a mode in the study-session builder) — a short live chat with an
  AI partner built around up to 10 of your due words; the partner steers the conversation toward
  situations where you'd naturally use them, without ever saying the words itself. Using a word
  correctly counts as a correct review. Ends with a short feedback recap. See [learning-flow](learning-flow.md).
- **Conversation Challenge** (nav: "Conversation") — free-form practice chat, not tied to any
  specific vocabulary. Pick a language, optionally a scene/topic and a grammar point to drill.
  Available in three modes:
  - **Text** — live corrections after every message, plus a wrap-up with what to study next. See
    [conversation-challenge](conversation-challenge.md).
  - **Voice** — a real-time spoken conversation (speak and listen, not type) with adjustable voice,
    speed, and turn-taking style; feedback comes as a written recap at the end since the partner
    doesn't interrupt to correct you mid-conversation. See [conversation-voice](conversation-voice.md).
  - **Game** — the AI drops you into a random everyday scenario (ordering food, checking into a
    hotel, …) and gives you a task each turn; you have 3 lives, lose one per mistake or missed
    task, and win by clearing 10 turns. Mistakes are revealed only at the end. See
    [conversation-game](conversation-game.md).

## Gap-fill exercises

Generate a short AI-written story per wordbox that naturally works in that wordbox's own phrases,
with each one replaced by a blank you fill in — a different way to practice the same vocabulary
in context, with a reusable "words to use" bank if you get stuck. See [gap-fill](gap-fill.md).

## Linking related cards

From a card's detail page, manually link it to another card in the same language you think of as
related (synonyms, opposites, things you associate together) — linked cards show up together on
each other's detail pages. See [cards](cards.md) "Manual card linking".

## Search

A quick term search from the nav bar (`/search`), and a separate search box on a wordbox's edit
page for finding existing cards to add to it. See [search-and-linking](search-and-linking.md).

## Profile & settings

`/profile` and `/profile/edit` — manage your target languages and their proficiency levels
(A1–C2, which drives how difficult AI-generated content is and which words are worth collecting),
your native language, the native-language-vocabulary opt-in, the language your screens open on, and
reorder your wordboxes. See [multi-language](multi-language.md),
[auth-and-users](auth-and-users.md).
