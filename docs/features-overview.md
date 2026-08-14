# Feature Overview

What a Frase user can actually do, described from their point of view — not the technical
architecture (that's the rest of `docs/`, linked from each section below). Use this file to get
oriented on the product before diving into a specific technical doc, or when you need a
plain-English answer to "does the app already do X?"

## Capturing words & phrases

The core loop: a user types (or pastes) a word, collocation, idiom, or even a whole sentence, and
the app turns it into a flashcard.

- **AI-assisted capture** (the main way): type a term on the dashboard, optionally add the
  sentence/context it was seen in so the AI captures the right sense/domain, and it comes back
  with a corrected/canonicalized term, a definition, a translation, an example sentence, and
  (for single-concept terms) three short usage-example fragments. The app automatically decides
  whether it's a **lexical** term (a word/phrase you'd define) or an **expression** (a whole thing
  you'd *say*) and tailors every field accordingly. See [ai-integration](ai-integration.md), [cards](cards.md).
- **Manual capture** (`/add`, no AI): type every field yourself. Useful when you already know
  exactly what you want on the card.
- **Browser extension**: capture a word from any webpage without visiting the site — same
  AI-assisted flow, in a popup. See [browser-extension](browser-extension.md).
- Duplicate terms (same phrase, same language) are rejected rather than creating a second card.

## Multi-language vocabulary

- Learn **up to 5 target languages** at once, each with its own vocabulary, wordboxes, and
  proficiency (CEFR) level, which steers how difficult the AI-generated content is.
- Optionally build vocabulary in your **own native language** too (e.g. a Czech speaker collecting
  Czech words/idioms) — a separate opt-in, doesn't count against the 5-language limit.
- A **save-destination picker** on the dashboard controls which language (and optionally which
  wordbox) new captures go into; it remembers your last choice.
- See [multi-language](multi-language.md).

## Vocabulary list

`/cards` — every saved term in one searchable, filterable table: filter by language, by wordbox
(or "general vocabulary" = no wordbox), by term type (lexical/expression/both), and search by
term or definition text as you type. Select multiple cards to bulk-delete or bulk-move them into
a wordbox. See [cards](cards.md).

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

- **Sentences** — see a sentence with the term blanked out, try to recall it, flip to check.
- **Sentences (writing)** — same sentence, but you type the missing word instead of flipping a
  card; forgiving about capitalization/punctuation/spelling of the surrounding text, not about the
  word itself.
- **Words** — see the translation, recall the term.
- **Definitions** — see the definition, recall the term.
- Reviews use **spaced repetition**: getting a card right pushes its next review further out
  (doubling each time), getting it wrong resets it to "review again tomorrow."
- See [learning-flow](learning-flow.md).

## Conversation practice (three ways)

- **SRS Conversation mode** (a 5th mode in the study-session builder) — a short live chat with an
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
(A1–C2, drives how difficult AI-generated content is), your native language, the
native-language-vocabulary opt-in, your default save language, and reorder your wordboxes. See
[multi-language](multi-language.md), [auth-and-users](auth-and-users.md).
