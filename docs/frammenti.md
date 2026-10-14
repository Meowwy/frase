# Frammenti

**Daily active-vocabulary drill** in LEARN over the vocabulary base and expression base, and the
app's daily core. Frase exists to grow the words a learner can *produce*, not only recognise.
Frammenti puts their own base words and fixed expressions into fresh AI-generated contexts and
makes them produce them. It deals **batches of 5 fragments**; a fragment is one short AI-written
sentence or task that tests one base word or fixed expression. Each day it plans a **finite
session** per language from a **word-level schedule**, and it repeats new and missed items several
times in the same session, in different sentences, until they stick.

Frammenti **never touches a card's schedule** and never clears a card. Clearing a card still stamps
last recall on its base words, but never moves their Frammenti schedule: a Term is produced as a
whole, which is weak evidence about each word, and coupling two schedules makes both harder to
reason about. Code: `App\Models\Frammenti` (schedule, plan, dealing, streak),
`App\Models\FrammentiSession`, `FrammentiController`, `AI::generateFragments()`,
`AI::evaluateSentence()`, `resources/views/frammenti/index.blade.php`.

## Tiers

| Tier | Name (UI) | The learner… | Graded by |
|---|---|---|---|
| I | Fill the gap | types the missing item into a cloze, with the fragment's native translation and the item's part of speech as the cue | the client, against `accepted` |
| II | Translate | sees the native fragment, writes it in the target language, reveals the answer | self: **I had it** / **I didn't** |
| III | Your sentence | sees the item's native translation and part of speech and a short native-language task ("Tell your friend she should be reading more books"), and writes their own sentence | AI evaluation, with a self-grade fallback |

Grading is binary everywhere: right or wrong. All three tiers are production, so every correct
answer stamps `last_recalled_at` ("I produced it").

**Tier I** has one gap, whatever the item: a base word with its article (*en skola*) is one gap.
Only a fixed expression whose parts stand apart (*inte bara … utan också*) gets one gap per part.
It is checked on the client, since fragments are not stored. Case, extra spaces and surrounding
punctuation never count, and a near miss (Levenshtein distance 1, or equal without diacritics) gets
one "check your spelling" retry; a correct retry counts as correct.

**Tier II** is self-graded against the revealed fragment, with the tested item wrapped in `[[ ]]` and
highlighted, and the learner's attempt highlighted wherever it contains the item's exact text. The
line under the buttons says the shown fragment is one possible version and they are judging the
highlighted item. Besides the item, it uses only words the learner has proven, so they are never
asked to write a word they haven't produced yet.

**Tier III** is the top of the ladder: no sentence to copy, only a situation, so the learner builds
the sentence themselves. **Evaluate** sends it to `AI::evaluateSentence()`, which says whether the
**item** is used in a correct form in a sense that fits its translation. Mistakes elsewhere don't
fail it, since that would grade grammar the item isn't about, but they come back fixed in
`corrected` with a short `note`, so nothing goes uncorrected. The page shows the verdict, the
learner's sentence and the corrected one, the note, and the generated `answer` as "one way to say
it". **I pass** reveals the answer and counts as wrong, with no AI call. If evaluation fails (503),
the page falls back to tier II's reveal and self-grade.

## The schedule

Every base word and fixed expression has `frammenti_tier` (1–3, default 1), `frammenti_interval`
(days), `frammenti_due_on` (a date; **null = new**, never introduced) and `frammenti_miss_streak`.
Due dates are calendar dates in the user's timezone (`User::today()`), so "due today" means the same
thing all day. The rules live in `Frammenti::answer()` and always run on the item's **stored** state.
The constants (`PROMOTE_AT_DAYS`, `PROMOTED_INTERVAL`, `MAX_INTERVAL`, `MISSES_TO_DEMOTE`) are meant
to be tuned.

The schedule replaced a short, hours-based "rest" after each correct answer with random dealing.
Nothing ranked items by how much they needed practice, so it could not run a finite daily session
that keeps pressing on the words that need it most.

**Only the first attempt of the day moves the schedule.** The server classifies each answer from the
data, so the client never sends an attempt number:

- the item is **new** or **due** (`due_on` ≤ today): the answer **counts**;
- otherwise, if it **owes repetitions** in today's session: it's a **repetition**, which only touches
  the owed count;
- otherwise it's an **early answer** (Extra drill, filler): no schedule change.

A counting answer moves `due_on` past today, so later answers that day are repetitions or early
answers. Without this, three clean repetitions in one morning would double a brand-new word's
interval three times.

**Counting answers:**

- **New**, right or wrong: interval 1, due tomorrow, tier stays I. Introducing it is what counts. A
  wrong first attempt still bumps the miss streak.
- **Correct**: miss streak 0, and the interval doubles. If the doubled interval is **≥ 8 days** and
  the tier is below III, the item **moves up a tier** and restarts at **2 days**: a harder exercise
  hasn't proven itself yet. Otherwise the interval is capped at 180 days. A fresh item goes
  introduced (1) → 2 → 4 → tier II at 2 → 4 → tier III at 2, about a week per tier; tier III has no
  exit and just keeps doubling.
- **Wrong**: interval 1, due tomorrow, miss streak + 1. **Two misses in a row** at tier II or III
  **demote one tier** (never below I) and reset the streak. One slip is usually a bad day or a bad
  fragment; two in a row mean the harder exercise came too early.

**Active words** are the base words and fixed expressions at tier ≥ II: the ones the learner can
produce, which is the app's mission made literal. They show on the dashboard, the start panel and
the recap.

## The daily session

### Repetitions

A new item, or one answered wrong, must be produced again **N times in this session, each in a
fresh fragment**. N comes from the learner's CEFR level for the language (`Frammenti::REPS`): A1/A2
→ 3, B1/B2 → 2, C1/C2 → 1, and 2 with no level. Beginners need more contact with a word and its
grammar; advanced learners need less repetition and more variety. The SRS decides *which day* an
item comes back; repetitions make sure a word just met or just failed is produced several times
*today*, in different sentences — the same idea as Anki's learning steps.

- A new item owes N, or N − 1 if its first attempt was right (that attempt was one).
- A due item answered wrong owes N; answered right, none.
- A **wrong repetition doesn't count**: the owed number stays, never resets to N, so a hard word
  can't trap the learner in a loop.
- Repetitions still owed when the planned batches run out are **dropped**: every counting answer
  that owes repetitions is due tomorrow anyway.

### Spacing

- An item appears **at most once per batch**: a batch is one AI call, and every repetition must be
  a different sentence.
- A new batch never places an item **within 2 fragments of its previous appearance**, counted across
  the batch boundary (slot 5 → not slots 1–2 of the next, slot 4 → not slot 1). Retrieving a word
  right after seeing it tests short-term memory, not learning; interleaving is the point.
- **New items are introduced only while at least N planned batches remain** (counting the one being
  dealt), so they have room for their repetitions.

### Planning

The **Start** of a language's day plans and stores the session (`Frammenti::startSession()`):

- **Fragments** = due items + new items × N, where new items = `min(new items per day, new items
  available)`. New items are introduced newest capture first, nouns and verbs before other parts of
  speech.
- **Batches** = `max(⌈fragments ÷ 5⌉, N if any new items)`, capped at **10** (`MAX_BATCHES`). When
  the cap bites, new items are cut first, then the least overdue due items. What's cut rolls to
  tomorrow. The start panel shows the plan before anything is generated: "Today: 14 due · 6 new →
  4 batches · ~8 min", the minutes being batches × 5 × `SECONDS_PER_FRAGMENT` (25).
- **The planned batch count never grows** once promised, so the learner can trust the number they
  signed up for. Repetitions owed from misses push lower-priority planned items out instead (new
  items first, then the least overdue due items).

The plan lives on `frammenti_sessions`: `user_id`, `language_id`, `date` (the user's local date,
unique together), `planned_batches`, `batches_done`, `introduced` (new items introduced today, which
the daily intake counts against), `owed` (JSON `"type:id" → repetitions left`) and `finished_at`.
Coming back the same day **continues** it. When the last planned batch is done, `finished_at` is set
and the session recap is shown. A day with nothing due and nothing new plans no session.

**Extra drill.** After the session is finished, or when there was nothing to plan, the page offers
Extra drill: one batch at a time of the items closest to due (never due or new ones). They are early
answers: no schedule change, no streak, no plan, only last recall on correct answers. It exists so
Frammenti is never a dead end.

### Dealing (`Frammenti::deal()`)

It runs in PHP before the AI call and fills 5 slots in priority order:

1. **Owed repetitions**, respecting the spacing rule.
2. **Due items**, most overdue first, ties to the lower interval.
3. **New items**, while the day's intake lasts and at least N planned batches remain.
4. **Filler**: the items closest to due, as early answers. Extra drill deals only these.
5. **Top-up** in a base too small for the above: the items on screen, so a batch always has 5. This
   may break spacing, which is accepted in a tiny base.

The request sends the batch on screen, **in order** (`on_screen`). Those items are never dealt as due
or new again, only as owed repetitions, spaced from their slot. **An on-screen item whose answer
hasn't arrived yet is planned as if answered correctly** (a new one owes N − 1, a due one nothing);
if it was actually missed, its extra repetitions land in the batch after next. That is the accepted
cost of prefetching.

**Not open yet**: Frammenti opens for a language once its base holds at least 5 nouns and 5 verbs
(`MIN_NOUNS_AND_VERBS`). Below that the page shows what's missing, the batch endpoint answers 422,
and no AI call is made.

## Generation (`AI::generateFragments()`)

One strict-schema call per batch. Its input is one line per slot (the item's display or canonical
form, part of speech, translation), the target and native language, the CEFR level and the context
words its slot types need. Each slot is its own schema property (`slot_1`…) whose shape is its
type's: I returns `fragment` with `___` gaps, a plain `translation` and `accepted` (acceptable answers
per gap); II returns `fragment` with the item in `[[ ]]` and a plain `translation`; III returns a
native `task` and a target-language `answer` with the item in `[[ ]]`.

**Context words are proven words only** — `last_recalled_at` set, strongest first (tier, then
interval, then random):

- tier I below B1: up to 80 proven lemmas, so every cloze has exactly one unknown, the tested item (a
  brand-new learner has none, and the fragment is built from words too basic to collect);
- tier II at any level: up to 80 proven items, the only content words besides the item;
- tier III below B1: up to 15 proven nouns and 15 proven verbs, so the task only asks for what the
  learner can say.

The batch is validated before it's returned: for I exactly one gap (one or more for a fixed
expression) with a non-empty `accepted` list each, `[[ ]]` in II's fragment and III's answer, a
non-empty translation or task. Any `[[ ]]` in a translation or task is stripped. One invalid
fragment fails the whole batch: the endpoint answers 503 and the page offers **Try again**.

**Fragments are not stored.** A batch lives only on the client: there's nothing to replay, and a
stored fragment would be a second copy of content nobody reads twice. Prompt rules and their reasons
are in [ai-integration](ai-integration.md) "Frammenti".

## Endpoints and page

- `GET /frammenti`: the page, on an optional `language_id`, otherwise `User::currentSaveLanguage()`.
  The switcher lists **target languages only** (`Frammenti::languages()`), never the native one even
  for a learner who collects native-language vocabulary. The start panel shows today's state — the
  plan, "Batch 2 of 4" with **Continue**, "✓ Done for today" with **Extra drill**, or nothing to plan
  with **Extra drill** — plus Active words and the streak.
- `POST /frammenti/batch`, `{language_id, on_screen: [{type, id}]}`: plans today's session on first
  call, then deals 5 fragments. Returns `{fragments, drill, planned_batches, batches_done}`. A
  non-target language is 403; below 5 nouns and 5 verbs it's 422; a failed AI call is 503.
- `POST /frammenti/answer`, `{type, id, result, last}`: applies one answer (`Frammenti::answer()`).
  `last` marks a batch's last fragment, which counts the batch done and, at the planned count,
  finishes the session. Returns `{tier_before, tier_after, back_tomorrow, batches_done, recap}`;
  `recap` (`{streak, active_words}`) only on the answer that finished the session. The client is
  trusted with right/wrong, as on `/saveLearning`; an item the learner doesn't own is a 404.
- `POST /frammenti/evaluate`, `{type, id, task, sentence}`: tier III evaluation,
  `{correct, corrected, note}` or 503.

**Client flow** (jQuery, inline in the view). Nothing is generated until the learner presses Start.
The switcher is hidden during play. **Each answer is posted as it's given**, one request each, in
order on one promise chain (so each lands on the state the previous one left): the schedule
updates immediately, **Quit** loses nothing, and the next batch is dealt knowing most of the current
batch's outcomes. **The prefetch starts at fragment 4 of 5**, once the earlier answers are saved:
only a miss on 4 or 5 then lands two batches later instead of one. **There's no prefetch after the
session's last planned batch** and Extra drill is fetched on demand, so no batch is paid for and
thrown away (quitting mid-batch can still waste one prefetched batch). Each batch ends on a recap
(right/wrong per fragment, ↑/↓ for tier changes); the session's last adds the session recap:
promotions and demotions, "+k active words today", what comes back tomorrow, the streak. **Enter**
takes the step on screen (Check / Show answer / Evaluate, then Next, then Next batch); self-grading
and **I pass** stay on buttons, and Shift+Enter breaks a line.

## Streak, settings, entry points

**Streak** (`Frammenti::streak()`): days in a row with a finished *planned* session in any language,
computed from `frammenti_sessions` with no stored counter. Today doesn't break it before it's over,
and **one missed day per ISO week** is forgiven automatically; a second breaks it. Extra drill
never counts.

**Settings** (`/profile/frammenti`, linked from `/profile`): one number per target language, **new
items per day** (`language_user.frammenti_new_per_day`, default 12, 0–50). Repetitions, the batch cap
and the intervals stay constants: a broad audience needs a legible product with few knobs, and those
are ours to tune.

**Entry points**: the nav's LEARN group (tooltip "Daily active-vocabulary drill"), the **Today's
Frammenti** section on the dashboard (between the capture form and the card reviews: streak, Active
words, one row per target language with its plan and Start / Continue / Done), the **Frammenti**
button on `/base`, and **Play Frammenti** on the session-complete screen.
