# Frammenti

Unscheduled practice in LEARN over the vocabulary base and expression base. It deals **batches of
5 fragments**. A fragment is one short, AI-generated sentence or phrase in the target language that
tests one base word or fixed expression. It replaced Refresher (lone flashcards over the base) and
Words mode (the same thing inside a session). Neither ever put a word into a new context, and both
only tested recall of the base form. Meeting a word in fresh contexts and *producing* it is what
makes it stick, and the vocabulary and expression bases, stored by lemma with no sense attached, are
the right raw material for that.

Frammenti **never touches a card's schedule** and never clears a card. Code: `App\Models\Frammenti`
(progress rules and batch selection), `FrammentiController`, `AI::generateFragments()`,
`resources/views/frammenti/index.blade.php`.

## Tiers and fragment types

| Tier | Type | The learner… |
|---|---|---|
| I | **Ia** | sees a fragment with a gap and picks the missing item from 3 options, in display form |
| I | **Ib** | sees a fragment with the item highlighted and picks its translation from 3 options |
| II | **II** (cloze) | types the missing item, inflected as the fragment needs, with the fragment's native translation and the item's part of speech as the cue, but not the lemma |
| III | **III** (full write) | sees the native fragment, writes it in the target language, then self-grades against the correct one |

**Tier I is disabled for now.** Every item starts at tier II and is never demoted below it, so
nothing is dealt as Ia or Ib. Its code is kept, so it can come back without rewriting it: the
dealing (a tier I slot is Ia or Ib at random), its distractors, the prompt rules, the client
rendering and the rest/recall rules. To switch it back on, set `Frammenti::FIRST_TIER` to 1 and
the `frammenti_tier` column default to 1, which needs a migration.

A discontinuous fixed expression (*inte bara … utan också*) gets one gap per part.

**Tier II is checked on the client** against the fragment's `accepted` forms, since fragments are
not stored and there is nothing on the server to check against. Case, extra spaces and surrounding
punctuation never count. A near miss gets one "check your spelling" retry and the second check is
final. A near miss means Levenshtein distance 1, or equal once diacritics are stripped.

**Tier III is self-graded** (**I had it** / **I didn't**). Grading a free sentence fairly needs
judgement an AI judge would get wrong often enough to annoy, and the learner is grading their own
practice anyway. The line under the buttons reminds them the shown fragment is one possible version
and they're judging whether they used the highlighted item correctly. The tested item is wrapped in
`[[ ]]` in both the target fragment and the native one, so the learner knows which word the sentence
is about, and their attempt is highlighted wherever it contains the item's exact text. Besides the
item, a tier III fragment uses only the learner's other tier III (and mastered) items and function
words, so the learner is never asked to write a word they haven't yet produced in a cloze.

## Progress

Four columns on **both** `base_words` and `fixed_expressions`: `frammenti_tier` (2, 3, and 4 =
mastered; default 2, with 1 valid but unreachable while tier I is off), `frammenti_correct_streak`
and `frammenti_wrong_streak` (default 0), and `frammenti_rest_until` (null = ready). With these
defaults, every item, existing or newly approved, starts at tier II and ready, and CAPTURE needed
no change.

There are two axes. **Tier** decides *how* an item is tested and **rest** decides *when*. The rules
live in `Frammenti::answer()` and run against the item's **stored** tier, never a tier sent by the
client. The constants (`FIRST_TIER`, `STREAK`, `REST_HOURS`, `MASTERED_REST_DAYS`) are meant to be
tuned.

- **Correct below mastered**: the correct streak goes up and the wrong streak resets. Two in a row
  promote the item (3 → 4 is mastered). It then rests by the tier it was answered at: 4 h at I, 1 day
  at II, 3 days at III.
- **Correct while mastered**: it rests 7, 14, 30 and then 60 days each time after that.
- **Wrong below mastered**: the wrong streak goes up, the correct streak resets and the rest is
  cleared, so the item can come back in the very next batch. Two in a row demote it, never below
  `FIRST_TIER` (II for now).
- **Wrong while mastered**: back to tier III, both streaks reset, rest cleared. One slip is enough
  to bring a forgotten word back into practice.
- **Last recall**: only a correct answer at tier II or III (mastered items are dealt as III) stamps
  `last_recalled_at`. Last recall means "I produced it", and tier I only asks for recognition.

**Rest is a priority, never a gate.** Resting items are dealt when there aren't enough ready ones,
so Frammenti is always playable and the learner is never left with nothing to do.

## Batch selection (`Frammenti::deal()`)

It runs in PHP, before the AI call, for one target language:

1. **Not open yet**: Frammenti opens for a language once its base holds at least 5 nouns and 5
   verbs (`MIN_NOUNS_AND_VERBS`, ignoring rest). Below that the page shows what's missing with a
   link to capture and no Start button, the batch endpoint answers 422, and no AI call is made.
2. Ready items (`rest_until` null or past) are those not in the request's `exclude` list.
3. Each of the 5 slots takes a random tier among those that still have ready items (mastered counts
   as III), then a random ready item at that tier. An item is never dealt twice in one batch.
4. **Top-up**: slots the ready pool can't fill take the resting items that wake soonest, each at its
   own tier. In a language too small for that, the excluded items fill whatever is still empty, so
   a batch always has 5 fragments.
5. **Tier I distractors** (dormant while tier I is off): below B1 (A1, A2), PHP takes 2 random items from the learner's own base
   with the same part of speech (2 fixed expressions for a fixed expression), as display forms for
   Ia or stored translations for Ib. The AI writes only the shortfall. At B1 and above, the AI
   writes both, as confusables (near-synonyms, same-root words, false friends). The correct option
   is always the stored display form or translation, added by PHP, and the options are shuffled.

## Generation (`AI::generateFragments()`)

One strict-schema chat call per batch. Its input is one line per slot (type, the item's display or
canonical form, part of speech, translation, base-chosen distractors, how many more to write), plus
the target and native language, the CEFR level and, below B1, a sample of up to 80 of the learner's
lemmas and, when a slot is III, a sample of up to 80 of their tier III and mastered items. It returns, per slot, `fragment` (with `___` gaps for Ia/II, or the item wrapped in `[[ ]]`
for Ib/III), `translation` (the whole fragment in the native language), `distractors` and, for II,
`accepted`, a list of acceptable answers per gap. The prompt rules and their reasons are in
[ai-integration](ai-integration.md) "Frammenti".

The batch is validated before it's returned: the slot count, gaps or a highlight as the type needs,
the exact number of distractors, and one non-empty `accepted` list per gap. A failure is `null`, the
endpoint answers 503, and the page offers **Try again**. The client never renders a broken fragment.

**Fragments are not stored.** A batch goes to the client as JSON and lives only there. There's
nothing to replay, and a stored fragment would be a second copy of content nobody reads twice.

## Endpoints and page

- `GET /frammenti`: the page. It takes an optional `language_id` and otherwise opens on
  `User::currentSaveLanguage()`. The switcher row lists **target languages only**, never the native
  one even for a learner who collects native-language vocabulary, since "translating" your own
  language into itself is meaningless. It has no wordboxes.
- `POST /frammenti/batch`, `{language_id, exclude: [{type, id}]}`: the 5 fragments. `type` is
  `base_word` or `fixed_expression`. The language must be one of the learner's target languages,
  or the response is 403. Below 5 nouns and 5 verbs it answers 422.
- `POST /frammenti/results`, `{results: [{type, id, result}]}`: applies the progress rules and
  returns `[{type, id, tier_before, tier_after}]` for the recap. Items load through
  `Auth::user()->baseWords()` / `fixedExpressions()`, so an id the learner doesn't own is silently
  skipped, as on `/saveLearning`. Each item counts at most once per request.

**Client flow** (jQuery, inline in the view). The page opens on a **Start** panel below the language
switcher. Nothing is generated until the learner has picked a language and pressed Start, so a
learner who meant another language never pays for a batch they won't play. The switcher is hidden
once play starts, because following one of its links mid-batch would drop the answers given so
far. The first batch loads behind a loading line. As soon
as a batch is shown, the next one is prefetched with the current items excluded. Answers are posted
**once per batch**, when it ends or, with the answers so far, on **Save and quit**. The recap shows
right/wrong per fragment and ↑/↓ for tier changes. **Next batch** uses the prefetched batch, or
fetches again if the prefetch failed. **Enter** always takes the step on screen, so a batch can be
played from the keyboard: Check (or Show answer at tier III, where Shift+Enter breaks a line), then
Next, and on the recap Next batch. Picking an option and self-grading stay on the buttons.

- **Prefetch staleness is accepted.** The next batch is chosen before the current batch's results
  are saved. Excluding the current items means no item is dealt twice in a row, and other items'
  readiness being a few minutes out of date doesn't matter.
- **The client is trusted with results**, since it checks tier II and self-grades tier III. That is
  the same trust model as `/saveLearning`, and fine for a learner's own practice data.

**Entry points**: **Frammenti** in the nav's LEARN group (after Learn), the **Frammenti** button on
`/base` (same `language_id`), and **Play Frammenti** on the session-complete screen, in the finished
session's language when `session('learning_filter')` holds one.
