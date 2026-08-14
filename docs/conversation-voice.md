# Conversation Challenge — Voice variant (Realtime API)

The **voice** mode of the Conversation Challenge ([conversation-challenge](conversation-challenge.md) for the base
feature and the shared setup page): a live **speech-to-speech** chat on OpenAI's **Realtime
API**. Chosen via the **Mode** pill (Text | Voice | Game) on `challenge/setup.blade.php`, which
reveals a set of voice-only controls (all toggled together via the shared `.voice-only` class):

- **Turn-taking**: **Auto** (`vad`) or **Push-to-talk** (`ptt`).
- **Voice**: a dropdown over `ChallengeController::REALTIME_VOICES` (marin/cedar/alloy/ash/
  ballad/coral/echo/sage/shimmer/verse), default `marin` (`REALTIME_VOICE`).
- **Speaking speed**: 0.75–1.15, maps to `audio.output.speed`.
- **Barge-in** checkbox ("Let me interrupt", Auto-only) → `turn_detection.interrupt_response`.

Language/scene/feedback-focus inputs are reused from the shared setup form.

## Key architectural difference from the text mode

**The browser talks directly to OpenAI over WebRTC.** There is no per-turn PHP AI call once the
session starts — the entire conversation behaviour lives in one **`instructions`** string built
server-side (`AI::realtimeInstructions`, see [ai-integration](ai-integration.md)) and handed to OpenAI when the
session is created. The partner stays **purely in character and gives NO live/verbal feedback** —
it never corrects the learner mid-chat; all feedback is deferred to the written end-of-chat
recap, because there's no PHP turn to hook a correction into. Billing is **per-minute**, not
per-token, unlike every other AI feature in the app.

## Starting a voice session

`POST /conversation/start` with `mode=voice` seeds ephemeral **`session('chat_voice')`** —
`{language_id, level, scene, feedback_focus, turn_taking, voice, speed, barge_in,
native_language}` — with **no AI call at all** at this point, and renders `challenge/voice.blade.php`.

## Endpoints

### `POST /conversation/voice/token` (`ChallengeController@voiceToken`)

Mints a short-lived **ephemeral client secret**
(`AI::mintRealtimeClientSecret` → `POST /v1/realtime/client_secrets`) carrying the whole session
config, so the real `OPENAI_SECRET` never reaches the browser — the client only ever receives the
ephemeral value.

Session config sent:

- `model`: `gpt-realtime-2.1-mini` (`REALTIME_MODEL`) — the cheaper sibling of `gpt-realtime-2.1`;
  swap if quality ever needs to trump per-minute cost.
- `instructions`: built by `AI::realtimeInstructions()`.
- `audio.input.transcription`: `gpt-4o-transcribe`, so the **learner's own speech** is shown on
  screen as text (not just the assistant's).
- `audio.input.turn_detection`: **Auto** uses `semantic_vad` with `eagerness: low` — this waits
  for the learner to actually finish a thought rather than jumping in on the first pause, which
  matters because learners pause mid-sentence far more than native speakers — plus
  `interrupt_response` from the barge-in toggle. **Push-to-talk** sends `null` (the client commits
  each turn itself via `input_audio_buffer.commit`).
- `audio.output.voice` / `audio.output.speed`: user-selected. Note the **voice cannot change once
  spoken** in a given session — pick it before connecting.

The controller's inline comment documents every Realtime session knob the app deliberately
**doesn't** set (audio format, fine VAD tuning under `server_vad`, `create_response`, noise
reduction, `max_output_tokens`, tool calling, `output_modalities`, `temperature`) and why each is
left on OpenAI's default — read that comment block in `ChallengeController::voiceToken` before
adding a new Realtime feature, since it's the single reference for what's already been considered
and rejected.

### `POST /conversation/voice/recap` (`ChallengeController@voiceRecap`)

Takes the **client-collected transcript** (`[{role, text}]`, built live in the browser from
Realtime data-channel events — see below) and calls `AI::voiceChallengeRecap()`. Because the
voice partner gives **no live feedback**, this recap is the learner's **only** feedback for the
whole session. It mirrors the SRS conversation recap's shape and returns three bullet-fragment
lists: `{did_well, corrections, vocabulary}` — strengths, clearly-wrong grammar (wrong→correct),
and better/more-natural vocabulary suggestions, all in the native language. Because the input is
a **speech-recognition transcript** rather than typed text, the prompt explicitly ignores typos,
mis-transcriptions, and pronunciation, and never comments on spelling — those would just be
transcription noise, not real learner mistakes. Then forgets `chat_voice`.

## View (`challenge/voice.blade.php`)

jQuery + WebRTC, no framework:

1. Fetches the ephemeral token from `voiceToken`.
2. `getUserMedia` for the mic, builds an `RTCPeerConnection` with the mic track attached, an
   `<audio autoplay>` element for playback, and an `oai-events` data channel.
3. Exchanges SDP with `POST https://api.openai.com/v1/realtime/calls` directly from the browser
   (no PHP in the loop for this call).
4. Renders **both** transcripts live, purely from data-channel events:
   `conversation.item.input_audio_transcription.completed` → learner line;
   `response.output_audio_transcript.delta`/`.done` → assistant line (accumulated as it streams).
   Both are also accumulated client-side into the array later posted to `voiceRecap`.
5. **Push-to-talk**: toggles `micTrack.enabled`; on release, sends
   `input_audio_buffer.commit` then `response.create`. **Auto (VAD)**: keeps the mic track
   enabled continuously; OpenAI's server-side VAD decides when a turn ends.
6. **End chat**: tears down the `RTCPeerConnection`, posts the accumulated transcript to
   `voiceRecap`, and renders the recap using the **same markup** as the text challenge's recap
   panel (green/orange/blue section headings for did-well/corrections/vocabulary).

WebRTC requires HTTPS — the local Herd `frase.test` domain qualifies, so this can be developed
against without a separate tunnel.
