# Frase

A language-learning app: learners save words and phrases as **cards** and get AI-generated content
for them, while the new words inside those cards land in their **vocabulary base**, the
inventory of the words they are learning. Cards are reviewed on a spaced-repetition schedule, across up to
five target languages plus optionally their own native one.

This file is the project's **glossary only** — the canonical word for each concept, and the words
not to use for it. It deliberately holds no implementation detail and no rationale: the "why"
behind every feature lives in `docs/*.md` (see the map in `CLAUDE.md`), which stays the single
record of it. Each cluster below points at the doc that details it.

> **This repo has no `docs/adr/`, and none should be created.** Decisions, the alternatives ruled
> out and the failures that forced them are written into the `docs/*.md` file for the area they
> constrain, inline with the feature — see `docs/ai-integration.md`'s prompt-design rules for what
> that looks like. `CLAUDE.md` makes those files the project's only record of why, so a separate
> ADR folder would split it. Record a decision where the feature is documented, and add a resolved
> *term* here. Full consumer rules: `docs/agents/domain.md`.

## Language

### Modules

The app divides into three modules **without residue**: every feature, screen and workflow belongs
to exactly one. Module names are written in caps, so CAPTURE the module is never mistaken for
capture the act. See [docs/features-overview.md](docs/features-overview.md).

**CAPTURE**:
The module that gets new vocabulary into the app — what the learner types, what the AI makes of it,
and staging. It ends the moment a proposal is approved.
_Avoid_: input, intake, import, adding

**ORGANIZE**:
The module that holds saved vocabulary and makes it findable and viewable — cards, the vocabulary
base, wordboxes, search.
_Avoid_: manage, browse, library, storage

**LEARN**:
The module that practices saved vocabulary — learning modes, the SRS, Frammenti and conversation
practice.
_Avoid_ (as the module's name): Review, Study, Practice

### Vocabulary items

Cross-cutting: these are the things all three modules act on.
See [docs/cards.md](docs/cards.md), [docs/ai-integration.md](docs/ai-integration.md).

**Card**:
One saved vocabulary item in one language, with its generated content and its own review schedule.
_Avoid_: entry, item, vocab; **flashcard** (that is a card being *presented* for review, not the
thing that is stored)

**Term**:
The thing on a card the learner is memorising — exactly what they gave at capture, with only typos
fixed: a lone inflected word stays inflected, a sentence stays a sentence. Every card is the same
kind whatever its Term, every generated field on the card is about the whole Term, and its words are
what reach the vocabulary base.
_Avoid_: target, answer, keyword, subject

**Context**:
The learner's own sentence or situation, given at capture so the term is captured in the sense they
met it in, and kept so the card can be regenerated in that same sense. It can be added or edited in
staging, which re-reads the proposal. It fixes the sense; it never reaches the vocabulary base.
_Avoid_: source, example, usage (those are the card's generated content, not the learner's input)

### Languages

Cross-cutting: every module is scoped by language.
See [docs/multi-language.md](docs/multi-language.md).

**Target language**:
A language the learner is learning, each with its own vocabulary, vocabulary base, wordboxes and
proficiency level. Up to five.
_Avoid_: learning language, foreign language, studied language

**Native language**:
The learner's own language — the one translations and definitions are written in.
_Avoid_: first language, mother tongue, L1, home language

**Native-language vocabulary**:
The opt-in for collecting cards in the learner's own native language, generated monolingually. It
does not count against the five-language cap.
_Avoid_: monolingual mode, self-language

**Hidden language**:
A language the learner has cards in but is no longer learning. Derived from what they have saved,
never a stored flag.
_Avoid_: archived, inactive, disabled language

**Proficiency level**:
The learner's CEFR level (A1–C2) for one target language. It caps how difficult generated content
may be — never how long it is — and governs which function words are too basic to be worth a base
word.
_Avoid_: difficulty, level (bare — that means the SRS level), grade, ability

### CAPTURE

See [docs/cards.md](docs/cards.md), [docs/browser-extension.md](docs/browser-extension.md).

**Capture**:
Turning a term the learner typed or pasted into a **proposal** in staging, with the AI detecting the
language and extracting the Term's words. It returns immediately and creates no card.
_Avoid_: add, create, import, save (a card); and never let it imply a card now exists

**Staging**:
Where proposals wait for the learner's approval — asynchronous, cross-language, and outside their
vocabulary. Nothing reaches the vocabulary or the vocabulary base until it is approved.
_Avoid_: inbox, queue, drafts, pending, review (that belongs to LEARN)

**Proposal**:
One captured Term awaiting approval in staging, together with everything the AI proposes for it: the
Term with its typos fixed, its base words, its fixed expressions and, for an ambiguous lone word,
its senses — or, for a Term at home in several of the learner's languages, only the languages to
pick from.
_Avoid_: draft, candidate, suggestion, staged card (it is more than a card)

**Language picker**:
The choice staging asks for when a captured Term is a real word or phrase in more than one of the
learner's languages (*bad* in English and Swedish). It comes before anything else: the picked
language is pinned and CALL 1 reads the Term again in it, so senses, words and fixed expressions all
follow. Until one is picked the proposal can't be approved.
_Avoid_: language detection (that is CALL 1's own guess), language question

**Sense picker**:
The choice staging asks for when a lone word was captured without a Context and has several common
senses (*run* the verb or noun, *bank* money or riverside). Each **sense** shows its part of speech,
a short gloss and a translation; the picked one becomes the proposal's Context. Until one is picked
the proposal can't be approved.
_Avoid_: meaning picker, disambiguation, definition

**Approve**:
The learner's act of accepting a proposal, which writes the card, links its base words and fixed
expressions. It is the only
way anything enters the vocabulary; **discard** is the other branch and leaves nothing behind.
_Avoid_: accept, confirm, publish, commit; decline and reject (the word is discard)

**Strike**:
Marking a proposed new word as a known word, so it never becomes a base word, or a proposed fixed
expression so this proposal doesn't save it. It governs what enters the vocabulary and expression
bases only — it never rewrites the Term.
_Avoid_: exclude, skip, drop, delete, unlink

**Known word**:
A word the learner struck, remembered per language by lemma and part of speech so that no inflected
form of it is ever proposed again. Staging shows it aside, labelled *known*; tapping it **un-knows**
it and the chip becomes proposable again.
_Avoid_: ignored word, blacklist, excluded word, struck word

**Related cards**:
The learner's existing cards that share a base word with a proposal, shown on it in staging.
_Avoid_: similar cards, synonyms, linked cards (those are the manual links in ORGANIZE)

**Covered**:
A proposal whose every base word and fixed expression is already on one existing card. Staging
refuses it, since it would add nothing to the vocabulary base.
_Avoid_: redundant, not needed, merge (there is no merging)

**Regenerate**:
Replacing an existing card's generated content while its review progress, note and links stay as
they are. Capturing a term already saved offers this; otherwise an identical Term is approved only
with a Context of its own.
_Avoid_: refresh, re-create, update

### ORGANIZE — the vocabulary base

See [docs/cards.md](docs/cards.md).

**Vocabulary base**:
The learner's inventory of the words they are learning in one language — new or wanted words, never
known ones — one entry per lemma **and part of speech**, independent of any card. Its jobs are
deduplication and coverage. Never shortened to *vocabulary*, which means their cards.
_Avoid_: wordlist, lexicon, word bank, dictionary, vocabulary (bare) — *lexicon* is the
downloaded reference dictionary below, which belongs to no learner

**Base word**:
One entry in the vocabulary base: a lemma, its **part of speech**, its native translation, its
**grammatical attributes** (if its language and part of speech carry any), and when it was last
recalled. Two base words may share a lemma when they differ in part of speech — *run* the verb and
*run* the noun are different base words, not one. It carries no sense finer than part of speech, no
card schedule and no generated content — those live on cards — only its Frammenti progress (tier
and rest).
_Avoid_: focus word, headword, root, stem, vocabulary item

**Lemma**:
The canonical spelling a base word is stored under, which is what makes deduplication work —
*obfuscate* and *obfuscated* are one base word (of the same part of speech).
_Avoid_: base form, canonical form, root, stem; dictionary form (that is how a base word is
written — below)

**Part of speech**:
A base word's grammatical category (noun, verb, adjective, …), fixed at the point CALL 1 extracts
it and never revised afterward. It is part of a base word's identity, not a property of it — see
Base word above.
_Avoid_: word class, category (bare)

**Grammatical attributes**:
The extra grammatical facts one language's guideline defines for one part of speech — Swedish
nouns carry a *gender* (`common`/`neuter`, displayed as the *en*/*ett* article); most
part-of-speech/language pairs carry none. Defined per language guideline (below), never hardcoded
per-language into the schema.
_Avoid_: properties, metadata, attributes (bare — too generic outside this context)

**Dictionary form**:
A base word written the way that language's dictionaries write it, for the parts of speech whose
language has such a convention — a Swedish verb is *komm|a -er*, not bare *komma*. Unlike a
grammatical attribute it cannot be derived from the lemma, so the whole string is stored on the
base word — taken from the lexicon where it knows the verb, from CALL 1 otherwise; it is set once
and never revised, and it is not part of the dedup key. Which parts of speech have one is declared
per language guideline (below).
_Avoid_: conjugation, inflection, suffix, stem

**Language guideline**:
The per-language file (`resources/language-guidelines/`) that tells CALL 1 which parts of speech a
language uses, which grammatical attributes apply to which part of speech and their valid values,
how to display them (e.g. Swedish `gender` → *en*/*ett*), and which parts of speech are written in
dictionary form. One file per supported language; absent for a language, CALL 1 still tags part of
speech but proposes no attributes and no dictionary form.
_Avoid_: language config, grammar rules (bare)

**Lexicon**:
A downloaded reference dictionary (`lexicon_entries`; Swedish only, imported from SALDO) whose
grammatical attributes and dictionary forms win over CALL 1's answer wherever it knows the word.
Shared by every learner — it is not anyone's vocabulary base.
_Avoid_: dictionary (bare), word list

**Display form**:
The one string every screen shows for a base word, built from its lemma plus whatever its language
guideline adds — *ett hus* for a Swedish neuter noun, *komm|a -er* for a Swedish verb, bare *hus*
for a language with no such rule. It is what the learner is expected to produce, so staging chips,
the vocabulary base and Frammenti all show it and never the bare lemma.
_Avoid_: label, rendered form

**Fixed expression**:
A multi-word unit learnt as a whole, of exactly two kinds: one with a **meaning of its own** that
its words don't add up to — a phrasal or particle verb (*make up* to invent, *take off*, *tycka
om*), an idiom or a fixed unit (*på grund av*) — or a **grammatical frame** used in a set shape with
gaps (*either … or …*, *inte bara … utan också*). Stored in its canonical form, `…` marking a gap and a bracketed placeholder marking a slot it
takes (*tycka om [någon]*).
A pattern whose words swap freely for others of their kind is not one: a reflexive verb (*lära sig*,
like *vrida sig*), a verb + particle that keeps its literal meaning, an ordinary combination (*make
a decision*).
_Avoid_: idiom, collocation, phrase

**Expression base**:
The learner's inventory of fixed expressions in one language, alongside the vocabulary base. Like
it, it has no card schedule, only Frammenti progress; its entries are linked to the cards whose Terms contain them. *Expression*
on its own always means this, never a kind of card.
_Avoid_: phrasebook, idiom list

**Last recall**:
The datetime a base word or fixed expression was last produced correctly — by clearing a card that
contains it, or at tier II or III in Frammenti. It is set only on a correct answer, and it schedules
nothing.
_Avoid_: last studied, last seen, reviewed at, due date

### ORGANIZE — collections

See [docs/wordboxes-themes-tags.md](docs/wordboxes-themes-tags.md),
[docs/search-and-linking.md](docs/search-and-linking.md).

**Wordbox**:
A user-created, named collection of cards in one language — the primary way vocabulary is
organised, and the only one to extend.
_Avoid_: deck, set, folder, collection, list, box

**General vocabulary**:
The cards in a language that are in no wordbox. A way of selecting cards, not a place they live.
_Avoid_: uncategorised, inbox, default wordbox

**Theme**:
A legacy, flatter category, at most one per card. Still live because existing cards and screens
depend on it, not because it is the pattern to follow.
_Avoid_: category, topic, tag

**Tag**:
Dormant schema with no UI behind it. Not a feature — never use the word for grouping vocabulary;
that is a wordbox.

### LEARN — reviewing

See [docs/learning-flow.md](docs/learning-flow.md).

**Level**:
A card's spaced-repetition box. Answering correctly raises it and pushes the next review further
out; answering wrong resets it.
_Avoid_: box, stage, streak, score, difficulty

**Due**:
A card whose next review date has arrived. Also the default session scope: due cards only.
_Avoid_: pending, scheduled, ready

**Cram**:
The session scope that serves every card in the selection regardless of schedule.
_Avoid_: practice, review all, free review

**Cleared**:
What a card becomes once its whole Term has been produced (Translation, Definitions, Conversation).
It advances the card's level. Nothing word-level clears a card.
_Avoid_: passed, completed, answered, correct

**Learning mode**:
How a session presents its cards — Translation, Definitions, or Conversation.
_Avoid_: exercise, game, activity, drill

**Translation**:
The learning mode that shows a card's translation (its definition, for a native-language card) and
elicits the Term — the classic Anki-style review, and the first mode the builder offers.
_Avoid_: reverse mode, recall mode, flashcards

### LEARN — Frammenti

See [docs/frammenti.md](docs/frammenti.md).

**Frammenti**:
Unscheduled practice over the vocabulary base and expression base, in batches of fragments. Not a
learning mode, and it never clears a card.
_Avoid_: snippets, drill, practice mode

**Fragment**:
One sentence or phrase that tests one base word or fixed expression at one tier. What it tests gets
no term of its own: write "the base word or fixed expression a fragment tests".
_Avoid_: snippet, exercise, question, item

**Tier**:
How a fragment tests its word (I recognition, II cloze, III full write), and how far a base word or
fixed expression has got.
_Avoid_: level (that is a card's), stage, difficulty

**Mastered**:
A base word or fixed expression past tier III, still checked at long rests.
_Avoid_: known (that is a struck word), learned

**Rest**:
How long a base word or fixed expression sits out of Frammenti after a correct answer. A priority,
never a gate.
_Avoid_: due, cooldown

### LEARN — conversation practice

See [docs/conversation-challenge.md](docs/conversation-challenge.md),
[docs/conversation-voice.md](docs/conversation-voice.md),
[docs/conversation-game.md](docs/conversation-game.md),
[docs/learning-flow.md](docs/learning-flow.md).

**Conversation Challenge**:
Free-form practice chat that is not tied to any particular vocabulary, in a text, voice or game
variant.
_Avoid_: chat, roleplay, conversation practice (bare)

**Conversation mode**:
The learning mode in which due cards are reviewed through a live chat — a Term used correctly in
conversation counts as a correct review. Distinct from Conversation Challenge, which reviews
nothing.
_Avoid_: SRS chat, vocabulary chat

**Gap-fill**:
A short generated story for one wordbox that works in that wordbox's own Terms, each replaced by a
blank to fill. See [docs/gap-fill.md](docs/gap-fill.md).
_Avoid_: cloze, exercise, quiz, fill in the blanks
