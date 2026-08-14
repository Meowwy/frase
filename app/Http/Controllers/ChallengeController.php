<?php

namespace App\Http\Controllers;

use App\Models\AI;
use App\Models\ChallengeGameResult;
use App\Models\ChallengeScene;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChallengeController extends Controller
{
    /** Realtime (speech-to-speech) model used for the voice challenge. */
    private const REALTIME_MODEL = 'gpt-realtime-2.1-mini';

    /** Default assistant voice for the voice challenge. */
    private const REALTIME_VOICE = 'marin';

    /** Selectable Realtime voices (offered on the setup page). */
    private const REALTIME_VOICES = ['marin', 'cedar', 'alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse'];

    /** Conversation Challenge Game: turns the learner must clear to win. */
    private const GAME_MAX_TURNS = 10;

    /** Conversation Challenge Game: lives the learner starts with (one lost per mistake). */
    private const GAME_LIVES = 3;

    /** Conversation Challenge Game: defensive cap on how many mistakes one turn can report. */
    private const GAME_MAX_MISTAKES_PER_TURN = 4;

    /**
     * Show the Conversation Challenge setup page: pick a language and start.
     */
    public function setup()
    {
        $languages = Auth::user()->languages()->orderBy('name')->get();
        $activeLanguageId = Auth::user()->currentSaveLanguage()?->id ?? optional($languages->first())->id;

        return view('challenge.setup', [
            'languages' => $languages,
            'activeLanguageId' => $activeLanguageId,
        ]);
    }

    /**
     * Start a challenge: seed the ephemeral session state and render the chat view
     * immediately (with a spinner). The opening line is fetched separately by the client
     * via opening() so the page appears instantly instead of blocking on the AI call.
     */
    public function start(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'language_id' => ['required', 'integer'],
            'scene' => ['nullable', 'string', 'max:200'],
            'feedback_focus' => ['nullable', 'string', 'max:200'],
            'mode' => ['nullable', 'in:text,voice,game'],
            'turn_taking' => ['nullable', 'in:vad,ptt'],
            'voice' => ['nullable', Rule::in(self::REALTIME_VOICES)],
            'speed' => ['nullable', 'numeric', 'min:0.25', 'max:1.5'],
            'barge_in' => ['nullable', 'boolean'],
        ]);

        $language = $user->languages()->where('languages.id', $data['language_id'])->first();
        if (! $language) {
            abort(403, 'Unauthorized action.');
        }

        // Voice mode: a live Realtime (WebRTC) chat driven entirely by the browser. Seed the
        // ephemeral state (no AI call here) and render the voice view; it fetches an
        // ephemeral key from voiceToken() and connects straight to OpenAI.
        if (($data['mode'] ?? 'text') === 'voice') {
            session(['chat_voice' => [
                'language_id' => $language->id,
                'level' => $user->levelForLanguage($language),
                'scene' => $data['scene'] ?? null,
                'feedback_focus' => $data['feedback_focus'] ?? null,
                'turn_taking' => $data['turn_taking'] ?? 'vad',
                'voice' => $data['voice'] ?? self::REALTIME_VOICE,
                'speed' => isset($data['speed']) ? (float) $data['speed'] : 1.0,
                'barge_in' => $request->boolean('barge_in'),
                'native_language' => optional($user->nativeLanguage)->name ?? $user->native_language,
            ]]);

            return view('challenge.voice', [
                'turnTaking' => $data['turn_taking'] ?? 'vad',
            ]);
        }

        // Game mode: the AI picks the scene and sets the learner a task each turn (no user
        // preferences). Seed the ephemeral state (no AI call here) and render the game view,
        // which fetches the opening via gameOpening().
        if (($data['mode'] ?? 'text') === 'game') {
            session(['chat_game' => [
                'messages' => [],
                'language_id' => $language->id,
                'level' => $user->levelForLanguage($language),
                'cache_key' => 'game-'.$user->id.'-'.Str::uuid()->toString(),
                'current_task' => null,
                'survived' => 0,
                'lives_left' => self::GAME_LIVES,
                'mistakes' => [],
                'scene_display' => null,
                'scene_id' => null,
            ]]);

            return view('challenge.game', [
                'maxTurns' => self::GAME_MAX_TURNS,
                'lives' => self::GAME_LIVES,
            ]);
        }

        // Stable per-chat key so every turn is routed to the same prompt cache.
        $cacheKey = 'chal-'.$user->id.'-'.Str::uuid()->toString();

        session(['chat_challenge' => [
            'messages' => [],
            'struggles' => [],
            'language_id' => $language->id,
            'level' => $user->levelForLanguage($language),
            'scene' => $data['scene'] ?? null,
            'feedback_focus' => $data['feedback_focus'] ?? null,
            'scene_display' => null,
            'cache_key' => $cacheKey,
        ]]);

        return view('challenge.chat');
    }

    /**
     * Generate the opening line (in-character message) + a short scene label, append it to
     * the session transcript, and return both. Called once by the client on page load.
     */
    public function opening(Request $request)
    {
        $chat = session('chat_challenge');
        if (! $chat) {
            return response()->json(['message' => 'No active conversation.'], 409);
        }

        // Already opened (e.g. a duplicate/refresh) — return the existing opener.
        if (! empty($chat['messages'])) {
            return response()->json([
                'scene' => $chat['scene_display'] ?? '',
                'reply' => $chat['messages'][0]['content'] ?? '',
            ]);
        }

        $opening = AI::startChallenge(
            $this->languageName($chat['language_id']),
            $chat['level'],
            $chat['scene'] ?? null,
            $chat['cache_key'] ?? null,
        );

        if (! is_array($opening) || ! isset($opening['reply'])) {
            return response()->json(['message' => 'Could not start the conversation. Please try again.'], 500);
        }

        $chat['messages'][] = ['role' => 'assistant', 'content' => $opening['reply']];
        $chat['scene_display'] = $opening['scene'] ?? '';
        session(['chat_challenge' => $chat]);

        return response()->json([
            'scene' => $opening['scene'] ?? '',
            'reply' => $opening['reply'],
        ]);
    }

    /**
     * Handle one learner turn: append their message, get the AI's in-character reply plus
     * a live correction of that message, accumulate any mistake for the end recap, and
     * return both to the client.
     */
    public function message(Request $request)
    {
        $data = $request->validate(['message' => ['required', 'string']]);

        $chat = session('chat_challenge');
        if (! $chat) {
            return response()->json(['message' => 'No active conversation.'], 409);
        }

        $user = Auth::user();
        $chat['messages'][] = ['role' => 'user', 'content' => $data['message']];

        $result = AI::challengeReply(
            $chat['messages'],
            $this->languageName($chat['language_id']),
            optional($user->nativeLanguage)->name ?? $user->native_language,
            $chat['level'],
            $chat['scene'] ?? null,
            $chat['feedback_focus'] ?? null,
            $chat['cache_key'] ?? null,
        );

        if (! is_array($result) || ! isset($result['reply'])) {
            // Drop the just-appended learner message so a retry doesn't double it.
            array_pop($chat['messages']);
            session(['chat_challenge' => $chat]);

            return response()->json(['message' => 'The conversation partner is unavailable right now. Please try again.'], 500);
        }

        $correction = $result['correction'] ?? ['has_error' => false, 'is_typo' => false, 'feedback' => ''];

        // Collect only genuine gaps for the end recap — skip typos/fast-typing slips.
        if (! empty($correction['has_error']) && empty($correction['is_typo']) && ! empty($correction['feedback'])) {
            $chat['struggles'][] = $correction['feedback'];
        }

        $chat['messages'][] = ['role' => 'assistant', 'content' => $result['reply']];
        session(['chat_challenge' => $chat]);

        return response()->json([
            'reply' => $result['reply'],
            'correction' => $correction,
        ]);
    }

    /**
     * End the challenge: turn the collected mistakes into recommendations, clear the
     * ephemeral session state, and return them.
     */
    public function recap(Request $request)
    {
        $chat = session('chat_challenge');
        if (! $chat) {
            return response()->json(['message' => 'No active conversation.'], 409);
        }

        $user = Auth::user();

        $recap = AI::challengeRecap(
            $chat['struggles'] ?? [],
            $this->languageName($chat['language_id']),
            optional($user->nativeLanguage)->name ?? $user->native_language,
            $chat['level'],
            $chat['feedback_focus'] ?? null,
        );
        $recap = is_array($recap) ? $recap : [];

        session()->forget('chat_challenge');

        return response()->json([
            'recommendations' => $recap['recommendations'] ?? [],
        ]);
    }

    /**
     * Game: generate the scene, the opening in-character line, and the first task. Called once
     * by the client on page load. Idempotent (a refresh returns the stored opening).
     */
    public function gameOpening(Request $request)
    {
        $chat = session('chat_game');
        if (! $chat) {
            return response()->json(['message' => 'No active game.'], 409);
        }

        // Already opened (duplicate/refresh) — return the stored opener + current task.
        if (! empty($chat['messages'])) {
            return response()->json([
                'scene' => $chat['scene_display'] ?? '',
                'reply' => $chat['messages'][0]['content'] ?? '',
                'suggestion' => $chat['current_task'] ?? '',
            ]);
        }

        $user = Auth::user();

        // Randomly draw one of the seeded situations and hand it to the AI.
        $scene = ChallengeScene::inRandomOrder()->first();
        $scenePrompt = $scene ? $scene->name.': '.$scene->description : null;

        $opening = AI::startChallengeGame(
            $this->languageName($chat['language_id']),
            optional($user->nativeLanguage)->name ?? $user->native_language,
            $chat['level'],
            $chat['cache_key'] ?? null,
            $scenePrompt,
        );

        if (! is_array($opening) || ! isset($opening['reply'], $opening['suggestion'])) {
            return response()->json(['message' => 'Could not start the game. Please try again.'], 500);
        }

        $chat['messages'][] = ['role' => 'assistant', 'content' => $opening['reply']];
        $chat['scene_display'] = $opening['scene'] ?? '';
        $chat['scene_id'] = $scene?->id;
        $chat['current_task'] = $opening['suggestion'];
        session(['chat_game' => $chat]);

        return response()->json([
            'scene' => $opening['scene'] ?? '',
            'reply' => $opening['reply'],
            'suggestion' => $opening['suggestion'],
        ]);
    }

    /**
     * Game: play one turn. The AI only reports what it found in the learner's message (whether
     * the task was accomplished + a list of individual mistakes); every game rule is applied
     * here: each mistake costs one life, a missed task costs one life, the game ends when the
     * lives run out (loss) or all 10 turns are cleared (win), and every mistake is collected in
     * the session so the whole list can be shown when the game ends. On end the result (turns
     * survived + whether all 10 were cleared) is stored and the session is cleared.
     */
    public function gameMessage(Request $request)
    {
        $data = $request->validate(['message' => ['required', 'string']]);

        $chat = session('chat_game');
        if (! $chat) {
            return response()->json(['message' => 'No active game.'], 409);
        }

        $user = Auth::user();
        $task = $chat['current_task'] ?? '';
        $chat['messages'][] = ['role' => 'user', 'content' => $data['message']];

        $result = AI::challengeGameReply(
            $chat['messages'],
            $task,
            $this->languageName($chat['language_id']),
            optional($user->nativeLanguage)->name ?? $user->native_language,
            $chat['level'],
            $chat['cache_key'] ?? null,
        );

        if (! is_array($result) || ! array_key_exists('task_completed', $result)) {
            // Drop the just-appended learner message so a retry doesn't double it.
            array_pop($chat['messages']);
            session(['chat_game' => $chat]);

            return response()->json(['message' => 'The game is unavailable right now. Please try again.'], 500);
        }

        $taskCompleted = (bool) ($result['task_completed'] ?? false);
        $turn = ($chat['survived'] ?? 0) + 1;

        // Everything below is the server's call — the AI only reported what it saw.
        $turnMistakes = $this->collectMistakes($result, $taskCompleted, $turn);

        $chat['mistakes'] = array_merge($chat['mistakes'] ?? [], $turnMistakes);
        $chat['lives_left'] = max(0, ($chat['lives_left'] ?? self::GAME_LIVES) - count($turnMistakes));

        $payload = [
            'task_completed' => $taskCompleted,
            'mistake_count' => count($turnMistakes),
            'lives_left' => $chat['lives_left'],
        ];

        // Out of lives — game over, with every mistake made along the way.
        if ($chat['lives_left'] <= 0) {
            $this->finishGame($chat, false);

            return response()->json(array_merge($payload, [
                'ended' => true,
                'completed' => false,
                'turns_survived' => $chat['survived'],
                'mistakes' => $chat['mistakes'],
            ]));
        }

        // Survived the turn: count it and continue in character.
        $chat['survived']++;
        $chat['messages'][] = ['role' => 'assistant', 'content' => $result['reply'] ?? ''];

        // Cleared all the turns — a win.
        if ($chat['survived'] >= self::GAME_MAX_TURNS) {
            $this->finishGame($chat, true);

            return response()->json(array_merge($payload, [
                'ended' => true,
                'completed' => true,
                'turns_survived' => $chat['survived'],
                'reply' => $result['reply'] ?? '',
                'mistakes' => $chat['mistakes'],
            ]));
        }

        // Hand out the next task.
        $chat['current_task'] = $result['suggestion'] ?? '';
        session(['chat_game' => $chat]);

        return response()->json(array_merge($payload, [
            'ended' => false,
            'turns_survived' => $chat['survived'],
            'reply' => $result['reply'] ?? '',
            'suggestion' => $chat['current_task'],
        ]));
    }

    /**
     * Turn one AI turn result into the list of mistakes that cost the learner a life: every
     * language mistake it reported (deduplicated, capped) plus one for a task it judged not
     * accomplished. Entries without an explanation are dropped — an unexplained mistake would
     * cost a life the learner could not learn from.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array{turn:int, type:string, quote:string, explanation:string}>
     */
    private function collectMistakes(array $result, bool $taskCompleted, int $turn): array
    {
        $mistakes = [];

        if (! $taskCompleted) {
            $mistakes[] = [
                'turn' => $turn,
                'type' => 'task',
                'quote' => '',
                'explanation' => trim((string) ($result['task_feedback'] ?? '')),
            ];
        }

        $seen = [];

        foreach (is_array($result['mistakes'] ?? null) ? $result['mistakes'] : [] as $mistake) {
            if (! is_array($mistake) || trim((string) ($mistake['explanation'] ?? '')) === '') {
                continue;
            }

            // The same mistake reported twice must not cost two lives.
            $fingerprint = mb_strtolower(trim((string) ($mistake['quote'] ?? '')).'|'.trim($mistake['explanation']));
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            $mistakes[] = [
                'turn' => $turn,
                'type' => 'language',
                'quote' => trim((string) ($mistake['quote'] ?? '')),
                'explanation' => trim($mistake['explanation']),
            ];

            if (count($mistakes) >= self::GAME_MAX_MISTAKES_PER_TURN) {
                break;
            }
        }

        return $mistakes;
    }

    /**
     * Persist a finished game (how many turns the learner survived + whether they cleared all
     * of them) and clear the ephemeral session state.
     *
     * @param  array<string, mixed>  $chat
     */
    private function finishGame(array $chat, bool $completed): void
    {
        ChallengeGameResult::create([
            'user_id' => Auth::id(),
            'language_id' => $chat['language_id'],
            'challenge_scene_id' => $chat['scene_id'] ?? null,
            'turns_survived' => $chat['survived'] ?? 0,
            'completed' => $completed,
        ]);

        session()->forget('chat_game');
    }

    /**
     * Mint a short-lived ephemeral client secret for a browser Realtime (WebRTC) voice
     * session. The real API key stays server-side; the browser only ever gets the ephemeral
     * value. The whole conversation + verbal-feedback behaviour is baked into the session
     * `instructions` here, so there is no per-turn server call once connected.
     */
    public function voiceToken(Request $request)
    {
        $chat = session('chat_voice');
        if (! $chat) {
            return response()->json(['message' => 'No active conversation.'], 409);
        }

        $instructions = AI::realtimeInstructions(
            $this->languageName($chat['language_id']),
            $chat['native_language'] ?? '',
            $chat['level'] ?? null,
            $chat['scene'] ?? null,
            $chat['feedback_focus'] ?? null,
        );

        // Push-to-talk disables VAD (the client commits each turn). Auto uses semantic VAD
        // with low eagerness so the model waits for the learner to actually finish — learners
        // pause a lot mid-sentence — instead of jumping in on the first silence.
        $turnDetection = ($chat['turn_taking'] ?? 'vad') === 'ptt'
            ? null
            : [
                'type' => 'semantic_vad',
                'eagerness' => 'low',
                'interrupt_response' => (bool) ($chat['barge_in'] ?? true),
            ];

        // Realtime session config. Reference for what each knob does and what we deliberately
        // leave on OpenAI's default (docs: https://developers.openai.com/api/docs/guides/realtime-conversations
        // and .../realtime-vad). "Set" = we pass it; "Default" = we intentionally omit it.
        //
        // WHAT WE SET:
        //   model              gpt-realtime-2.1-mini — the speech-to-speech model. Bigger sibling
        //                      gpt-realtime-2.1 is higher quality but pricier (per-minute billing).
        //   instructions       the system prompt: persona, target language, scene, feedback focus,
        //                      CEFR level, "correct out loud then continue" (see AI::realtimeInstructions).
        //                      Can be rewritten mid-session via a session.update event (e.g. adaptive difficulty).
        //   audio.input.transcription   gpt-4o-transcribe — transcribes the LEARNER's speech so we can
        //                      show it on screen. Alternative: whisper-1. (Optional 'language' hint not set.)
        //   audio.input.turn_detection  how a turn ends. We use semantic_vad + eagerness:low for Auto
        //                      (waits for semantic completeness, good for learners who pause), or null
        //                      for push-to-talk (client commits each turn). interrupt_response = barge-in.
        //   audio.output.voice starts once spoken it CANNOT change for the session. User-selectable.
        //   audio.output.speed 0.25–1.5 speaking-rate multiplier (1.0 = normal). User-selectable.
        //
        // WHAT WE LEAVE ON DEFAULT (available if we ever want them):
        //   audio.output.format / audio.input.format   audio encoding — handled by the WebRTC transport, no need to set.
        //   turn_detection.threshold / silence_duration_ms / prefix_padding_ms   fine VAD tuning (server_vad only;
        //                      we use semantic_vad's eagerness instead).
        //   turn_detection.create_response   defaults true (auto-replies after a turn); we rely on the default.
        //   audio.input.noise_reduction      near_field / far_field mic cleanup for noisy rooms.
        //   max_output_tokens   caps reply length; default is unlimited-ish.
        //   tools / tool_choice  function calling — not used here.
        //   output_modalities    text+audio by default — we want both, so left alone.
        //   temperature          reasoning-style models typically reject it; left unset.
        $secret = AI::mintRealtimeClientSecret([
            'type' => 'realtime',
            'model' => self::REALTIME_MODEL,
            'instructions' => $instructions,
            'audio' => [
                'input' => [
                    'transcription' => ['model' => 'gpt-4o-transcribe'],
                    'turn_detection' => $turnDetection,
                ],
                'output' => [
                    'voice' => in_array($chat['voice'] ?? null, self::REALTIME_VOICES, true) ? $chat['voice'] : self::REALTIME_VOICE,
                    'speed' => (float) ($chat['speed'] ?? 1.0),
                ],
            ],
        ]);

        if (! is_array($secret) || ! isset($secret['value'])) {
            return response()->json(['message' => 'Could not start the voice session. Please try again.'], 500);
        }

        return response()->json([
            'value' => $secret['value'],
            'expires_at' => $secret['expires_at'] ?? null,
            'model' => self::REALTIME_MODEL,
        ]);
    }

    /**
     * End the voice challenge: turn the collected spoken transcript into written feedback
     * (strengths, grammar corrections, better vocabulary), clear the session, and return it.
     */
    public function voiceRecap(Request $request)
    {
        $chat = session('chat_voice');
        if (! $chat) {
            return response()->json(['message' => 'No active conversation.'], 409);
        }

        $data = $request->validate([
            'transcript' => ['nullable', 'array', 'max:200'],
            'transcript.*.role' => ['required', 'string', 'in:user,assistant'],
            'transcript.*.text' => ['required', 'string', 'max:2000'],
        ]);

        $user = Auth::user();

        $recap = AI::voiceChallengeRecap(
            $data['transcript'] ?? [],
            $this->languageName($chat['language_id']),
            optional($user->nativeLanguage)->name ?? $user->native_language,
            $chat['level'] ?? null,
            $chat['feedback_focus'] ?? null,
        );
        $recap = is_array($recap) ? $recap : [];

        session()->forget('chat_voice');

        return response()->json([
            'did_well' => $recap['did_well'] ?? [],
            'corrections' => $recap['corrections'] ?? [],
            'vocabulary' => $recap['vocabulary'] ?? [],
        ]);
    }

    private function languageName(?int $languageId): string
    {
        return Language::find($languageId)?->name ?? '';
    }
}
