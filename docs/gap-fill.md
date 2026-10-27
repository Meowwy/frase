# Gap-Fill Exercises

A per-wordbox generated exercise: a short AI-written story over the base words ticked in one
wordbox, each word replaced by a numbered blank the learner fills **in the form its sentence
needs** — the inflection is the exercise, which is why a wordbox holds base words rather than
cards (see [wordboxes-themes-tags](wordboxes-themes-tags.md)).

## Model & schema (`gap_fill_exercises`)

`GapFillExercise` (`app/Models/GapFillExercise.php`): `wordbox_id`, `base_word_ids` (`array`
cast — the words ticked for it), `theme_preference` (nullable, free text passed by the user),
`title`, `text_with_gaps`, `correct_answers` (`array` cast, see "Checking answers"), `status`
(`pending` \| `processing` \| `completed` \| `failed`). Belongs to `Wordbox`.
`Wordbox::gapFillExercises()` (has-many) and `Wordbox::latestGapFillExercise()`
(`hasOne(...)->latestOfMany()`).

## Generation flow

1. The wordbox page's gap-fill card lists the box's words as a checklist, the first 30 ticked,
   with a theme field. It submits to `GET /wordbox/{wordbox}/gapfill/generate`
   (`GapFillExerciseController@store`), which checks `$this->authorize('update', $wordbox)`
   (`WordboxPolicy` — the wordbox must belong to the current user, see [overview](overview.md)
   "Authorization") and validates `base_word_ids`: required, **at most 30** (as many as one short
   story can carry) and every one of them in the wordbox. It then creates a `GapFillExercise`
   row with `status: pending` and dispatches **`GenerateGapFillJob`** onto the queue — this is
   the one AI-backed feature in the app that's genuinely asynchronous rather than synchronous
   (contrast card capture and every chat feature, which all call OpenAI inline). Redirects
   immediately to `gap-fill.show`. `show`/`status`/`destroy` all check `GapFillExercisePolicy`
   the same way (`view` for the first two, `delete` for the third), scoped through the
   exercise's `wordbox->user_id`.
2. `GenerateGapFillJob::handle()`: sets `status: processing`, loads the exercise's
   `base_word_ids` through `$wordbox->baseWords()` — so a word since removed from the box or
   deleted from the base drops out — and builds one item per word: `base_word_id`, `lemma`,
   `display_form`, `part_of_speech`, `grammar` (its attributes) and `translation`. With the
   wordbox's language and the user's CEFR level for it (`User::levelForLanguage`) it calls
   `AI::generateTextWithGaps()` (see [ai-integration](ai-integration.md) "Gap-fill"). On success
   it stores `title`, `text_with_gaps`, `correct_answers`, `status: completed`; with no words
   left, a null AI result or any thrown exception, `status: failed` (logged via `Log::error`).

`GapFillExerciseController@status` (`GET /gap-fill/{exercise}/status`) is a small JSON polling
endpoint (`{status, url}`) the show view hits while `status` is still `pending`/`processing`.
**Requires a running queue worker** (`php artisan queue:work`, `QUEUE_CONNECTION=database`) — with
no worker running, the job never executes and the page polls forever.

## Viewing / retrying (`GapFillExerciseController@show`)

`GET /gap-fill/{exercise}` renders `gap-fill.show`, passing the exercise, each gap's base word
(`$words`, keyed by id — a word deleted from the base since is simply missing) and
`$exercise->wordbox->gapFillExercises()` ordered oldest-first, so the view can show a history list
of every exercise generated for that wordbox alongside the current one.

`DELETE /gap-fill/{exercise}` (`@destroy`) removes an exercise and redirects back to the wordbox.

## The word bank

**Words to use** shows each gap's word as the learner learnt it — its display form and
translation — never the form the gap needs: putting it in that form is the exercise. A click puts
the bare lemma into the focused (or first empty) gap, to inflect there. Hovering a gap shows its
word's part of speech and display form.

## Checking answers

Entirely client-side, no request per check. `correct_answers` is
`[index => {phrase, accepted, base_word_id}]` (reshaped from the AI's `answers` array in
`AI::generateTextWithGaps` itself) and is embedded into the page with each gap's lemma and display
form. Typed answers are matched by `<x-answer-check>`, the same matching as Frammenti's tier I
(case, extra spaces and surrounding punctuation never count). Each gap comes out:

- **correct** — any of its `accepted` forms;
- **right word, wrong form** — its bare lemma or display form, when neither is accepted;
- **almost** — a near miss of an accepted form (one character or one accent off);
- **wrong** — anything else.

A summary line under the buttons counts the correct gaps and names the other two cases. Checking
can be repeated freely; nothing is graded or stored.
