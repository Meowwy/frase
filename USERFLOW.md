# USERFLOW

The functional spec for how vocabulary moves through Frase: what the learner types, what it
becomes, what is stored, and how it is practiced. It describes the current design. Terminology
follows `CONTEXT.md`; schema, AI-call and endpoint detail live in the relevant `docs/*.md` file
and aren't repeated here.

The app divides into three modules without residue: **CAPTURE**, **ORGANIZE**, **LEARN**.

## CAPTURE

The learner types a **Term** — the thing they want to learn — and, optionally, a **Context** — the
sentence or situation they met it in. The Term is kept **exactly as typed, with only typos fixed**:
`kostade` stays `kostade` on the card, and a pasted sentence stays a sentence. Every Term becomes
the same kind of card, however long or idiomatic it is. Context fixes the sense but never
contributes anything to the vocabulary: `collateral` captured with the context `collateral damage`
must not add *damage* to the base.

Capture does not produce a card. It produces a **proposal** in **staging**, and returns
immediately.

### What CALL 1 does

At capture, CALL 1:

- detects the **language**, from the learner's own target/native languages — and, when the Term
  is a real word in more than one of them (*bad* in English and Swedish), says which others it
  could be, so staging can ask first;
- fixes the Term's typos, and nothing else;
- extracts the Term's **base words**, each reduced to its **lemma**, tagged with its **part of
  speech**, with a native translation and any **grammatical attributes** that language and part
  of speech carry (see "Part of speech and grammatical attributes" below). Articles are never
  extracted, nor is a word that occurs in the Term only inside one of its fixed expressions —
  *tycka om* is learnt as a whole, not as *tycka* + *om*;
- extracts up to three **fixed expressions** from the Term, of exactly two kinds: a unit with a
  meaning of its own that its words don't add up to (*make up* to invent, *take off*, *tycka om*,
  *på grund av*), or a grammatical frame used in a set shape with gaps (*either … or …*, *inte bara
  … utan också*). Reflexive verbs (*lära sig*), literal verb + particle pairs and ordinary
  combinations (*make a decision*, *heavy rain*) are not fixed expressions. Each comes in its
  canonical form, `…` marking a gap and a placeholder marking what it takes (*tycka om [någon]*,
  translated *to like [someone]*). A Term that is itself a fixed expression is returned as one;
- for a single-word Term captured **without a Context** that has two or more common senses
  (*run* the verb or noun, *bank* money or riverside), offers up to four **senses**, each with its
  part of speech, a short gloss and a translation.

Words and fixed expressions come from the Term only, never from the Context.

At B1 and above, prepositions, pronouns, conjunctions and determiners are not worth a base word
and are dropped from the proposal, even if that leaves no word at all. An A1–A2 learner keeps them.

CALL 2 does not run yet. It runs only once the proposal is **approved**, so no content is ever
generated for something the learner discards.

### Staging

Staging holds every proposal — asynchronous, cross-language, outside the vocabulary until the
learner acts on it. Nothing picks a language up front: it is detected, and staging is where a wrong
detection gets corrected. The browser extension writes into staging the same way the web form does.

**The fast path** is one feed, not a separate queue, listed in the order terms were captured:
capture clears the form, notes the term was added and bumps a persistent count badge, and a
skeleton row appears in the staging list, resolving into the real proposal once CALL 1 returns.
If CALL 1 fails or takes more than a few minutes, the row shows as failed, with **Try again** and
a bin to delete it.

A proposal shows the Term, in its own block, with the detected (editable) language; below its word
and fixed-expression chips sits the Context, which can be added, edited or cleared on any proposal. Changing the language or the Context
**re-runs CALL 1**, since the words were extracted, translated and tagged for the old language and
sense.

#### Words

Each extracted word is a chip labelled with its **part of speech** and, where the language defines
one, its **display form** — a Swedish chip reads *"ett hus"*, not bare *"hus"*, and *"komm|a -er"*,
not bare *"komma"*. The chips run in the order the Term spells the words. Every chip falls into
exactly one of three groups:

- **Already present** — the learner already has this lemma **in the same part of speech** in their
  vocabulary base. Shown in place with a thin green border and no strike control, since there is
  nothing to decide. Linked to the new card on approval. *Run*
  the verb and *run* the noun are different base words, not duplicates of each other.
- **Known** — the learner struck this word before. Shown aside, labelled *known*, never linked.
  Tapping it **un-knows** it and it becomes a new chip again.
- **New** — strikeable. Created and linked on approval unless struck.

**Striking** a new chip records it as a **known word**, remembered per language by lemma and part
of speech, so that word — in any inflected form, since the match is on the lemma — is never
proposed again. Striking **never rewrites the Term**: `hur mycket kostar det` with `hur` struck is
still a card for the whole phrase, just linked to fewer base words.

There is no rule on how many base words a card may have. A card can link none or many, so no
proposal can get stuck in staging.

#### Fixed expressions

Fixed expressions are chips of their own, in their canonical form. One already in the learner's
**expression base** has the same green border, is not strikeable, and is linked on approval. A new one can be
struck; unlike a word, a struck fixed expression is not remembered — the strike holds for this
proposal only.

#### Language

When CALL 1 found the Term at home in more than one of the learner's languages, staging asks
**which language is this?** before anything else, and the proposal shows no words, fixed
expressions or senses yet — all of them depend on the language. Picking one pins it, exactly as
correcting a wrong detection does, and re-runs CALL 1 in that language alone; only then may senses
follow.

#### Senses

When CALL 1 offered senses, staging shows them as a **sense picker** and Approve is disabled until
one is picked. Picking a sense writes it into the proposal's Context and re-runs CALL 1, which now
has a Context and extracts everything in the chosen sense. The sense is kept as the card's Context,
so regenerating the card later stays in it.

#### Related cards and Merge

Each proposal shows its **related cards**: the learner's existing cards that share at least one of
its base words, most shared words first, at most five so a common word can't flood the panel. A
single-word card whose word appears in the new Term is flagged *made redundant by this card* and
listed first — it is the one the learner most likely wants to replace.

Any related card can be switched to **merge** into the new one. Nothing happens to it until approval; then it is
removed, its wordbox memberships move to the new card, and its review progress and note are
dropped — the new card starts fresh, so the learner actually reviews the longer Term they just
saved. **Discarding the proposal removes nothing.**

#### An identical Term

A proposal whose Term matches an existing card is flagged as such and is not approvable as it
stands. The learner can **regenerate** that card, **discard** the proposal, or **merge** the old
card into the new one. Two ways make it approvable:

- giving the proposal a **Context** of its own — the way to hold *run* (verb) and *run* (noun) as
  separate cards;
- marking every identical card for merge — the way to replace a card.

Owning a base word of a Term never counts as already having that Term — the base and the card list
are different questions, and the already-present group answers the base one.

#### Approve and Discard

**Approve** writes the card, links its base words and fixed expressions, merges away the marked
cards, and is the point CALL 2 finally runs. The learner stays on staging: the proposal collapses
to one line at once and, when the card is written, becomes a link to it. It is disabled until the proposal is analysed, a
language is picked if the language picker was offered, a sense is picked if senses were offered, and the identical-Term rule is satisfied — never by how
many words it has. **Discard** removes the proposal immediately and nothing is kept — no discard
history, no draft state. The row collapses to a one-line *discarded* note with **Capture again**,
which captures the same raw input and Context afresh, as a new proposal at the end of the list.

## ORGANIZE

### Cards

A card is about its **whole Term**; nothing inside it is privileged. CALL 2 writes three fields for
it, all about the Term as typed:

- a **translation** — a natural equivalent of the Term, inflection included, never word by word
  (left out for a native-language card);
- a **definition** — what the Term means or, for a whole utterance, when you would say it;
- an **example sentence** with the Term in it — for a Term that is itself a sentence, a short
  two-line exchange with the Term as one line.

The card detail page shows the card's base words and fixed expressions beside its content.

### The vocabulary base

One entry per **lemma and part of speech** per language — the **base word**: the lemma, its part
of speech, its native translation (set once, at proposal time, never revised), any grammatical
attributes that part of speech carries, and its **last recall**. It holds only words the learner
is learning — new or wanted ones, never known ones. The base carries no sense finer than part of
speech, no schedule and no generated content; sense lives on cards. Its jobs are deduplication and
coverage — telling the learner what they've already met, and which words appear across many cards
without being owned by any single card's review.

**Part of speech is part of a base word's identity, not a revisable fact about it.** *Run* the verb
and *run* the noun are two different base words, not one row that gets overwritten — they mean
completely different things, so treating them as duplicates would lose one of them. Everything
else CALL 1 fixes at proposal time (translation, attributes) is never revised either.

One Term always yields exactly one card: a sentence is not split into several cards.
`hur mycket kostar det` yields one card plus base words for its words (`kosta`, not `kostar`; at
B1 and above the pronoun `det` is dropped); `It is not my cup of tea` yields one card and the fixed
expression *not my cup of tea*, and no base word for *cup* or *tea*, which occur only inside it.

### Part of speech and grammatical attributes

Every base word carries a **part of speech** (noun, verb, adjective, …). Some languages define
extra **grammatical attributes** for some parts of speech — a fact the app looks up from that
language's own guideline, not something hardcoded per feature:

- **English**: part of speech only. Nothing else needed for now.
- **Swedish**: nouns carry a **gender** — *common* or *neuter* — displayed as the *en*/*ett*
  article. A Swedish noun's canonical **display form** is the article plus the lemma (*"ett hus"*,
  *"en bil"*), and that display form is what the learner sees and is expected to learn — wherever
  a Swedish noun's lemma is shown (staging chips, the vocabulary base, Words mode, Refresher), it
  is shown with its article, not bare. Swedish **verbs** are shown in **dictionary form**: the
  infinitive with a bar marking off the ending inflection replaces, then the present-tense ending
  (*"komm|a -er"*, *"tal|a -ar"*, *"bo -r"*, irregulars written out — *"var|a är"*). Unlike the
  noun article this cannot be computed from the lemma and an attribute value — it differs per verb
  — so it is settled once, when the word is proposed, and stored alongside the lemma.

  Both the gender and the dictionary form come from a downloaded dictionary (the **lexicon**)
  wherever it knows the word, so they are deterministic rather than the model's guess; the model
  only fills in words the dictionary lacks, and picks the sense when the dictionary lists the same
  word twice (*ett plan* / *en plan*).

  A dictionary form is never part of a base word's identity: *komma* is one entry whether or not
  one was stored for it, and like the translation it is written once and never revised.

Adding a third language means adding its own guideline, not changing this file or the schema —
see `docs/ai-integration.md` "Language guidelines" for where that lives and what it can define.

### The expression base

The learner's fixed expressions, per language, beside the vocabulary base: the canonical form, a
translation (set once, never revised), its last recall, and the cards whose Terms contain it. It
has a tab of its own on the vocabulary base page. Fixed expressions are stored and shown only —
they have no practice surface of their own.

### Where translations come from

CALL 1 returns each extracted word's and fixed expression's translation directly. Whether that is
served by the model itself, a dictionary lookup, or an API behind the same seam is an
implementation choice, not part of this spec.

## LEARN

**SRS lives on cards only.** A card is cleared when its **Term** is produced — as a whole, or,
uniquely in Words mode, one base word at a time. Clearing a card stamps **last recall** on every
base word and fixed expression linked to it. Every other word-level answer only ever stamps a base
word's last recall, and only on a correct answer; it never touches a card's schedule.

Every learning mode serves every card, except that Words mode skips cards with no base words.

- **Translation** — the first mode the builder offers, and the classic Anki-style review. Front is
  the card's translation (its definition, for a native-language card, which has no translation),
  back is the Term, hint is the example sentence with the Term blanked out. The learner flips the
  card and grades themselves Wrong or Correct.
- **Sentences / Sentences-write / Definitions / Conversation** — the target they elicit is always
  the whole Term.
- **Words** — pulls the **individual base words** of due cards (not the cards themselves),
  already-present ones included, into one shuffled, per-word session capped at **15 words**; a due
  card enters only if all of its base words fit under that cap. Front is the base word's own
  translation, back is its **lemma** in its **display form** (never the inflected form the Term uses —
  for a Swedish noun the back is *"ett hus"*, not *"hus"*, and for a Swedish verb *"komm|a -er"*),
  hint is the parent card's context. **Part of speech is shown alongside the word on both front
  and back**, since two base words can share a lemma and differ only by part of speech. A card
  clears once every one of its base words has been answered correctly within the session; each
  correct word also stamps that word's own last recall independently of whether the card clears.
- **Refresher** — free-form, unscheduled practice over the whole vocabulary base, ordered by
  **staleness** (how long since last recall). Front/back/part-of-speech are the same as Words; a
  correct answer stamps that word's last recall and nothing else — no schedule, and it never clears
  a card. It is not a learning mode: no session scope.

## Out of scope

- **Migrating existing cards.** Existing data is expendable; a fresh start on deploy is
  acceptable.
- **Practice built on fixed expressions** — no mode, no Refresher, no Gap-fill use.
- **Remembering struck fixed expressions** as known.
- **The hide-a-word review mode** (a base word of a multi-word Term hidden mid-sentence). It would
  need the Term's own spelling of each word, which is no longer stored.
- **Downloaded dictionaries for languages other than Swedish.** The plan is one per supported
  language with the AI as fallback; only Swedish has one today. Which tool supplies
  lemmatization, part-of-speech tagging and translation is an optimisation behind the same seam.
- **Visual design.** This spec settles interaction and data shape only; implementation ships in
  the current app's existing visual style.
