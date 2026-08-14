# Conversation Challenge — Game variant (task game with lives)

The **Game** mode of the Conversation Challenge ([conversation-challenge](conversation-challenge.md) for the base feature
and shared setup page): the AI runs a roleplay in a random scene and sets the learner a **task**
each turn (what information to convey); the learner must produce it in the target language.
Chosen via the **Mode** pill (Text | Voice | **Game** 🎯).

Game **disallows all preferences** — selecting it hides the Scene/Feedback-focus textareas (the
`.pref-block` class) and the voice-only controls on the shared setup page; only the **language**
pick and the (automatically resolved) CEFR **level** apply. This is deliberate: the scene is
meant to be a surprise the learner reacts to, not something they set up in advance.

## Constants (`ChallengeController`)

- `GAME_MAX_TURNS = 10` — turns the learner must clear to win.
- `GAME_LIVES = 3` — starting lives; **one lost per mistake**, not per turn.
- `GAME_MAX_MISTAKES_PER_TURN = 4` — defensive cap on how many mistakes one AI turn result can
  report (matches the same cap baked into the `challengeGameReply` schema description).

## Starting a game

`POST /conversation/start` with `mode=game` seeds ephemeral **`session('chat_game')`** —
`{messages, language_id, level, cache_key, current_task, survived, lives_left, mistakes,
scene_display, scene_id}` (**no preferences** stored, unlike text/voice) — and renders
`challenge/game.blade.php`, passed `maxTurns = GAME_MAX_TURNS` and `lives = GAME_LIVES`.

`POST /conversation/game/opening` (`ChallengeController@gameOpening`), fired by the view on load:

1. Draws **one random row** from the seeded **`challenge_scenes`** table
   (`ChallengeScene::inRandomOrder()->first()` — seeded by `ChallengeSceneSeeder` with 10
   everyday situations: restaurant, hotel check-in, asking directions, clothes shopping, doctor's
   visit, café, job interview, meeting a colleague, train station, renting an apartment). Its id
   is kept in `scene_id` and later stored with the result row.
2. Hands the scene's `name: description` to `AI::startChallengeGame()` (see [ai-integration](ai-integration.md)),
   which returns `{scene, reply, suggestion}` — `scene` is a **native-language** setup blurb,
   `reply` is the first in-character line (target language, spoken *to* the learner), and
   `suggestion` is the first **task**: a concise native-language instruction of what to convey,
   deliberately **never** prescribing a grammar structure or specific words to use.
3. Idempotent like the text challenge's `opening()` — a refresh returns the stored opener/task
   instead of drawing a new scene.

## Turn flow — the AI only reports, the server owns the game

`POST /conversation/game/message` (`ChallengeController@gameMessage`) calls
`AI::challengeGameReply($messages, $task, ...)`, which in **one call** returns
`{task_completed, task_feedback, mistakes[], reply, suggestion}`. Crucially, the model is **never
told about lives, scoring, or when to end the game** — it only judges the latest message and
reports what it found. Every game rule below is enforced entirely in
`ChallengeController@gameMessage` / `@collectMistakes` / `@finishGame`.

**`collectMistakes($result, $taskCompleted, $turn)`** turns one AI turn result into the list of
mistakes that cost the learner a life:

1. If the task wasn't completed, one `{type: 'task', explanation: task_feedback}` entry.
2. Every reported language mistake, **deduplicated** (lowercased `quote|explanation` fingerprint,
   so the same mistake reported twice — which can happen since the model re-evaluates each call —
   can't cost two lives), any without an `explanation` **dropped** (an unexplained mistake would
   cost a life the learner couldn't learn from), capped at `GAME_MAX_MISTAKES_PER_TURN`.

Each entry in that list costs **exactly one life**: `lives_left -= count($turnMistakes)`. Every
entry (tagged with its turn number) is also appended to `session('chat_game')['mistakes']`, so
the complete list can be shown when the game ends.

**Ending conditions**, checked in this order:

- **Lives reach 0** → immediate loss, regardless of whether the turn's `reply`/next task would
  have continued. `finishGame($chat, completed: false)`.
- Otherwise the turn is **survived** — `survived++`, the reply is appended to the transcript, and
  the next task is handed out — **even if the learner lost lives this turn**. Losing a life
  doesn't end the turn early; it's purely a running deduction.
- **`survived >= GAME_MAX_TURNS` (10)** → win. `finishGame($chat, completed: true)`.

**`finishGame()`** persists one row to **`challenge_game_results`**
(`user_id`, `language_id`, `challenge_scene_id`, `turns_survived`, `completed`) and clears
`session('chat_game')`. There is **no separate recap AI call** for the game — the mistakes
collected during play *are* the end-of-game feedback, which is why they're deliberately withheld
from the learner during play (see below) rather than shown live like the text challenge's
per-message corrections.

## View (`challenge/game.blade.php`)

Single-column thread: assistant bubbles on the left, followed by a highlighted **"🎯 Your task"**
box (native language) after each assistant line, then the learner's reply on the right followed
by a **verdict row** of two chips — ✓/✗ **Task done**, and ✓ **No mistakes** / ✗ **N mistakes** —
plus an "N/3 ♥ left" note the moment a life is lost. This per-turn indicator is deliberately
**pass/fail only**: mistake **explanations are withheld during play** and revealed only once the
game ends, so the learner isn't handed the correction mid-conversation (that would defeat the
point of a task game — see how this differs from the text challenge's live corrections).

Header shows a **♥♥♥ lives row** (spent hearts dimmed via `.heart`) and a **Turn X / 10** counter.

On end, the feedback panel shows either **"🎉 You cleared all 10 turns!"** (win) or **"Game over —
you ran out of lives"** (loss), then **"You lasted N turns."**, then a full **"Your mistakes"**
list — every collected entry, turn number + either the struck-through wrong quote or a "Task not
completed" tag, plus its explanation — followed by Play-again / Home links.

## Prompt-caching structure

`startChallengeGame`/`challengeGameReply` follow the same pattern as the other chat features: an
invariant system prefix (role/rules/level) first for cache-serving the growing transcript, a
per-game `prompt_cache_key` (`game-{userId}-{uuid}`) routing every turn to the same cache, and the
one thing that changes every turn — the current task — sent as a small **trailing** system
message so it never invalidates the cached prefix. `reasoning_effort: low` on every turn. See
[ai-integration](ai-integration.md) for the full method signature and schema.
