<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeProposalJob;
use App\Models\Card;
use App\Models\IgnoredWord;
use App\Models\Proposal;
use App\Models\ProposalBaseWord;
use App\Models\ProposalFixedExpression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CAPTURE: the capture endpoint and staging.
 *
 * Capture writes a proposal and returns immediately — no card, no language picker, no
 * waiting on the AI. Everything else here is the learner acting on what CALL 1 came back
 * with: striking a candidate word as ignored or a fixed expression, correcting a wrong language detection, editing
 * the Context (or picking a sense, which is the same thing), picking the language of a Term
 * at home in several, switching on an existing card for it to replace, and finally approving (which writes the card and runs CALL 2) or discarding.
 *
 * See docs/cards.md "Staging" and USERFLOW.md.
 */
class ProposalController extends Controller
{
    /**
     * Capture a term. Shared by the web form and the browser extension
     * (POST /api/addWordAPI), which is why it always answers JSON.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            // Long enough to accept a pasted sentence, which is kept as typed.
            'capturedWord' => ['required', 'string', 'min:2', 'max:120'],
            'context' => ['nullable', 'string', 'min:2', 'max:'.Proposal::MAX_CONTEXT],
        ]);

        $user = Auth::user();

        if ($user->languages()->doesntExist()) {
            return response()->json(['message' => 'Please set up a target language in your settings first.'], 422);
        }

        $proposal = $user->proposals()->create([
            'raw_input' => trim($data['capturedWord']),
            // An untouched context input submits "", which is not null.
            'context' => $request->filled('context') ? trim($data['context']) : null,
            'source' => $request->routeIs('captureApi') ? 'extension' : 'web',
        ]);

        AnalyzeProposalJob::dispatch($proposal);

        return response()->json([
            'proposal_id' => $proposal->id,
            'staged_count' => $user->proposals()->count(),
            'message' => '"'.$proposal->raw_input.'" is in staging.',
        ]);
    }

    /**
     * The staging feed — one list, in the order the terms were captured, across every language.
     */
    public function index()
    {
        return view('staging.index', $this->listData());
    }

    /**
     * The same list as JSON, for the fast path: a freshly captured proposal shows as a
     * skeleton row and the page polls this until nothing is pending any more.
     */
    public function list()
    {
        $data = $this->listData();

        return response()->json([
            'rows' => view('staging._proposals', $data)->render(),
            'count' => $data['proposals']->count(),
            'pending' => $data['proposals']->contains(fn (Proposal $p) => $p->isAwaitingAnalysis()),
        ]);
    }

    /**
     * Strike a new candidate word as ignored, or tap an ignored one to un-ignore it. An ignored word
     * is remembered across captures (on lemma + part of speech, so every inflected form is
     * covered) and never becomes a base word. It never rewrites the Term — the card still
     * teaches the whole phrase, just linked to fewer words.
     */
    public function ignore(Request $request, Proposal $proposal, ProposalBaseWord $word)
    {
        $this->authorize('update', $proposal);
        abort_unless($word->proposal_id === $proposal->id, 404);

        $attributes = [
            'user_id' => $proposal->user_id,
            'language_id' => $proposal->language_id,
            'part_of_speech' => $word->part_of_speech,
        ];

        $existing = IgnoredWord::where($attributes)->whereLemma($word->lemma);

        $request->boolean('ignored')
            ? ($existing->exists() || IgnoredWord::create($attributes + ['lemma' => $word->lemma]))
            : $existing->delete();

        return response()->noContent();
    }

    /**
     * Strike (or un-strike) one fixed expression so it isn't saved. Unlike a word this is
     * per proposal: a struck fixed expression is never remembered as ignored.
     */
    public function strikeExpression(Request $request, Proposal $proposal, ProposalFixedExpression $expression)
    {
        $this->authorize('update', $proposal);
        abort_unless($expression->proposal_id === $proposal->id, 404);

        $expression->update(['struck' => $request->boolean('struck')]);

        return response()->noContent();
    }

    /**
     * Correct a wrong language detection, or answer the language picker. The candidate
     * words were extracted (and translated, and tagged) for the language CALL 1 guessed, so
     * they can't simply be re-pointed: the proposal goes back through CALL 1, this time
     * pinned to the language the learner chose rather than detecting one.
     */
    public function language(Request $request, Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        $data = $request->validate(['language_id' => ['required', 'integer']]);

        abort_unless(Auth::user()->languages()->whereKey($data['language_id'])->exists(), 422);

        $proposal->reanalyze(['language_id' => $data['language_id']]);

        return response()->json(['reanalyzing' => true]);
    }

    /**
     * Add, edit or clear a proposal's Context. The Context decides which sense the Term is
     * read in, so the proposal goes back through CALL 1 just as for a language correction.
     */
    public function context(Request $request, Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        $data = $request->validate(['context' => ['nullable', 'string', 'min:2', 'max:'.Proposal::MAX_CONTEXT]]);

        $proposal->reanalyze(['context' => $request->filled('context') ? trim($data['context']) : null]);

        return response()->json(['reanalyzing' => true]);
    }

    /**
     * Answer the clarifying question CALL 1 asked: the chosen option's line (or the
     * learner's own words) is added to the Context and CALL 1 runs again, which either asks
     * the next question or extracts the words in the meaning now pinned down.
     */
    public function clarify(Request $request, Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        $data = $request->validate(['answer' => ['required', 'string', 'min:2', 'max:200']]);

        abort_unless($proposal->clarification, 422);

        $proposal->answerClarification($data['answer']);

        return response()->json(['reanalyzing' => true]);
    }

    /**
     * Override staging's verdict on which saved meaning of an already-saved word the Term
     * uses: one of its meanings (`sense_id`) or a new one (`translation`). Nothing is
     * re-run and nothing is written to the vocabulary base until approval.
     */
    public function sense(Request $request, Proposal $proposal, ProposalBaseWord $word)
    {
        $this->authorize('update', $proposal);
        abort_unless($word->proposal_id === $proposal->id, 404);

        $data = $request->validate([
            'sense_id' => ['nullable', 'integer'],
            'translation' => ['nullable', 'string', 'max:255'],
        ]);

        $baseWord = Proposal::presenceIndex(collect([$proposal]))->get($proposal->presenceKeyFor($word));

        abort_unless($baseWord, 422);

        if (filled($data['translation'] ?? null)) {
            $word->update(['base_word_sense_id' => null, 'new_sense_translation' => trim($data['translation'])]);
        } else {
            abort_unless($baseWord->senses->contains('id', $data['sense_id'] ?? 0), 422);

            $word->update(['base_word_sense_id' => $data['sense_id'], 'new_sense_translation' => null]);
        }

        return response()->noContent();
    }

    /**
     * Try CALL 1 again on a proposal whose analysis failed or stalled.
     */
    public function retry(Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        $proposal->reanalyze();

        return response()->json(['reanalyzing' => true]);
    }

    /**
     * Regenerate the proposal: CALL 1 again on the raw input and the current Context, as for
     * a new capture. The row shows as pending and is replaced in place by the new answer.
     */
    public function regenerate(Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        $proposal->regenerate();

        return response()->json(['reanalyzing' => true]);
    }

    /**
     * Switch one of the learner's existing cards on (or off) to be replaced by this proposal
     * when it is approved. Nothing happens to it until then; approval checks again that the
     * card is still contained (see Proposal::replaceableCards()).
     */
    public function replace(Request $request, Proposal $proposal, Card $card)
    {
        $this->authorize('update', $proposal);
        $this->authorize('delete', $card);
        abort_unless($card->language_id === $proposal->language_id, 422);

        $proposal->markForReplace($card, $request->boolean('replace'));

        return response()->noContent();
    }

    /**
     * Approve: write the card and its base-word links, and pay for CALL 2 at last.
     */
    public function approve(Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        if ($duplicate = $proposal->duplicateCards()->first()) {
            return response()->json([
                'message' => 'You already have this Term: "'.$duplicate->term.'".',
                'duplicate_card_id' => $duplicate->id,
            ], 409);
        }

        // Staging shows a covered proposal as refused, with no Approve; this is the backstop.
        $batch = collect([$proposal]);

        if ($covering = $proposal->coveringCard(Proposal::presenceIndex($batch), Proposal::ignoredIndex($batch), Proposal::expressionPresenceIndex($batch))) {
            return response()->json([
                'message' => 'There is already a card with exactly these words: "'.$covering->term.'".',
                'covering_card_id' => $covering->id,
            ], 409);
        }

        if (! $proposal->isApprovable()) {
            return response()->json(['message' => 'This proposal is not ready to be approved.'], 422);
        }

        $card = $proposal->approve();

        if (is_null($card)) {
            return response()->json(['message' => 'There was an error while creating the card.'], 500);
        }

        // Staging stays open: the row collapses to a link to the new card.
        return response()->json([
            'url' => '/cards/'.$card->id,
            'term' => $card->term,
            'staged_count' => Auth::user()->proposals()->count(),
        ]);
    }

    /**
     * Discard: the proposal is gone. Nothing is kept — no discard history, no draft state.
     * Staging's "Capture again" is a fresh capture of the same input, not a restore.
     */
    public function destroy(Proposal $proposal)
    {
        $this->authorize('delete', $proposal);

        $proposal->delete();

        return response()->json(['staged_count' => Auth::user()->proposals()->count()]);
    }

    /**
     * Shared view data for the staging list and its JSON twin.
     */
    private function listData(): array
    {
        $user = Auth::user();
        $proposals = $user->proposals()->with(['baseWords', 'fixedExpressions', 'language'])->oldest('id')->get();

        return [
            'proposals' => $proposals,
            'targetLanguages' => $user->languages()->orderBy('name')->get(),
            // Resolved for the whole list in one query per store rather than per chip — see
            // Proposal::presenceIndex() for why that matters on a polled endpoint. Related
            // cards are read off alreadyInBase, so they add no query of their own.
            'alreadyInBase' => Proposal::presenceIndex($proposals),
            'ignoredWords' => Proposal::ignoredIndex($proposals),
            'expressionsInBase' => Proposal::expressionPresenceIndex($proposals),
        ];
    }
}
