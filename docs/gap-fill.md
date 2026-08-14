# Gap-Fill Exercises

A per-wordbox generated exercise: a short AI-written story that naturally works in the wordbox's
own phrases, with each occurrence replaced by a numbered blank the learner fills in.

## Model & schema (`gap_fill_exercises`)

`GapFillExercise` (`app/Models/GapFillExercise.php`): `wordbox_id`, `theme_preference` (nullable,
free text passed by the user), `title` (nullable — added later, see below), `text_with_gaps`,
`correct_answers` (`array` cast), `status` (`pending` \| `processing` \| `completed` \| `failed`).
Belongs to `Wordbox`. `Wordbox::gapFillExercises()` (has-many) and
`Wordbox::latestGapFillExercise()` (`hasOne(...)->latestOfMany()`).

## Generation flow

1. `GET /wordbox/{wordbox}/gapfill/generate` (`GapFillExerciseController@store`) checks
   `$this->authorize('update', $wordbox)` (`WordboxPolicy` — the wordbox must belong to the
   current user, see [overview](overview.md) "Authorization"), then creates a `GapFillExercise`
   row with `status: pending` and dispatches **`GenerateGapFillJob`** onto the queue — this is the
   one AI-backed feature in the app that's genuinely asynchronous rather than synchronous
   (contrast card capture and every chat feature, which all call OpenAI inline). Redirects
   immediately to `gap-fill.show`. `show`/`status`/`destroy` all check `GapFillExercisePolicy`
   the same way (`view` for the first two, `delete` for the third), scoped through the exercise's
   `wordbox->user_id`.
2. `GenerateGapFillJob::handle()`: sets `status: processing`, pulls **up to 30 random** phrases
   from the wordbox (`inRandomOrder()->limit(30)`), resolves the target language (wordbox's
   language, falling back to the user's legacy `target_language` string, then `'English'`) and
   the user's CEFR level for that language (`User::levelForLanguage`), then calls
   `AI::generateTextWithGaps()` (see [ai-integration](ai-integration.md)). On success, stores `title`,
   `text_with_gaps`, `correct_answers`, `status: completed`; on a null/failed AI result or any
   thrown exception, `status: failed` (logged via `Log::error`).
3. `AI::generateTextWithGaps` doesn't require the story to use each phrase **verbatim** — the
   model may adapt a phrase's inflection/conjugation/form so the story reads naturally, as long
   as the meaning/context the phrase carries as a vocabulary item is preserved. `answers[].phrase`
   is always the exact adapted text that belongs in that gap, which is what the learner is graded
   against — not the original wordbox phrase.

`GapFillExerciseController@status` (`GET /gap-fill/{exercise}/status`) is a small JSON polling
endpoint (`{status, url}`) the show view can hit while `status` is still `pending`/`processing`.
**Requires a running queue worker** (`php artisan queue:work`, `QUEUE_CONNECTION=database`) — with
no worker running, the job never executes and the page polls forever.

## Viewing / retrying (`GapFillExerciseController@show`)

`GET /gap-fill/{exercise}` renders `gap-fill.show`, passing the exercise plus
`$exercise->wordbox->gapFillExercises()` ordered oldest-first, so the view can show a history list
of every exercise generated for that wordbox alongside the current one.

`DELETE /gap-fill/{exercise}` (`@destroy`) removes an exercise and redirects back to the wordbox.

## Checking answers

Same client-side pattern as the SRS "Sentences — writing" mode (see [learning-flow](learning-flow.md)): the
learner fills numbered gap `<input>`s and checks locally, no request round-trip per check.
`correct_answers` (an id-keyed map from `AI::generateTextWithGaps`'s `answers` array, reshaped in
`AI::generateTextWithGaps` itself from `[{index, phrase}, ...]` into `[index => phrase, ...]`) is
embedded into the page for the client-side checker to compare against.
