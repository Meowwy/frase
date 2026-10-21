# Frammenti

**Daily active-vocabulary drill** in LEARN over the vocabulary base and expression base, and the
app's daily core. Frase exists to grow the words a learner can *produce*, not only recognise.
Frammenti puts their own base words and fixed expressions into fresh AI-generated contexts and
makes them produce them. It deals **batches of 5 fragments**; a fragment is one short AI-written
sentence or task that tests one base word or fixed expression. Each item has a **level** on a
simple Anki-style schedule, and a **session** plays the items that are due.

Frammenti **never touches a card's schedule** and never clears a card. Clearing a card still stamps
last recall on its base words, but never moves their Frammenti schedule: a Term is produced as a
whole, which is weak evidence about each word, and coupling two schedules makes both harder to
reason about. Code: `App\Models\Frammenti` (schedule, plan, dealing, streak),
`App\Models\FrammentiSession`, `FrammentiController`, `AI::generateFragments()`,
`AI::evaluateSentence()`, `resources/views/frammenti/index.blade.php`.

## Tiers

| Tier | Levels | Name (UI) | The learner… | Graded by |
|---|---|---|---|---|
| I | 0–2 | Fill the gap | types the missing item into a cloze, with the fragment's native translation and the item's part of speech as the cue | the client, against `accepted` |
| II | 3–5 | Translate | sees the native fragment, writes it in the target language, reveals the answer | self: **I had it** / **I didn't** |
| III | 6–8 | Your sentence | sees the item's native translation and part of speech and a short native-language task ("Tell your friend she should be reading more books"), and writes their own sentence | AI evaluation, with a self-grade fallback |

The tier is never stored: `Frammenti::tier()` reads it off the level, so an item moves to a harder
exercise as it climbs and back to the gap when a miss resets it. Grading is binary everywhere:
right or wrong. All three tiers are production, so every correct answer stamps `last_recalled_at`
("I produced it").

**Tier I** has one gap, whatever the item: a base word with its article (*en skola*) is one gap.
Only a fixed expression whose parts stand apart (*inte bara … utan också*) gets one gap per part.
It is checked on the client, since fragments are not stored. Case, extra spaces and surrounding
punctuation never count, and a near miss (Levenshtein distance 1, or equal without diacritics) gets
one "check your spelling" retry; a correct retry counts as correct. An answer in the gap's
`synonym_answers` (a form of one of the item's [synonym links](cards.md#synonym-links) that also
fits) is right but not the item: the gap is cleared with "That's a synonym — give another way",
nothing is graded or posted, and it can happen any number of times without using up the spelling
retry.

**Tier II** is self-graded against the revealed fragment, with the tested item wrapped in `[[ ]]` and
highlighted, and the learner's attempt highlighted wherever it contains the item's exact text. The
line under the buttons says the shown fragment is one possible version and they are judging the
highlighted item. For a beginner, besides the item it uses only words the learner has proven, so
they are never asked to write a word they haven't produced yet.

**Tier III** is the top of the ladder: no sentence to copy, only a situation, so the learner builds
the sentence themselves. **Evaluate** sends it to `AI::evaluateSentence()`, which says whether the
**item** is used in a correct form in a sense that fits its translation. Mistakes elsewhere don't
fail it, since that would grade grammar the item isn't about, but they come back fixed in
`corrected` with a short `note`, so nothing goes uncorrected. The page shows the verdict, the
learner's sentence and the corrected one, the note, and the generated `answer` as "one way to say
it". **I pass** reveals the answer and counts as wrong, with no AI call. If evaluation fails (503),
the page falls back to tier II's reveal and self-grade. The evaluation is told the item's synonyms
and says `used_synonym` when the sentence used one in the item's place; when that comes with
`correct` false, the page shows "You used a synonym (…); say it another way", clears the sentence
and lets them try again, ungraded. A sentence that uses the item correctly passes even if a synonym
appears too.

## The schedule

Every base word and fixed expression has `frammenti_level` (0–8, default 0) and `frammenti_due_on`
(a date; null until first answered). An item is **due** when `due_on` is null or ≤ today. Due dates
are calendar dates in the user's timezone (`User::today()`), so "due today" means the same thing
all day. The rules live in `Frammenti::answer()` and always run on the item's **stored** state:

- **Correct**: level + 1 (capped at 8, `MAX_LEVEL`), due in **2^(level − 1)** days on the new level:
  1, 2, 4 … 128. Level 8 keeps coming back every 128 days.
- **Wrong**: back to **level 0, due today**, so it is dealt again in a later batch of the session
  and climbs from 0 once it's right.
- **Not due** (filler): an **early answer**, no schedule change; a correct one still stamps last
  recall.

It is the cards' scheme (a level that climbs on a correct answer and resets on a miss) and replaced
a tier + doubling interval + miss streak schedule with promotion thresholds, per-CEFR repetitions
owed within the day and a daily intake of new items. That ran a precise plan but was hard to
follow and to trust; a level the learner can read off is enough. A correct answer always moves
`due_on` past today, so a word answered right never comes back the same day, and three quick
answers can't push a fresh word out three times. A word nobody has answered yet is just a level-0
due item: there is no separate intake of new words.

**Active words** are the base words and fixed expressions at tier II and up (level ≥ 3): the ones
the learner can produce, which is the app's mission made literal. They show on the dashboard, the
start panel and the recap.

## Sessions

**Start** plans and stores a session (`Frammenti::startSession()`): **⌈due ÷ 5⌉ batches, capped at
10** (`MAX_BATCHES`). What doesn't fit stays due for the next session. The start panel shows the plan
before anything is generated: "14 due → 3 batches · ~7 min", the minutes being batches × 5 ×
`SECONDS_PER_FRAGMENT` (25). **The planned count never changes** once started, so the learner can
trust the number they signed up for.

The plan lives on `frammenti_sessions`: `user_id`, `language_id`, `date` (the user's local date),
`planned_batches`, `batches_done` and `finished_at`. There is **at most one unfinished session** per
language (`Frammenti::session()`), and coming back **continues** it. When the last planned batch is
done, `finished_at` is set and the session recap is shown. A day may hold **several sessions**:
while words are still due (misses, or what the cap left out), the recap offers **Start another
session**. With nothing due, nothing is planned: "Nothing due, come back tomorrow".

### Dealing (`Frammenti::deal()`)

It runs in PHP before the AI call and fills 5 slots:

1. **Due items, at random.** No priority order: every due item is equally owed, and a random pick
   mixes tiers and parts of speech.
2. **Filler** when fewer than 5 are due: the not-due items closest to due, as early answers.

The request sends the batch on screen (`on_screen`), and **its items are never dealt**: an item
appears at most once per batch, and a miss on screen comes back in a later batch rather than in the
prefetched one, so it isn't retrieved straight after being seen. **Linked synonyms are never dealt
together**: the slots are filled in the order above, and once an item is in, its synonyms are
skipped for this batch, due or not (pairs only, so a synonym's synonym can still come). An open
language has at least 10 items, so 5 are left unless skipped synonyms leave fewer, which only makes
a shorter batch.

Each slot carries the item's `synonyms` (`[{form, translation}]`, empty when none) for the AI call
and the page; never the synonyms' own level or schedule.

**Not open yet**: Frammenti opens for a language once its base holds at least 5 nouns and 5 verbs
(`MIN_NOUNS_AND_VERBS`). Below that the page shows what's missing, the batch endpoint answers 422,
and no AI call is made.

## Generation (`AI::generateFragments()`)

One strict-schema call per batch. Its input is one line per slot (the item's display or canonical
form, part of speech, translation and any linked synonyms), the target and native language, the
CEFR level and, for a beginner, their own words. Each slot is its own schema property (`slot_1`…)
whose shape is its type's: I returns `fragment` with `___` gaps, a plain `translation`, `accepted`
(acceptable answers per gap) and, for an item with synonyms, `synonym_answers` (the synonyms' forms
that would also fill each gap, filled with empty lists in PHP for the rest); II returns `fragment`
with the item in `[[ ]]` and a plain `translation`; III returns a native `task` and a
target-language `answer` with the item in `[[ ]]`.

**Context words are a beginner's proven words.** At A1/A2, or with no level set, the call gets up to
80 random items at level 2 or above (lemmas for base words), one list for all three tiers: besides
the item, a fragment uses only those and words too basic to collect, so it has exactly one unknown.
From B1 there is no list and the level alone caps the vocabulary.

The batch is validated before it's returned: for I exactly one gap (one or more for a fixed
expression) with a non-empty `accepted` list each and a `synonym_answers` list each (which may be
empty), `[[ ]]` in II's fragment and III's answer, a
non-empty translation or task. Any `[[ ]]` in a translation or task is stripped. One invalid
fragment fails the whole batch: the endpoint answers 503 and the page offers **Try again**.

**Fragments are not stored.** A batch lives only on the client: there's nothing to replay, and a
stored fragment would be a second copy of content nobody reads twice. Prompt rules and their reasons
are in [ai-integration](ai-integration.md) "Frammenti".

## Endpoints and page

- `GET /frammenti`: the page, on an optional `language_id`, otherwise `User::currentSaveLanguage()`.
  The switcher lists **target languages only** (`Frammenti::languages()`), never the native one even
  for a learner who collects native-language vocabulary. The start panel shows the state — "Batch 2
  of 4" with **Continue**, the plan with **Start**, or "Nothing due, come back tomorrow" with no
  button — plus Active words and the streak.
- `POST /frammenti/batch`, `{language_id, on_screen: [{type, id}]}`: plans a session when none is
  open, then deals 5 fragments. Returns `{fragments, planned_batches, batches_done}`. A non-target
  language is 403; below 5 nouns and 5 verbs, or with nothing due and no open session, it's 422; a
  failed AI call is 503.
- `POST /frammenti/answer`, `{type, id, result, last}`: applies one answer (`Frammenti::answer()`).
  `last` marks a batch's last fragment, which counts the batch done and, at the planned count,
  finishes the session. Returns `{level_before, level_after, batches_done, recap}`; `recap`
  (`{streak, active_words, due}`) only on the answer that finished the session, `due` saying whether
  another can start. The client is trusted with right/wrong, as on `/saveLearning`; an item the
  learner doesn't own is a 404.
- `POST /frammenti/evaluate`, `{type, id, task, sentence}`: tier III evaluation,
  `{correct, used_synonym, corrected, note}` or 503.

**Client flow** (jQuery, inline in the view). Nothing is generated until the learner presses Start.
The switcher is hidden during play. **Each answer is posted as it's given**, one request each, in
order on one promise chain (so each lands on the state the previous one left): the schedule
updates immediately, **Quit** loses nothing, and the next batch is dealt knowing most of the current
batch's outcomes. **The prefetch starts at fragment 4 of 5**, once the earlier answers are saved.
**There's no prefetch after the session's last planned batch**, so no batch is paid for and thrown
away (quitting mid-batch can still waste one prefetched batch). Each batch ends on a recap
(right/wrong per fragment, ↑/↓ when the level changed the tier); the session's last adds the
session recap: "+k active words", promotions and demotions between tiers, the words missed and due
again (level 0 after their latest answer), the streak, and **Start another session** while anything
is due. **Enter** takes the step on screen (Check / Show answer / Evaluate, then Next, then Next
batch); self-grading and **I pass** stay on buttons, and Shift+Enter breaks a line.

## Streak, settings, entry points

**Streak** (`Frammenti::streak()`): days in a row with at least one finished session in any
language, computed from `frammenti_sessions` with no stored counter. Today doesn't break it before
it's over, and **one missed day per ISO week** is forgiven automatically; a second breaks it.

**No settings.** The batch cap and the intervals are constants: a broad audience needs a legible
product with few knobs, and those are ours to tune.

**Entry points**: the nav's LEARN group (tooltip "Daily active-vocabulary drill"), the **Today's
Frammenti** section on the dashboard (between the capture form and the card reviews: streak, Active
words, one row per target language with its state and Start / Continue), the **Frammenti** button
on `/base`, and **Play Frammenti** on the session-complete screen.
