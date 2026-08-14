# Conversation Challenge (free practice chat — text)

The **text** variant of a **separate**, standalone feature from the SRS Conversation mode
documented in [learning-flow](learning-flow.md): free-form conversation practice with **live per-message
corrections**, not tied to any wordbox or SRS state. Two other input/interaction variants of the
same feature exist — voice ([conversation-voice](conversation-voice.md)) and a task-based game
([conversation-game](conversation-game.md)) — chosen on one shared setup page.

## Setup page

`GET /conversation` (`ChallengeController@setup`) → `challenge/setup.blade.php`. The learner
picks a target language (pill picker, defaults to `currentSaveLanguage()`), a **Mode** pill
(**Text** | Voice | Game — see the other two docs for what those reveal/hide), reads the
instruction to write in full sentences, and can optionally fill two **≤200-char** preference
textareas: **Scene** (what the chat is about) and **Feedback focus** (a grammar/vocabulary point
to drill, e.g. "present perfect"). Text mode is the only one where both preference fields apply
directly to per-message correction, not just to the opening scene.

## Starting a chat

`POST /conversation/start` (`ChallengeController@start`, validates `language_id`, optional `scene`
/`feedback_focus` ≤200 chars, plus the mode-selection fields shared with voice/game) branches on
`mode`. For text mode it mints a per-chat `prompt_cache_key` (`chal-{userId}-{uuid}`, see
[ai-integration](ai-integration.md) "Prompt caching pattern"), seeds **ephemeral**
`session('chat_challenge')` = `{messages, struggles, language_id, level, scene, feedback_focus,
scene_display, cache_key}` (**no DB row, no target words** — this feature has nothing to do with
the card SRS), and renders `challenge/chat.blade.php` **immediately**, before any AI call — the
page shows a spinner (reusing the gap-fill loader) rather than blocking the request on the model.

The view then fires `POST /conversation/opening` (`ChallengeController@opening`) itself, which
calls `AI::startChallenge()` (see [ai-integration](ai-integration.md)) and appends the result to the session
transcript. It's **idempotent**: if `chat_challenge['messages']` is already non-empty (e.g. a
page refresh), it just returns the stored opener instead of generating a second one. The client
shows the returned `scene` in a banner **above** the chat and `reply` as the first bubble.

## Chat UI

Top-left **Home** back link; a **two-column** thread (Tailwind `grid md:grid-cols-2`, stacks on
mobile) — each learner message on the left, its **correction at the same row level** on the
right. The composer is an auto-growing `<textarea>` (Enter sends, Shift+Enter = newline),
disabled until the opening has loaded.

Each turn is a synchronous `$.post` to `POST /conversation/message`
(`ChallengeController@message`), which calls `AI::challengeReply()` — **one call** returns both
the in-character `reply` and a `correction` of the learner's just-sent message
(`{has_error, is_typo, feedback}`), to save a second round-trip. The correction renders as a
**feedback note only** — it does *not* echo the learner's sentence back — shown as a green "Looks
good" when `has_error` is false, or an orange card with `feedback` otherwise. `scene` and
`feedback_focus` steer both the reply and what gets corrected.

Server-side, `message()` appends each real mistake's `feedback` to
`session('chat_challenge')['struggles']` **unless `is_typo`** — obvious fast-typing slips are
excluded there so they never reach the end-of-chat recap, keeping it focused on genuine gaps. If
the AI call fails, the just-appended learner message is popped back off the session transcript
before returning an error, so a client retry doesn't end up duplicating it.

## Ending a chat

`#endBtn` → `POST /conversation/recap` (`ChallengeController@recap`). Calls
`AI::challengeRecap()` with the accumulated `struggles` (not the full transcript — deliberately
token-efficient), biased by `feedback_focus`, producing up to 4 native-language recommendation
bullets on what to improve/learn next — genuine grammar/preposition/vocabulary gaps only, typos
already excluded upstream. Shown below the chat; the button becomes "Back to home". The session
bucket (`chat_challenge`) is cleared, so nothing about this chat persists once the recap is shown
— there is no history of past Conversation Challenge chats to revisit later (contrast
[conversation-game](conversation-game.md), which does persist a result row per game).

## Prompt-caching structure

`AI::startChallenge`/`challengeReply` mirror the SRS conversation's caching structure — an
invariant system prefix (now also carrying the constant scene/feedback-focus text for this chat)
sits first so the growing transcript is cache-served; the per-chat `prompt_cache_key` routes every
turn to the same cache. They differ from the SRS conversation prompts by **dropping all
target-word elicitation** entirely — there's no word list to track here, just free conversation.
Turns use `reasoning_effort: low`; the recap uses the default (`medium`) since it isn't
latency-sensitive. See [ai-integration](ai-integration.md) for the full method signatures.
