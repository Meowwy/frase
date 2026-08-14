<?php

namespace App\Http\Controllers;

use App\Models\AI;
use App\Models\Language;
use App\Models\Learning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    /**
     * Handle one learner turn: append their message, get the AI's in-character reply
     * plus which still-remaining target words they used, update the ephemeral session
     * state, and tell the client whether the practice has ended.
     */
    public function message(Request $request)
    {
        $data = $request->validate(['message' => ['required', 'string']]);

        $chat = session('chat_practice');
        if (! $chat) {
            return response()->json(['message' => 'No active conversation.'], 409);
        }

        $chat['messages'][] = ['role' => 'user', 'content' => $data['message']];

        // Cast on read: session state survives across requests, so an id that came back
        // as a string once would break the strict comparisons below and let a cleared
        // word reappear in the remaining set.
        $usedIds = array_map('intval', $chat['used_ids']);
        $remaining = array_values(array_filter(
            $chat['target_words'],
            fn ($w) => ! in_array((int) $w['id'], $usedIds, true)
        ));

        // Only the words still left are sent; the model never sees the cleared ones.
        $result = AI::conversationReply(
            $chat['messages'],
            $remaining,
            $this->languageName($chat['language_id']),
            $chat['level'],
            $chat['cache_key'] ?? null,
        );

        if (! is_array($result) || ! isset($result['reply'])) {
            return response()->json(['message' => 'The conversation partner is unavailable right now. Please try again.'], 500);
        }

        // The words the learner just used — restricted to the still-remaining set so a
        // stray id can't corrupt progress. used_ids only ever grows, which is what keeps
        // the "words left" counter monotonic.
        $remainingIds = array_map('intval', array_column($remaining, 'id'));
        $usedNew = array_values(array_intersect(
            array_map('intval', $result['used_word_ids'] ?? []),
            $remainingIds
        ));

        $chat['used_ids'] = array_values(array_unique(array_merge($usedIds, $usedNew)));
        // Stall guard: end after 4 consecutive learner messages that used no new word.
        $chat['stale_count'] = empty($usedNew) ? $chat['stale_count'] + 1 : 0;
        $chat['messages'][] = ['role' => 'assistant', 'content' => $result['reply']];

        $remainingCount = count($chat['target_words']) - count($chat['used_ids']);
        $ended = $remainingCount === 0 || $chat['stale_count'] >= 4;

        session(['chat_practice' => $chat]);

        return response()->json([
            'reply' => $result['reply'],
            'used_new' => $usedNew,
            'remaining_count' => $remainingCount,
            'ended' => $ended,
        ]);
    }

    /**
     * End the practice: generate the recap, apply spaced-repetition credit for every
     * word the learner used, and clear the ephemeral session state.
     */
    public function recap(Request $request)
    {
        $chat = session('chat_practice');
        if (! $chat) {
            return response()->json(['message' => 'No active conversation.'], 409);
        }

        $user = Auth::user();
        $usedIds = array_map('intval', $chat['used_ids']);

        $recap = AI::conversationRecap(
            $chat['messages'],
            $this->languageName($chat['language_id']),
            optional($user->nativeLanguage)->name ?? $user->native_language,
            $chat['level'],
        );
        $recap = is_array($recap) ? $recap : [];

        // Server is the only authority on got/not-got; the recap only shows mark + term.
        $words = array_map(fn ($w) => [
            'id' => (int) $w['id'],
            'term' => $w['term'],
            'got' => in_array((int) $w['id'], $usedIds, true),
        ], $chat['target_words']);

        // Spaced-repetition: each used word counts as one correct review (mirrors
        // AjaxController@saveLearning for result = 1). Unused words are left untouched.
        foreach ($user->cards()->whereIn('id', $usedIds)->get() as $card) {
            $card->next_study_at = Learning::getNextStudyDay($card->level, 1);
            $card->level++;
            $card->last_studied = now();
            $card->save();
        }

        session()->forget('chat_practice');

        return response()->json([
            'words' => $words,
            'corrections' => $recap['corrections'] ?? [],
        ]);
    }

    private function languageName(?int $languageId): string
    {
        return Language::find($languageId)?->name ?? '';
    }
}
