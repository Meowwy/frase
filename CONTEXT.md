# Frase

A language-learning app: learners save words and phrases as **cards** and get AI-generated content
for them, while every word inside those cards also lands in their **vocabulary base**, the
inventory of what they have met. Cards are reviewed on a spaced-repetition schedule, across up to
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
The module that practices saved vocabulary — learning modes, the SRS, Refresher and conversation
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
The thing on a card the learner is memorising — exactly what they gave at capture, in the card's
own spelling. Every generated field on the card is about the Term, the Term is what reaches the
vocabulary base, and every card has one whatever its shape.
_Avoid_: target, answer, keyword, subject; phrase and word (those are card shapes)

**Card shape**:
Which of the three kinds a card is — **word**, **phrase** or **expression** — decided at capture.
It governs which fields get written and how.
_Avoid_: card type, card kind, format

**Word**:
A card shape: a Term that is a single naming unit word. Only word cards may carry an anchor phrase.
_Avoid_: base word (that is a vocabulary-base entry, not a card shape)

**Phrase**:
A card shape: a naming unit of several words.
_Avoid_: collocation, multi-word term, chunk; anchor phrase (that is a property of a word card)

**Expression**:
A card shape: a ready-made utterance or utterance frame performing a communicative function —
answers "you say X when you want to…".
_Avoid_: sentence, utterance, idiom, phrase

**Naming unit**:
Something that names a concept — the defining property of a lexical term. The axis between lexical
and expression is naming unit vs. ready-made utterance, **never** word count.
_Avoid_: noun phrase, single word

**Term type**:
The binary classification stored on every card: **lexical** or **expression**. Distinct from card
shape — both word and phrase cards are lexical.
_Avoid_: card type, category, class

**Lexical**:
A term type: the card is a naming unit, so it gets a dictionary definition and a translation.

**Anchor phrase**:
A short phrase that sets a word card's Term in a natural setting and carries its own translation —
word cards only, at most one, and optional. Only the Term reaches the vocabulary base; the anchor
phrase's other words never do.
_Avoid_: phrase (that is a card shape), example, collocation, carrier phrase, context

**Context**:
The learner's own sentence or situation, given at capture so the term is captured in the sense they
met it in, and kept so the card can be regenerated in that same sense. It fixes the sense and may
seed the anchor phrase; it never reaches the vocabulary base.
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
language and settling the card shape. It returns immediately and creates no card.
_Avoid_: add, create, import, save (a card); and never let it imply a card now exists

**Staging**:
Where proposals wait for the learner's approval — asynchronous, cross-language, and outside their
vocabulary. Nothing reaches the vocabulary or the vocabulary base until it is approved.
_Avoid_: inbox, queue, drafts, pending, review (that belongs to LEARN)

**Proposal**:
One captured Term awaiting approval in staging, together with everything the AI proposes for it: the
card, its base words, and for a lone word its anchor phrase.
_Avoid_: draft, candidate, suggestion, staged card (it is more than a card)

**Approve**:
The learner's act of accepting a proposal, which writes the card and its base words. It is the only
way anything enters the vocabulary; **discard** is the other branch and leaves nothing behind.
_Avoid_: accept, confirm, publish, commit; decline and reject (the word is discard)

**Strike**:
Removing a word from a proposal so it never becomes a base word. It governs base membership only —
it never rewrites the Term.
_Avoid_: exclude, skip, drop, delete, unlink

**Regenerate**:
Replacing an existing card's generated content while its review progress, note and links stay as
they are. Capturing a term already saved offers this instead of ever making a second card.
_Avoid_: refresh, re-create, update

### ORGANIZE — the vocabulary base

See [docs/cards.md](docs/cards.md).

**Vocabulary base**:
The learner's inventory of every word they have met in one language, one entry per lemma **and
part of speech**, independent of any card. Its jobs are deduplication and coverage — never
shortened to *vocabulary*, which means their cards.
_Avoid_: wordlist, lexicon, word bank, dictionary, vocabulary (bare)

**Base word**:
One entry in the vocabulary base: a lemma, its **part of speech**, its native translation, its
**grammatical attributes** (if its language and part of speech carry any), and when it was last
recalled. Two base words may share a lemma when they differ in part of speech — *run* the verb and
*run* the noun are different base words, not one. It carries no sense finer than part of speech, no
schedule and no generated content — those live on cards.
_Avoid_: focus word, headword, root, stem, vocabulary item; word (that is a card shape)

**Lemma**:
The canonical spelling a base word is stored under, which is what makes deduplication work —
*obfuscate* and *obfuscated* are one base word (of the same part of speech).
_Avoid_: base form, dictionary form, canonical form, root, stem

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
grammatical attribute it cannot be derived from the lemma, so CALL 1 supplies the whole string and
it is stored on the base word; it is set once and never revised, and it is not part of the dedup
key. Which parts of speech have one is declared per language guideline (below).
_Avoid_: conjugation, inflection, suffix, stem

**Language guideline**:
The per-language file (`resources/language-guidelines/`) that tells CALL 1 which parts of speech a
language uses, which grammatical attributes apply to which part of speech and their valid values,
how to display them (e.g. Swedish `gender` → *en*/*ett*), and which parts of speech are written in
dictionary form. One file per supported language; absent for a language, CALL 1 still tags part of
speech but proposes no attributes and no dictionary form.
_Avoid_: language config, grammar rules (bare)

**Surface form**:
The spelling one particular Term uses for one of its base words, carried on the link between them —
*kostade* in the Term, *kosta* in the base.
_Avoid_: inflection, variant, spelling, display form

**Display form**:
The one string every screen shows for a base word, built from its lemma plus whatever its language
guideline adds — *ett hus* for a Swedish neuter noun, *komm|a -er* for a Swedish verb, bare *hus*
for a language with no such rule. It is what the learner is expected to produce, so staging chips,
the vocabulary base, Words mode and Refresher all show it and never the bare lemma.
_Avoid_: label, rendered form, surface form (that is the Term's spelling — above)

**Last recall**:
The datetime a base word was last produced correctly. It is set only on a correct answer, and it
schedules nothing.
_Avoid_: last studied, last seen, reviewed at, due date

**Staleness**:
How long ago a base word was last recalled — the only ordering Refresher uses. It is not a schedule
and never makes anything due.
_Avoid_: due, overdue, priority, decay, urgency

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
What a card becomes once its Term has been produced — as a whole (Sentences, Sentences-write,
Definitions, Conversation), or in Words mode once every one of the card's base words has been
answered correctly. Either path advances the card's level. Every other word-level answer (Refresher)
stamps a base word's last recall and never clears a card.
_Avoid_: passed, completed, answered, correct

**Learning mode**:
How a session presents its cards — Sentences, Sentences (writing), Words, Definitions, or
Conversation.
_Avoid_: exercise, game, activity, drill

**Words**:
The learning mode that elicits the individual base words of due cards, shuffled. It is scheduled and
lives inside a session, which is what separates it from Refresher — and clearing every one of a
card's base words here clears the card itself, the one word-level path that does.
_Avoid_: word mode, base practice; word (that is a card shape)

**Refresher**:
Free-form practice over the vocabulary base, ordered by staleness. It is **not** a learning mode: no
session scope, no schedule, and it never clears a card.
_Avoid_: drill, practice mode, word practice, cram (that is a session scope)

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
The learning mode in which due cards are reviewed through a live chat — a word used correctly in
conversation counts as a correct review. Distinct from Conversation Challenge, which reviews
nothing.
_Avoid_: SRS chat, vocabulary chat

**Gap-fill**:
A short generated story for one wordbox that works in that wordbox's own terms, each replaced by a
blank to fill. See [docs/gap-fill.md](docs/gap-fill.md).
_Avoid_: cloze, exercise, quiz, fill in the blanks
