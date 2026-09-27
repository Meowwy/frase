# USERFLOW

The locked functional spec for Frase's vocabulary-base redesign: what the learner types, what it
becomes, what is stored, and how it is practiced. Design is settled here — nothing in this file is
still being decided, and implementation tickets can be cut straight from it. Terminology follows
`CONTEXT.md`; schema, AI-call and endpoint detail live in the relevant `docs/*.md` file and aren't
repeated here.

This file describes the **redesign only**. Everything the redesign leaves untouched — the
lexical/expression axis, the two-call pipeline, whole-sentence-to-reusable-frame normalisation,
context-as-sense-fixer, CEFR-driven difficulty — already ships and is documented in
`docs/ai-integration.md`; it is not re-litigated here.

The app divides into three modules without residue: **CAPTURE**, **ORGANIZE**, **LEARN**.

## CAPTURE

The learner types **Term** — the thing they want to learn — and, optionally, **Context** — the
sentence or situation they met it in. Context fixes the sense and may seed the anchor phrase below,
but never contributes a word to the vocabulary base: `collateral` captured with the context
`collateral damage` must not add *damage* to the base.

Capture no longer produces a card. It produces a **proposal** in **staging**, and returns
immediately.

### What CALL 1 does now

At capture, CALL 1:

- detects the **language**, from the learner's own target/native languages;
- settles the **card shape** (word / phrase / expression) and the canonical form of the Term, as
  today;
- extracts the Term's **lexical words**, each reduced to its **lemma**, tagged with its **part of
  speech**, together with a native translation for each and any **grammatical attributes** that
  language and part of speech carry (see "Part of speech and grammatical attributes" below);
- for a **lone-word Term**, proposes an **anchor phrase** — pulled from the Context when the
  Context already contains the Term in a natural phrase, otherwise invented.

Two filters narrow which extracted words are actually suggested as base words:

- **Proficiency** — at B1 and above, prepositions, pronouns and other very basic function words
  are not suggested (it never drops a word card's own Term).
- **Already present** — a lemma the learner already has **in the same part of speech**, or an
  expression already held, is not suggested again, but the learner is told it is already there.
  *Run* the verb and *run* the noun are different vocabulary items, not duplicates of each other.

CALL 2 does not run yet. It runs only once the proposal is **approved**, so no content is ever
generated for something the learner discards.

### Staging

Staging holds every proposal — asynchronous, cross-language, outside the vocabulary until the
learner acts on it. Because staging exists, there is no longer a language switcher / save-
destination picker on the dashboard: the language is detected, not chosen up front, and staging is
where a wrong detection gets corrected. The browser extension writes into staging the same way the
web form does.

A proposal shows:

- the proposed card — the Term, in its own block, visually separate from anything strikeable, plus
  its shape tag and the detected (editable) language;
- the proposed base words as a tray of chips, one per extracted lemma, each labeled with its
  **part of speech** and, where the language defines one, its **display form** — a Swedish chip
  reads *"ett hus"*, not bare *"hus"*;
- for a lone word, the proposed anchor phrase, editable, with **Replace** (regenerate) and
  **Clear** — clearing is reversible, leaving a "+ Add one" state rather than deleting the block
  outright.

**Striking** removes one chip so that word never becomes a base word. It is the only per-word
control, and it **never rewrites the Term** — `hur mycket kostar det` with `hur` struck is still a
card for the whole phrase, just linked to three base words instead of four. A word or phrase card
must keep at least one base word, so the control disables itself the moment striking would leave
zero (an expression may end with none); it never fails after the fact. Word and phrase cards are
also capped at **5** base words — a Term whose lexical words exceed that must be struck down before
it can be approved.

A chip for a word already in the base carries an **already-present** notice; tapping it expands
which phrases/expressions the word is already used in. Owning the base word never blocks capturing
this new card — the learner decides for themselves whether a card of its own is worth it.

**Approve** writes the card and its base-word links — disabled until the min-base-word rule above
is satisfied — and is the point CALL 2 finally runs. **Discard** removes the proposal immediately,
with an undo toast for a few seconds; once it expires nothing is kept — no discard history, no
draft state.

**The fast path** is one feed, not a separate queue: capture shows an instant toast and bumps a
persistent count badge, and a skeleton card appears at the top of the staging list, resolving into
the real proposal once CALL 1 returns.

## ORGANIZE

### The vocabulary base

One entry per **lemma and part of speech** per language — the **base word**: the lemma, its part
of speech, its native translation (set once, at proposal time, never revised), any grammatical
attributes that part of speech carries, and its **last recall**. The base carries no sense finer
than part of speech, no schedule and no generated content; sense lives on cards. Its jobs are
deduplication and coverage — telling the learner what they've already met, and which words appear
across many phrases without being owned by any single card's review.

**Part of speech is part of a base word's identity, not a revisable fact about it.** *Run* the verb
and *run* the noun are two different base words, not one row that gets overwritten — they mean
completely different things, so treating them as duplicates would lose one of them. Everything
else CALL 1 fixes at proposal time (translation, attributes) follows the same never-revised rule
translation already had.

### Part of speech and grammatical attributes

Every base word carries a **part of speech** (noun, verb, adjective, …). Some languages define
extra **grammatical attributes** for some parts of speech — a fact the app looks up from that
language's own guideline, not something hardcoded per feature:

- **English**: part of speech only. Nothing else needed for now.
- **Swedish**: nouns carry a **gender** — *common* or *neuter* — displayed as the *en*/*ett*
  article. A Swedish noun's canonical **display form** is the article plus the lemma (*"ett hus"*,
  *"en bil"*), and that display form is what the learner sees and is expected to learn — wherever
  a Swedish noun's lemma is shown (staging chips, the vocabulary base, Words mode, Refresher), it
  is shown with its article, not bare.

Adding a third language means adding its own guideline, not changing this file or the schema —
see `docs/ai-integration.md` "Language guidelines" for where that lives and what it can define.

A card's Term reaches the base by way of its lexical words; its **anchor phrase** never does — only
the Term does. One Term always yields exactly one card, plus base entries for its lexical words: a
sentence is not split into several cards. `hur mycket kostar det` yields one phrase card plus base
words for `hur`/`mycket`/`kosta`/`det`; `It is not my cup of tea` yields one expression card
(`not my cup of tea`) plus base words `cup`/`tea` — the words inside an expression do enter the
base, subject to the same two filters CALL 1 applies at capture.

A card is about its **whole Term**; nothing inside it is privileged. There is no focus word and no
focus/non-focus split — a word of the Term is either linked as a base word or it isn't, and
striking at staging time is the only thing that decides which.

How many base words a card may link:

- **word** — exactly one, its own lemma;
- **phrase** — at least one, capped at **5**;
- **expression** — zero or more.

### The anchor phrase

A lone word is not replaced by a phrase card built around it — it keeps its own card and gains a
phrase as a **property**: word cards only, nullable, the Term's occurrence marked in `[brackets]`
inside it, carrying its own translation. Its other words never enter the base. Re-suggesting one
later is a regenerate.

### The duplicate check

Narrows to the card's own Term alone. Owning a base word of a Term never counts as already having
that Term as a card — the base and the card list are two different questions, and staging answers
the base one explicitly with the already-present notice above.

### Where the base word's translation comes from

CALL 1 returns each extracted word's translation directly, in lemma form. Whether that is served
by the model itself, a dictionary lookup, or an API behind the same seam is an implementation
choice, not part of this spec.

## LEARN

**SRS lives on cards only.** A card is cleared when its **Term** is produced — as a whole, or,
uniquely in Words mode, one base word at a time. Every other word-level answer only ever stamps a
base word's **last recall**, and only on a correct answer; it never touches a card's schedule.

- **Sentences / Sentences-write / Definitions / Conversation** — unchanged mechanically, except
  that the target they elicit is now always the whole Term (there is no more focus word to bracket
  or ask for separately). A correct answer clears the card and stamps last-recall on every base
  word linked to it.
- **Words** — pulls the **individual base words** of due cards (not the cards themselves) into one
  shuffled, per-word session capped at **15 words**; a due card enters only if all of its base
  words fit under that cap. Front is the base word's own translation, back is its **lemma** in its
  **display form** (never the inflected surface form — Words always tests the lemma; for a Swedish
  noun the back is *"ett hus"*, not *"hus"*), hint is the parent card's context. **Part of speech is
  shown alongside the word on both front and back**, since two base words can share a lemma and
  differ only by part of speech. A card clears once every one of its base words has been answered
  correctly within the session; each correct word also stamps that word's own last recall
  independently of whether the card clears. Cards with zero base words (an expression whose words
  were all filtered out) don't enter the Words-mode due pool — they can only clear through the
  other modes.
- **Refresher** — free-form, unscheduled practice over the whole vocabulary base, ordered by
  **staleness** (how long since last recall). Front/back/part-of-speech are the same as Words; a
  correct answer stamps that word's last recall and nothing else — no schedule, and it never clears
  a card. It is not a learning mode: no session scope.
- **Anchor phrase** is never shown during review, for now — a staging/card-detail artifact only.

## Consequences for screens this redesign touches but does not itself design

- **The save-destination picker and its capture-target session state are retired** — staging
  detects the language, so the dashboard no longer needs a language/wordbox picker for new
  captures. This changes the dashboard and the nav; the replacement is an implementation decision.
- **The vocabulary base needs a page of its own** in ORGANIZE — nothing here specifies its layout,
  beyond that each entry shows its part of speech and display form (e.g. *"ett hus"*, not bare
  *"hus"*).
- **The CAPTURE / ORGANIZE / LEARN division gives the nav a shape it has to grow into** — today's
  nav predates the module split.
- **The card detail and edit pages** lose the three example fragments and `<x-phrase-suggestions>`,
  and gain the anchor phrase editor. Schema side: see `docs/cards.md`.

## Out of scope

- **Migrating existing production cards.** Existing data is expendable; a fresh start on deploy is
  acceptable.
- **The hide-a-word review mode** (a base word of a phrase Term hidden mid-sentence). The
  card/base-word link's surface form is built to support it, but the mode's own design is a future
  effort.
- **Which tool supplies lemmatization, part-of-speech tagging and translation** — the AI, a
  dictionary API, or a bundled wordlist. CALL 1 covers every supported language either way;
  swapping in something cheaper for specific languages is an optimisation behind the same seam.
- **Visual design.** This spec settles interaction and data shape only; a separate design project
  supplies the eventual look. Implementation ships against the current app's existing visual style.
